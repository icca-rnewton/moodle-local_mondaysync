<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * CRUD helper for connected boards, plus orphan-warning detection for the Connected Boards list.
 *
 * @package    local_mondaysync
 * @copyright  2026 Inns of Court College of Advocacy (Part of COIC)
 * @license    http://www.gnu.org/licenses/GPL-3.0 GNU GPL v3 or later
 */

namespace local_mondaysync;

defined('MOODLE_INTERNAL') || die();

/**
 * Simple CRUD wrapper around the local_mondaysync_board table.
 */
class board_repository {

    /**
     * @return array List of board records, ordered for display.
     */
    public static function get_all(): array {
        global $DB;
        return $DB->get_records('local_mondaysync_board', null, 'sortorder ASC, name ASC');
    }

    /**
     * @return array Only enabled boards - what the sync task should process.
     */
    public static function get_enabled(): array {
        global $DB;
        return $DB->get_records('local_mondaysync_board', ['enabled' => 1], 'sortorder ASC, name ASC');
    }

    public static function get(int $id): \stdClass {
        global $DB;
        return $DB->get_record('local_mondaysync_board', ['id' => $id], '*', MUST_EXIST);
    }

    /**
     * Insert or update a board record.
     *
     * @param \stdClass $record Must have ->name and ->boardid; ->id present means update.
     * @return int The board's id.
     */
    public static function save(\stdClass $record): int {
        global $DB;

        $record->timemodified = time();

        if (!empty($record->id)) {
            $DB->update_record('local_mondaysync_board', $record);
            return $record->id;
        }

        $record->timecreated = time();
        if (!isset($record->enabled)) {
            $record->enabled = 1;
        }
        if (!isset($record->sortorder)) {
            $record->sortorder = 0;
        }
        return (int) $DB->insert_record('local_mondaysync_board', $record);
    }

    public static function delete(int $id): void {
        global $DB;
        $DB->delete_records('local_mondaysync_board', ['id' => $id]);
        // Leave cache/log history in place - it's harmless and useful for audit,
        // and monday_itemid values are globally unique so there's no collision
        // risk if a new board is added later.
    }

    public static function set_enabled(int $id, bool $enabled): void {
        global $DB;
        $DB->set_field('local_mondaysync_board', 'enabled', $enabled ? 1 : 0, ['id' => $id]);
    }

    /**
     * Check whether a board currently has any orphaned mappings - a saved
     * mapping whose Moodle field or Monday.com column has since been
     * deleted (or its matching column itself). Used for the Connected
     * Boards list's warning indicator.
     *
     * The Moodle-side check is cheap (local DB only). The Monday-side
     * check needs a live API call, so this is wrapped defensively - if
     * Monday can't be reached right now, this just returns null (no
     * warning shown this time) rather than breaking the boards list page.
     *
     * @return string|null A short warning message, or null if nothing's orphaned (or it couldn't be checked).
     */
    public static function get_orphan_warning(\stdClass $board): ?string {
        if (empty($board->matchingcolumnid) || empty($board->fieldmappings)) {
            return null;
        }

        $rawmappings = mapping_util::parse_raw((string)$board->fieldmappings);
        if (empty($rawmappings)) {
            return null;
        }

        $customfields = mapping_util::get_custom_fields();
        $moodleorphans = 0;
        foreach ($rawmappings as $mapping) {
            if (!mapping_util::is_valid_field($mapping['type'], $mapping['field'], $customfields)) {
                $moodleorphans++;
            }
        }

        $mondayorphans = 0;
        $matchingcolumnmissing = false;
        $creationproblem = null;

        $token = trim((string) get_config('local_mondaysync', 'apitoken'));
        $apiversion = trim((string) get_config('local_mondaysync', 'apiversion'));

        if ($token !== '') {
            try {
                $client = new monday_client($token, $apiversion);
                $livecolumns = $client->get_board_columns($board->boardid);
                $liveids = array_column($livecolumns, 'id');

                foreach (array_keys($rawmappings) as $columnid) {
                    if (!in_array($columnid, $liveids, true)) {
                        $mondayorphans++;
                    }
                }
                if (!in_array($board->matchingcolumnid, $liveids, true)) {
                    $matchingcolumnmissing = true;
                }

                if (!empty($board->createusers)) {
                    $requiredcreatecolumns = [
                        $board->createtriggercolumnid ?? '',
                        $board->createfirstnamecolumnid ?? '',
                        $board->createlastnamecolumnid ?? '',
                        $board->createemailcolumnid ?? '',
                        $board->createusernamecolumnid ?? '',
                    ];
                    $blank = in_array('', array_map('trim', $requiredcreatecolumns), true)
                        || trim((string)($board->createdefaultauth ?? '')) === '';

                    if ($blank) {
                        $creationproblem = get_string('warningcreationnotconfigured', 'local_mondaysync');
                    } else {
                        $missingcolumn = false;
                        foreach ($requiredcreatecolumns as $columnid) {
                            if (!in_array(trim((string)$columnid), $liveids, true)) {
                                $missingcolumn = true;
                                break;
                            }
                        }
                        if ($missingcolumn) {
                            $creationproblem = get_string('warningcreationcolumnmissing', 'local_mondaysync');
                        }
                    }
                }
            } catch (\Throwable $e) {
                // Couldn't check live right now - fall through and report
                // only what the (still valid) Moodle-side check found.
            }
        }

        if ($matchingcolumnmissing) {
            return get_string('warningmatchingcolumnmissing', 'local_mondaysync');
        }
        if ($creationproblem !== null) {
            return $creationproblem;
        }
        if ($moodleorphans > 0 || $mondayorphans > 0) {
            return get_string('warningorphanedmappings', 'local_mondaysync', $moodleorphans + $mondayorphans);
        }

        return null;
    }

    /**
     * Accepts either a bare board ID ("18423283636") or a full Monday.com
     * board URL ("https://coic-company.monday.com/boards/18423283636") and
     * splits out the board ID and (if present) the account subdomain.
     *
     * @return array ['boardid' => string, 'subdomain' => string|null]
     */
    public static function parse_board_input(string $input): array {
        $input = trim($input);

        if (preg_match('#https?://([a-z0-9\-]+)\.monday\.com/boards/(\d+)#i', $input, $matches)) {
            return ['boardid' => $matches[2], 'subdomain' => $matches[1]];
        }

        return ['boardid' => $input, 'subdomain' => null];
    }

    /**
     * Build a direct "go to board" URL, if the account subdomain is known.
     */
    public static function board_url(string $boardid): ?string {
        $subdomain = trim((string) get_config('local_mondaysync', 'accountsubdomain'));
        if ($subdomain === '' || $boardid === '') {
            return null;
        }
        return 'https://' . $subdomain . '.monday.com/boards/' . $boardid;
    }

    /**
     * @param string $boardid
     * @param int $excludeid Board record id to exclude (i.e. itself, when editing).
     */
    public static function boardid_in_use(string $boardid, int $excludeid = 0): bool {
        global $DB;

        $params = ['boardid' => $boardid];
        $sql = "boardid = :boardid";

        if ($excludeid > 0) {
            $sql .= " AND id <> :excludeid";
            $params['excludeid'] = $excludeid;
        }

        return $DB->record_exists_select('local_mondaysync_board', $sql, $params);
    }
}