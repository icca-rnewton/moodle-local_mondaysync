<?php
namespace local_mondaysync;

defined('MOODLE_INTERNAL') || die();

/**
 * Thin wrapper around the Monday.com GraphQL API (https://api.monday.com/v2).
 */
class monday_client {

    /** @var string */
    const API_URL = 'https://api.monday.com/v2';

    /** @var string */
    protected $token;

    /** @var string */
    protected $apiversion;

    /**
     * @param string $token Monday.com API token.
     * @param string $apiversion API version string, e.g. '2026-01'.
     */
    public function __construct(string $token, string $apiversion) {
        $this->token = $token;
        $this->apiversion = $apiversion;
    }

    /**
     * Run a raw GraphQL request against the Monday.com API.
     *
     * @param string $query GraphQL query/mutation string.
     * @param array $variables GraphQL variables.
     * @return array Decoded 'data' portion of the response.
     * @throws \moodle_exception on transport or GraphQL errors.
     */
    public function graphql_request(string $query, array $variables = []): array {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        $curl = new \curl();
        $curl->setHeader([
            'Authorization: ' . $this->token,
            'Content-Type: application/json',
            'API-Version: ' . $this->apiversion,
        ]);
        $curl->setopt([
            'CURLOPT_CONNECTTIMEOUT' => 10,
            'CURLOPT_TIMEOUT' => 30,
        ]);

        $payload = json_encode([
            'query' => $query,
            'variables' => $variables,
        ]);

        $response = $curl->post(self::API_URL, $payload);
        $info = $curl->get_info();

        if (empty($info['http_code']) || (int)$info['http_code'] !== 200) {
            throw new \moodle_exception('errormondayhttp', 'local_mondaysync', '', $info['http_code'] ?? 'unknown');
        }

        $decoded = json_decode($response, true);

        if ($decoded === null) {
            throw new \moodle_exception('errormondayresponse', 'local_mondaysync', '', $response);
        }

        if (!empty($decoded['errors'])) {
            throw new \moodle_exception('errormondaygraphql', 'local_mondaysync', '', json_encode($decoded['errors']));
        }

        return $decoded['data'] ?? [];
    }

    /**
     * Fetch every item on a board, with all of its column values.
     * Pages through the board using cursor-based pagination (500 items per page).
     *
     * @param string $boardid Monday.com board ID.
     * @return array List of ['id' => string, 'columns' => [columnid => ['text' => ..., 'value' => ...]]]
     */
    public function get_all_board_items(string $boardid): array {
        $items = [];

        $query = 'query($boardid: [ID!]) {
            boards(ids: $boardid) {
                items_page(limit: 500) {
                    cursor
                    items {
                        id
                        name
                        column_values {
                            id
                            text
                            value
                        }
                    }
                }
            }
        }';

        $data = $this->graphql_request($query, ['boardid' => [$boardid]]);
        $page = $data['boards'][0]['items_page'] ?? null;

        if ($page === null) {
            return $items;
        }

        $items = array_merge($items, $this->normalise_items($page['items']));
        $cursor = $page['cursor'] ?? null;

        $nextquery = 'query($cursor: String!) {
            next_items_page(limit: 500, cursor: $cursor) {
                cursor
                items {
                    id
                    name
                    column_values {
                        id
                        text
                        value
                    }
                }
            }
        }';

        // Guard against runaway pagination if the API ever misbehaves.
        $maxpages = 100;
        while ($cursor && $maxpages-- > 0) {
            $data = $this->graphql_request($nextquery, ['cursor' => $cursor]);
            $nextpage = $data['next_items_page'] ?? null;
            if ($nextpage === null) {
                break;
            }
            $items = array_merge($items, $this->normalise_items($nextpage['items']));
            $cursor = $nextpage['cursor'] ?? null;
        }

        return $items;
    }

    /**
     * Fetch a board's columns (id, title, type, settings) - used by the
     * mapping wizard to populate its dropdowns, and to resolve status
     * column label indices for the user-creation trigger column.
     *
     * @param string $boardid Monday.com board ID.
     * @return array List of ['id' => string, 'title' => string, 'type' => string, 'settings_str' => string]
     */
    public function get_board_columns(string $boardid): array {
        $query = 'query($boardid: [ID!]) {
            boards(ids: $boardid) {
                columns {
                    id
                    title
                    type
                    settings_str
                }
            }
        }';

        $data = $this->graphql_request($query, ['boardid' => [$boardid]]);
        $boards = $data['boards'] ?? [];

        if (empty($boards)) {
            throw new \moodle_exception('errornoboardaccess', 'local_mondaysync', '', $boardid);
        }

        return $boards[0]['columns'] ?? [];
    }

    /**
     * Set a column's value using its plain-text/display representation.
     * Per Monday's own docs this covers text and status columns (setting a
     * status by its label text is an officially documented example) - it's
     * NOT supported for every column type (e.g. doc columns explicitly
     * reject it), so a GraphQL error here is a real possibility for less
     * common column types and is left to the caller to catch and log,
     * rather than silently swallowed here.
     *
     * @param string $boardid
     * @param string $itemid
     * @param string $columnid
     * @param string $value Empty string clears the column.
     */
    public function set_simple_column_value(string $boardid, string $itemid, string $columnid, string $value): void {
        $query = 'mutation($boardid: ID!, $itemid: ID!, $columnid: String!, $value: String!) {
            change_simple_column_value(board_id: $boardid, item_id: $itemid, column_id: $columnid, value: $value) {
                id
            }
        }';

        $this->graphql_request($query, [
            'boardid' => $boardid,
            'itemid' => $itemid,
            'columnid' => $columnid,
            'value' => $value,
        ]);
    }

    /**
     * Set a column's value using its structured JSON representation - needed
     * for column types (like date) that don't reliably accept a plain string
     * via change_simple_column_value.
     *
     * @param string $boardid
     * @param string $itemid
     * @param string $columnid
     * @param array $valuedata Will be JSON-encoded, e.g. ['date' => '2026-07-23'].
     *  An empty array clears the column.
     */
    public function set_column_value_json(string $boardid, string $itemid, string $columnid, array $valuedata): void {
        $query = 'mutation($boardid: ID!, $itemid: ID!, $columnid: String!, $value: JSON!) {
            change_column_value(board_id: $boardid, item_id: $itemid, column_id: $columnid, value: $value) {
                id
            }
        }';

        $this->graphql_request($query, [
            'boardid' => $boardid,
            'itemid' => $itemid,
            'columnid' => $columnid,
            'value' => json_encode($valuedata),
        ]);
    }

    /**
     * Reshape raw items_page items into a simpler [id, columns] structure.
     */
    protected function normalise_items(array $rawitems): array {
        $out = [];
        foreach ($rawitems as $item) {
            $columns = [
                // The item's title isn't part of column_values in Monday's API -
                // it's a separate top-level field - so we fold it in here under
                // the same 'name' id the columns list reports, letting it be
                // used as a matching/mapping column like any other.
                'name' => [
                    'text' => $item['name'] ?? '',
                    'value' => null,
                ],
            ];
            foreach (($item['column_values'] ?? []) as $col) {
                $columns[$col['id']] = [
                    'text' => $col['text'],
                    'value' => $col['value'],
                ];
            }
            $out[] = [
                'id' => $item['id'],
                'columns' => $columns,
            ];
        }
        return $out;
    }
}