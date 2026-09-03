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
 * Orchestrates a sync run across every connected Monday.com board.
 *
 * @package    local_mondaysync
 * @copyright  2026 Inns of Court College of Advocacy (Part of COIC)
 * @license    http://www.gnu.org/licenses/GPL-3.0 GNU GPL v3 or later
 */

namespace local_mondaysync;

defined('MOODLE_INTERNAL') || die();

/**
 * Orchestrates a sync run: pulls every connected Monday.com board, matches
 * rows to Moodle users by ID Number (creating a new account first if the
 * board's configured for it and none exists yet), and applies any changed
 * mapped columns in whichever direction(s) that mapping is configured for.
 *
 * Direction handling, per mapping:
 * - toMoodle (default): Monday.com is the source of truth. Every poll, the
 *   live Moodle value is compared directly against the live Monday value
 *   and corrected if they differ - including if the difference is because
 *   someone edited the Moodle side by hand, not because Monday changed.
 * - toMonday: the same, in reverse - Moodle is the source of truth, Monday
 *   is corrected to match on every poll regardless of why it drifted.
 * - both: whichever side changed *since the last sync* gets pushed to the
 *   other (this is the one direction that genuinely needs to track
 *   history, via the sync cache, rather than just compare current values).
 *   If BOTH sides changed to different values since the last sync (a
 *   genuine conflict, not just one side catching up), Monday.com's value
 *   wins - deliberately, because there's no reliable way to tell which side
 *   was edited more recently (Moodle's custom profile fields have no
 *   per-field timestamp at all, and even the coarser account-level
 *   timestamp is contaminated by unrelated profile edits), so "most
 *   recent edit wins" isn't something this can honestly implement.
 *
 * User creation, per board (optional): a Status column on the board acts
 * as a trigger - "Create user" makes a new account (using dedicated
 * firstname/lastname/email/username column mappings and a default auth),
 * flipping to "User created" on success or "Error" on failure. Once an
 * account exists, that status is never touched again except to correct it
 * back to "User created" if it's ever found reading anything else -
 * deliberately, there's no "delete" state here: a human is meant to stay
 * the final decision-maker for anything irreversible, so this only ever
 * creates, never destroys.
 */
class sync_manager {

    /**
     * Run one full sync pass across all enabled boards. Safe to call
     * repeatedly (e.g. from the scheduled task).
     */
    public function run(): void {
        $token = trim((string)get_config('local_mondaysync', 'apitoken'));
        $apiversion = trim((string)get_config('local_mondaysync', 'apiversion'));

        if (empty($token)) {
            mtrace(get_string('errornotconfigured', 'local_mondaysync'));
            return;
        }

        $boards = board_repository::get_enabled();

        if (empty($boards)) {
            mtrace('local_mondaysync: no enabled boards connected, nothing to sync.');
            return;
        }

        $client = new monday_client($token, $apiversion);

        foreach ($boards as $board) {
            $this->run_board($client, $board);
        }

        $this->cleanup_old_data();
    }

    /**
     * Purge sync log and cache rows older than the configured retention
     * period. Log rows hold personal data (old/new field values tied to a
     * user), so this also serves data-minimisation purposes, not just
     * table size. A retention of 0 (or unset) disables cleanup entirely.
     */
    protected function cleanup_old_data(): void {
        global $DB;

        $days = (int) get_config('local_mondaysync', 'logretentiondays');
        if ($days <= 0) {
            return;
        }

        $cutoff = time() - ($days * DAYSECS);

        $deletedlogs = $DB->count_records_select('local_mondaysync_log', 'timecreated < :cutoff', ['cutoff' => $cutoff]);
        $DB->delete_records_select('local_mondaysync_log', 'timecreated < :cutoff', ['cutoff' => $cutoff]);

        $deletedcache = $DB->count_records_select('local_mondaysync_cache', 'timemodified < :cutoff', ['cutoff' => $cutoff]);
        $DB->delete_records_select('local_mondaysync_cache', 'timemodified < :cutoff', ['cutoff' => $cutoff]);

        if ($deletedlogs > 0 || $deletedcache > 0) {
            mtrace("local_mondaysync: cleanup - removed $deletedlogs log row(s) and $deletedcache cache row(s) older than $days day(s).");
        }
    }

    /**
     * Run a sync pass for a single connected board.
     */
    protected function run_board(monday_client $client, \stdClass $board): void {
        global $DB;

        $matchingcolumnid = trim((string)$board->matchingcolumnid);
        $mappings = mapping_util::parse((string)$board->fieldmappings);

        if (empty($matchingcolumnid) || empty($mappings)) {
            mtrace('local_mondaysync: board "' . $board->name . '" has no matching column / mappings configured yet, skipping.');
            return;
        }

        try {
            $livecolumns = $client->get_board_columns($board->boardid);
        } catch (\Throwable $e) {
            mtrace('local_mondaysync: board "' . $board->name . '" - failed to fetch columns: ' . $e->getMessage());
            $this->log($DB, $board->id, '(board)', null, null, null, null, 'error',
                'Could not fetch columns from Monday.com this run - board was skipped entirely: ' . $e->getMessage());
            return;
        }
        $livecolumnids = array_column($livecolumns, 'id');

        if (!in_array($matchingcolumnid, $livecolumnids, true)) {
            // The matching column itself is gone - every row on this board
            // would come back with no ID Number, producing a wall of
            // confusing per-item "skipped" entries with no obvious cause.
            // One clear board-level error instead.
            $this->log($DB, $board->id, '(board)', null, null, null, null, 'error',
                'Matching column "' . $matchingcolumnid . '" no longer exists on this board - check Connected Boards > Configure mapping.');
            return;
        }

        $this->log_orphaned_mappings($DB, $board, $mappings, $livecolumnids);

        $statusindices = ['created' => null, 'error' => null];
        $cancreate = false;

        if (!empty($board->createusers)) {
            if (!$this->board_can_create_users($board)) {
                $this->log($DB, $board->id, '(board)', null, null, null, null, 'warning',
                    'User creation is enabled for this board but not fully configured (trigger column, firstname/lastname/email/username columns, and default auth are all required) - check Connected Boards > Configure mapping.');
            } else {
                $missingcolumns = $this->creation_columns_missing($board, $livecolumnids);
                if (!empty($missingcolumns)) {
                    // Configured, but one or more of those columns has
                    // since been deleted on Monday.com. Without this check,
                    // process_user_creation() would just read an empty
                    // value for the missing column and fail each row with
                    // a misleading "missing required value" error, as if
                    // the board *row* were incomplete rather than the
                    // board *configuration*. One clear warning instead,
                    // and creation is skipped entirely for this run.
                    $this->log($DB, $board->id, '(board)', null, null, null, null, 'warning',
                        'User creation is configured but the following column(s) no longer exist on this board: ' .
                        implode(', ', $missingcolumns) . ' - check Connected Boards > Configure mapping.');
                } else {
                    $cancreate = true;
                    $triggercolumnid = trim((string)$board->createtriggercolumnid);
                    $statusindices = $this->resolve_trigger_status_indices($livecolumns, $triggercolumnid);
                    if ($statusindices['created'] === null || $statusindices['error'] === null) {
                        $this->log($DB, $board->id, '(board)', null, null, null, null, 'warning',
                            'The user-creation trigger column is missing a status option this needs - it must have both "' .
                            mapping_util::STATUS_CREATED . '" and "' . mapping_util::STATUS_ERROR .
                            '" defined as options on Monday.com (any casing is fine, but both need to exist).');
                    }
                }
            }
        }

        try {
            $items = $client->get_all_board_items($board->boardid);
        } catch (\Throwable $e) {
            mtrace('local_mondaysync: board "' . $board->name . '" - failed to fetch items: ' . $e->getMessage());
            $this->log($DB, $board->id, '(board)', null, null, null, null, 'error',
                'Could not fetch items from Monday.com this run - board was skipped entirely: ' . $e->getMessage());
            return;
        }

        mtrace('local_mondaysync: board "' . $board->name . '" - fetched ' . count($items) . ' item(s)');

        foreach ($items as $item) {
            try {
                $this->process_item($DB, $client, $board, $item, $matchingcolumnid, $mappings, $statusindices, $cancreate);
            } catch (\Throwable $e) {
                // Whatever went wrong with this one item, don't let it take
                // out every other item on this board (or every other board -
                // this is already inside run_board's own item loop).
                $itemid = (string)($item['id'] ?? '?');
                mtrace('local_mondaysync: board "' . $board->name . '" - unexpected error on item ' . $itemid . ': ' . $e->getMessage());
                $this->log($DB, $board->id, $itemid, null, null, null, null, 'error', 'Unexpected error: ' . $e->getMessage());
            }
        }
    }

    /**
     * Which of the required user-creation column references (trigger, plus
     * firstname/lastname/email/username) are configured but no longer
     * exist on the live board - as opposed to board_can_create_users(),
     * which only checks that they're configured at all.
     *
     * @return string[] Human-readable labels of the missing column(s), empty if all present.
     */
    protected function creation_columns_missing(\stdClass $board, array $livecolumnids): array {
        $required = [
            'Trigger column' => $board->createtriggercolumnid,
            'First name column' => $board->createfirstnamecolumnid,
            'Last name column' => $board->createlastnamecolumnid,
            'Email column' => $board->createemailcolumnid,
            'Username column' => $board->createusernamecolumnid,
        ];

        $missing = [];
        foreach ($required as $label => $columnid) {
            if (!in_array(trim((string)$columnid), $livecolumnids, true)) {
                $missing[] = $label;
            }
        }
        return $missing;
    }

    /**
     * Look up the numeric index Monday.com uses internally for each status
     * label this plugin needs to write ("User created" / "Error"), by
     * matching (case-insensitively) against the trigger column's actual
     * defined options. Writing by index rather than by label text is
     * Monday's own recommended approach for exactly this reason: label
     * text can be renamed by a board admin at any time without the index
     * changing, so this keeps working even if Ops tweaks the wording later.
     *
     * @return array ['created' => int|null, 'error' => int|null]
     */
    protected function resolve_trigger_status_indices(array $livecolumns, string $triggercolumnid): array {
        foreach ($livecolumns as $col) {
            if ($col['id'] !== $triggercolumnid) {
                continue;
            }
            $labels = $this->parse_status_labels($col['settings_str'] ?? '');
            return [
                'created' => $this->find_status_index($labels, mapping_util::STATUS_CREATED),
                'error' => $this->find_status_index($labels, mapping_util::STATUS_ERROR),
            ];
        }
        return ['created' => null, 'error' => null];
    }

    /**
     * Parse a Status column's settings_str into its [index => label text] map.
     */
    protected function parse_status_labels(string $settingsstr): array {
        $decoded = json_decode($settingsstr, true);
        if (!is_array($decoded) || empty($decoded['labels']) || !is_array($decoded['labels'])) {
            return [];
        }
        return $decoded['labels'];
    }

    protected function find_status_index(array $labels, string $canonical): ?int {
        foreach ($labels as $index => $label) {
            if (strcasecmp((string)$label, $canonical) === 0) {
                return (int)$index;
            }
        }
        return null;
    }

    /**
     * Check every *configured* mapping (including ones parse() would have
     * silently excluded for execution) against what's currently valid, and
     * log a single summary line if anything's orphaned - a Monday column
     * that's been deleted, a Moodle field that's been deleted, or both.
     * One line per run, not one per affected item, since this is a
     * configuration issue rather than a per-row sync event.
     */
    protected function log_orphaned_mappings(\moodle_database $DB, \stdClass $board, array $validmappings, array $livecolumnids): void {
        $rawmappings = mapping_util::parse_raw((string)$board->fieldmappings);
        $customfields = mapping_util::get_custom_fields();

        $problems = [];
        foreach ($rawmappings as $columnid => $mapping) {
            $moodleok = mapping_util::is_valid_field($mapping['type'], $mapping['field'], $customfields);
            $mondayok = in_array($columnid, $livecolumnids, true);

            if ($moodleok && $mondayok) {
                continue;
            }

            $reasons = [];
            if (!$mondayok) {
                $reasons[] = 'Monday column no longer exists';
            }
            if (!$moodleok) {
                $reasons[] = 'Moodle field "' . $mapping['field'] . '" no longer exists';
            }
            $problems[] = $columnid . ' (' . implode('; ', $reasons) . ')';
        }

        if (empty($problems)) {
            return;
        }

        $this->log($DB, $board->id, '(board)', null, null, null, null, 'warning',
            'Orphaned mapping(s) skipped: ' . implode(', ', $problems) . '. Review this board\'s mapping wizard.');
    }

    /**
     * Process a single Monday.com board item: match it to a Moodle user
     * (attempting creation first if configured and none exists yet) and
     * apply any changed mapped columns, in whichever direction each mapping
     * is configured for.
     */
    protected function process_item(\moodle_database $DB, monday_client $client, \stdClass $board, array $item, string $matchingcolumnid, array $mappings, array $statusindices, bool $cancreate): void {
        $itemid = $item['id'];
        $idnumber = trim($item['columns'][$matchingcolumnid]['text'] ?? '');

        if ($idnumber === '') {
            $this->log($DB, $board->id, $itemid, null, null, null, null, 'skipped', 'No ID Number value on this board row.');
            return;
        }

        // Moodle doesn't enforce uniqueness on user.idnumber at the DB level,
        // so more than one account can technically share a value. Detect
        // that explicitly with get_records() rather than get_record(),
        // which would throw dml_multiple_records_exception and abort the
        // rest of this run rather than just skipping the row.
        $matches = $DB->get_records('user', ['idnumber' => $idnumber, 'deleted' => 0], '',
            'id, idnumber, city, department, institution, address, phone1, phone2, description, firstname, lastname, alternatename, auth, suspended, lastlogin');

        if (empty($matches)) {
            if ($cancreate) {
                $this->process_user_creation($DB, $client, $board, $itemid, $idnumber, $item, $statusindices);
            } else {
                $this->log($DB, $board->id, $itemid, null, null, null, null, 'skipped', 'No Moodle user found with ID Number "' . $idnumber . '".');
            }
            return;
        }

        if (count($matches) > 1) {
            $ids = implode(', ', array_keys($matches));
            $this->log($DB, $board->id, $itemid, null, null, null, null, 'skipped',
                'Multiple Moodle users share ID Number "' . $idnumber . '" (user ids: ' . $ids . ') - skipped to avoid updating the wrong account.');
            return;
        }

        $user = reset($matches);

        if ($cancreate) {
            $this->reconcile_creation_status($DB, $client, $board, $itemid, $item, $statusindices);
        }

        foreach ($mappings as $columnid => $mapping) {
            if ($columnid === $matchingcolumnid) {
                continue;
            }
            if (!array_key_exists($columnid, $item['columns'])) {
                continue;
            }

            try {
                $this->process_mapping($DB, $client, $board, $itemid, $user, $columnid, $mapping, $item);
            } catch (\Throwable $e) {
                $this->log($DB, $board->id, $itemid, $user->id, $mapping['field'], null, null, 'error', $e->getMessage());
            }
        }
    }

    /**
     * Whether this board has everything it needs to create new accounts:
     * enabled, a trigger column, and all four required field mappings, and
     * a default auth method.
     */
    protected function board_can_create_users(\stdClass $board): bool {
        return !empty($board->createusers)
            && trim((string)$board->createtriggercolumnid) !== ''
            && trim((string)$board->createfirstnamecolumnid) !== ''
            && trim((string)$board->createlastnamecolumnid) !== ''
            && trim((string)$board->createemailcolumnid) !== ''
            && trim((string)$board->createusernamecolumnid) !== ''
            && trim((string)$board->createdefaultauth) !== '';
    }

    /**
     * A matched account already exists for this row. The trigger column
     * should read "User created" and nothing else from this point on -
     * this mechanism never creates a second account for the same row, and
     * never deletes anything. If the column reads anything else (someone
     * reset it, or it was never updated), correct it back rather than
     * treating it as an instruction to do something.
     */
    protected function reconcile_creation_status(\moodle_database $DB, monday_client $client, \stdClass $board, string $itemid, array $item, array $statusindices): void {
        $triggercolumnid = trim((string)$board->createtriggercolumnid);
        if (!array_key_exists($triggercolumnid, $item['columns'])) {
            return;
        }

        $current = trim($item['columns'][$triggercolumnid]['text'] ?? '');

        if (strtolower($current) === strtolower(mapping_util::STATUS_CREATED)) {
            return; // Already correct - no write needed.
        }

        if ($statusindices['created'] === null) {
            // Already warned about this once for the whole run in run_board().
            return;
        }

        try {
            $client->set_column_value_json($board->boardid, $itemid, $triggercolumnid, ['index' => $statusindices['created']]);
            $this->log($DB, $board->id, $itemid, null, null, $current, mapping_util::STATUS_CREATED, 'corrected',
                'An account already exists for this row - corrected the creation-status column back to "' . mapping_util::STATUS_CREATED . '".');
        } catch (\Throwable $e) {
            mtrace('local_mondaysync: board "' . $board->name . '" - failed to correct creation status for item ' . $itemid . ': ' . $e->getMessage());
        }
    }

    /**
     * Attempt to create a new Moodle account for a board row with no
     * existing match, if (and only if) its trigger column currently reads
     * "Create user".
     */
    protected function process_user_creation(\moodle_database $DB, monday_client $client, \stdClass $board, string $itemid, string $idnumber, array $item, array $statusindices): void {
        $triggercolumnid = trim((string)$board->createtriggercolumnid);
        if (!array_key_exists($triggercolumnid, $item['columns'])) {
            return;
        }

        $status = strtolower(trim($item['columns'][$triggercolumnid]['text'] ?? ''));

        if ($status === strtolower(mapping_util::STATUS_CREATED)) {
            // The only legitimate way this status gets set is a real
            // account having existed for this row at some point - so
            // finding no match here means it's gone, almost certainly
            // deleted directly in Moodle (this plugin never deletes
            // accounts itself). Flag it rather than silently doing
            // nothing on every future poll. Reusing fail_creation() gives
            // this the same anti-repeat property as an ordinary creation
            // failure: once flipped to "Error", nothing repeats until
            // someone deliberately resets it.
            $this->fail_creation($DB, $client, $board, $itemid, $triggercolumnid, $statusindices,
                'the Moodle account for this row appears to have been deleted - status was "' . mapping_util::STATUS_CREATED .
                '" but no matching account exists. Set this back to "' . mapping_util::STATUS_CREATE .
                '" if a fresh account should be created, or you may delete the row from the Monday board.');
            return;
        }

        if ($status !== strtolower(mapping_util::STATUS_CREATE)) {
            return; // Blank / "Not yet created" / "Error" - just wait for an explicit "Create user".
        }

        $firstname = trim($item['columns'][$board->createfirstnamecolumnid]['text'] ?? '');
        $lastname = trim($item['columns'][$board->createlastnamecolumnid]['text'] ?? '');
        $email = trim($item['columns'][$board->createemailcolumnid]['text'] ?? '');
        $username = trim($item['columns'][$board->createusernamecolumnid]['text'] ?? '');

        $missing = [];
        if ($firstname === '') {
            $missing[] = 'firstname';
        }
        if ($lastname === '') {
            $missing[] = 'lastname';
        }
        if ($email === '') {
            $missing[] = 'email';
        }
        if ($username === '') {
            $missing[] = 'username';
        }
        if (!empty($missing)) {
            $this->fail_creation($DB, $client, $board, $itemid, $triggercolumnid, $statusindices,
                'missing required value(s): ' . implode(', ', $missing) . '.');
            return;
        }

        if (!validate_email($email)) {
            $this->fail_creation($DB, $client, $board, $itemid, $triggercolumnid, $statusindices,
                '"' . $email . '" is not a valid email address.');
            return;
        }

        global $CFG;
        require_once($CFG->dirroot . '/user/lib.php');

        $username = \core_text::strtolower($username);
        if ($username !== \core_user::clean_field($username, 'username')) {
            $this->fail_creation($DB, $client, $board, $itemid, $triggercolumnid, $statusindices,
                '"' . $username . '" contains characters not allowed in a Moodle username.');
            return;
        }

        if ($DB->record_exists('user', ['username' => $username, 'mnethostid' => $CFG->mnet_localhost_id])) {
            $this->fail_creation($DB, $client, $board, $itemid, $triggercolumnid, $statusindices,
                'a Moodle account with the username "' . $username . '" already exists.');
            return;
        }

        if (empty($CFG->allowaccountssameemail)) {
            $select = $DB->sql_equal('email', ':email', false) . ' AND mnethostid = :mnethostid';
            if ($DB->record_exists_select('user', $select, ['email' => $email, 'mnethostid' => $CFG->mnet_localhost_id])) {
                $this->fail_creation($DB, $client, $board, $itemid, $triggercolumnid, $statusindices,
                    'a Moodle account with the email address "' . $email . '" already exists.');
                return;
            }
        }

        $newuser = new \stdClass();
        $newuser->username = $username;
        $newuser->firstname = $firstname;
        $newuser->lastname = $lastname;
        $newuser->email = $email;
        $newuser->idnumber = $idnumber;
        $newuser->auth = trim((string)$board->createdefaultauth) ?: 'manual';
        $newuser->confirmed = 1;
        $newuser->mnethostid = $CFG->mnet_localhost_id;
        // Always set a generated password and let user_create_user() hash it
        // via the proper auth-plugin path (updatepassword=true) - passing a
        // password with updatepassword=false would insert it unhashed.
        // For non-manual auth this value is simply never used to log in.
        $newuser->password = generate_password();

        try {
            $newuserid = user_create_user($newuser, true, true);
        } catch (\Throwable $e) {
            $this->fail_creation($DB, $client, $board, $itemid, $triggercolumnid, $statusindices, $e->getMessage());
            return;
        }

        // Tenant assignment (Moodle Workplace multi-tenancy, if installed)
        // happens immediately, before anything else - specifically before
        // the welcome email below, since that email's content/login URL
        // needs to reflect the correct tenant, not whatever a brand-new
        // account defaults to.
        if (class_exists('\tool_tenant\manager')) {
            $this->assign_tenant($DB, $board, $itemid, $item, $newuserid);
        }

        if ($newuser->auth === 'manual' && !empty($board->createemailpassword)) {
            $this->send_manual_password_email_once($DB, $board, $itemid, $newuserid);
        }

        if ($statusindices['created'] !== null) {
            try {
                $client->set_column_value_json($board->boardid, $itemid, $triggercolumnid, ['index' => $statusindices['created']]);
            } catch (\Throwable $e) {
                mtrace('local_mondaysync: created user ' . $newuserid . ' but failed to update the status column: ' . $e->getMessage());
            }
        }

        $this->log($DB, $board->id, $itemid, $newuserid, null, null, null, 'created',
            'New Moodle account created (username: ' . $username . ', auth: ' . $newuser->auth . ').');
    }

    /**
     * Assign a newly-created account to a Moodle Workplace tenant, if this
     * board has tenant handling configured. Resolution order:
     * 1. The per-row Monday column, if configured and its text matches a
     *    real tenant name (case-insensitive) - a warning is logged if it's
     *    set but doesn't match anything, rather than silently ignored.
     * 2. The board's configured default tenant, used whenever (1) doesn't
     *    resolve to anything (blank column, no column configured, or an
     *    unmatched name).
     * 3. If neither resolves to a tenant, nothing is done - Workplace's
     *    own default tenant behaviour applies, exactly as if this feature
     *    didn't exist.
     *
     * Uses \tool_tenant\manager::allocate_user() and
     * \tool_tenant\tenancy::get_tenants() - the documented API for this
     * (Moodle Workplace's own README explicitly says not to query its
     * tables directly, since the schema isn't a supported external API).
     * Critically, allocate_user() also triggers the proper
     * tenant_user_created event - a raw database write wouldn't, and
     * whatever tenant-specific behaviour Workplace itself hangs off that
     * event (e.g. tenant-branded emails) would likely never fire.
     */
    protected function assign_tenant(\moodle_database $DB, \stdClass $board, string $itemid, array $item, int $newuserid): void {
        $tenants = \tool_tenant\tenancy::get_tenants();

        $tenantid = null;
        $columnid = trim((string)($board->createtenantcolumnid ?? ''));

        if ($columnid !== '' && array_key_exists($columnid, $item['columns'])) {
            $requestedname = trim($item['columns'][$columnid]['text'] ?? '');
            if ($requestedname !== '') {
                foreach ($tenants as $tenant) {
                    if (strcasecmp(trim($tenant->name), $requestedname) === 0) {
                        $tenantid = (int)$tenant->id;
                        break;
                    }
                }
                if ($tenantid === null) {
                    $this->log($DB, $board->id, $itemid, $newuserid, 'tenant', null, $requestedname, 'warning',
                        '"' . $requestedname . '" doesn\'t match any Moodle Workplace tenant name - falling back to this board\'s default tenant, if one is configured.');
                }
            }
        }

        if ($tenantid === null) {
            $defaulttenantid = (int)($board->createdefaulttenantid ?? 0);
            if ($defaulttenantid > 0 && array_key_exists($defaulttenantid, $tenants)) {
                $tenantid = $defaulttenantid;
            }
        }

        if ($tenantid === null) {
            return; // Nothing configured or resolvable - leave Workplace's own default behaviour to apply.
        }

        try {
            (new \tool_tenant\manager())->allocate_user($newuserid, $tenantid, 'local_mondaysync', 'Assigned via Monday.com board sync');
            $this->log($DB, $board->id, $itemid, $newuserid, 'tenant', null, $tenants[$tenantid]->name, 'updated', null);
        } catch (\Throwable $e) {
            $this->log($DB, $board->id, $itemid, $newuserid, 'tenant', null, null, 'error',
                'Account was created, but tenant assignment failed: ' . $e->getMessage());
        }
    }

    /**
     * Mark a creation attempt as failed: flip the trigger column to
     * "Error" by index (best-effort - if even that write fails, the log
     * entry is still recorded) and log the reason.
     */
    protected function fail_creation(\moodle_database $DB, monday_client $client, \stdClass $board, string $itemid, string $triggercolumnid, array $statusindices, string $reason): void {
        if ($statusindices['error'] !== null) {
            try {
                $client->set_column_value_json($board->boardid, $itemid, $triggercolumnid, ['index' => $statusindices['error']]);
            } catch (\Throwable $e) {
                // Swallow - the log entry below is what actually matters here.
            }
        }

        try {
            $client->create_update($itemid, 'Moodle account creation failed: ' . htmlspecialchars($reason, ENT_QUOTES));
        } catch (\Throwable $e) {
            // Best-effort - the Moodle log entry below is the reliable
            // record either way, this is just a convenience for Ops.
            mtrace('local_mondaysync: failed to post error update to item ' . $itemid . ': ' . $e->getMessage());
        }

        $this->log($DB, $board->id, $itemid, null, null, null, null, 'error', 'User creation failed - ' . $reason);
    }

    /**
     * Process one column/field mapping for one already-matched item/user
     * pair, applying the direction and conflict logic described in this
     * class's docblock.
     *
     * One-way mappings (toMoodle/toMonday) don't use the sync cache at
     * all: the live value on the "source" side is compared directly
     * against the live value on the "destination" side every poll, and any
     * mismatch is corrected - regardless of whether the source side
     * actually changed since last time, or the destination side drifted
     * on its own (e.g. someone edited it by hand). That's what makes a
     * one-way mapping genuinely one-way: the destination can never
     * meaningfully diverge from the source for more than one poll cycle.
     * "Both ways" mappings still need the cache, since telling a genuine
     * conflict apart from one side simply catching up requires knowing
     * what each side's value was as of the last sync, not just what they
     * are now.
     */
    protected function process_mapping(\moodle_database $DB, monday_client $client, \stdClass $board, string $itemid, \stdClass $user, string $columnid, array $mapping, array $item): void {
        $direction = $mapping['direction'] ?? mapping_util::DEFAULT_DIRECTION;

        $mondayvalue = trim($item['columns'][$columnid]['text'] ?? '');
        $moodlevalue = $this->get_moodle_value($DB, $user, $mapping);

        if ($mapping['type'] === 'advanced' && $mapping['field'] === 'suspended') {
            // Accept several common phrasings on the way in (Yes/No, 1/0,
            // True/False, Suspended/Not Suspended) rather than requiring
            // one exact string - this field's too consequential to
            // silently misinterpret, so an unrecognised value is an error,
            // not a guess.
            $normalised = mapping_util::parse_suspended_value($mondayvalue);

            if ($normalised === null && in_array($direction, ['toMoodle', 'both'], true)) {
                // Only relevant when this value could actually be written
                // INTO Moodle (toMoodle/both) - never guess, skip this
                // field for this row rather than risk it. A toMonday-only
                // mapping never writes $mondayvalue anywhere, so a blank/
                // unrecognised Monday cell doesn't need blocking here -
                // see below, it naturally still triggers the correcting
                // push instead.
                //
                // 2026 fix (Titus review, Critical #2): a blank cell used
                // to fall through this check entirely (the old guard only
                // fired for non-blank unrecognised text) and become an
                // empty string, which coerces to 0 on Moodle's integer
                // 'suspended' column - silently un-suspending the account,
                // and repeating every single run since '' never matched
                // the stored '0'. Blank now takes the same "never guess,
                // skip" path as genuinely unrecognised text - the only
                // difference is a blank cell doesn't log a loud error,
                // since it's a normal/expected state (e.g. a freshly
                // added row nobody's set a status on yet), whereas
                // unrecognised text is a real problem worth flagging.
                if ($mondayvalue !== '') {
                    $this->log($DB, $board->id, $itemid, $user->id, $mapping['field'], null, $mondayvalue, 'error',
                        'Could not interpret "' . $mondayvalue . '" as suspended/not-suspended - expected something like Yes/No, 1/0, True/False, or Suspended/Not Suspended.');
                }
                return;
            }

            if ($normalised !== null) {
                $mondayvalue = $normalised;
            }
            // else (toMonday-only, blank/unrecognised): leave $mondayvalue
            // as the raw text. It'll naturally differ from $moodlevalue
            // ('0'/'1'), so the toMonday comparison below still correctly
            // treats Monday's display as needing correction, rather than
            // being blocked from ever fixing an out-of-date Monday cell.
        }

        if ($mapping['type'] === 'advanced' && $mapping['field'] === 'auth'
            && $mondayvalue !== '' && in_array($direction, ['toMoodle', 'both'], true)) {
            // Unlike a new account's default auth (validated in the
            // creation wizard against enabled plugins both client- and
            // server-side), an ongoing auth update via this mapping had no
            // such check - a typo on Monday's side would silently set a
            // real account's auth to a non-existent plugin and break their
            // login, with nothing surfacing it anywhere. Only relevant
            // when Monday's value could actually be written to Moodle
            // (toMoodle/both) - a toMonday-only mapping never writes this
            // value into Moodle, so it doesn't need gating here.
            if (!in_array($mondayvalue, get_enabled_auth_plugins(), true)) {
                $this->log($DB, $board->id, $itemid, $user->id, $mapping['field'], null, $mondayvalue, 'error',
                    '"' . $mondayvalue . '" is not a currently-enabled authentication method on this site - value left unchanged to avoid breaking this account\'s login.');
                return;
            }
        }

        if ($direction === 'toMoodle') {
            if ($mondayvalue !== $moodlevalue) {
                $this->apply_to_moodle_and_maybe_email($DB, $board, $itemid, $user->id, $mapping, $mondayvalue);
                $this->log($DB, $board->id, $itemid, $user->id, $mapping['field'], $moodlevalue, $mondayvalue, 'updated', null);
            }
            return;
        }

        if ($direction === 'toMonday') {
            if ($moodlevalue !== $mondayvalue) {
                $this->apply_to_monday($client, $board, $itemid, $columnid, $mapping, $moodlevalue);
                $this->log($DB, $board->id, $itemid, $user->id, $mapping['field'], $mondayvalue, $moodlevalue, 'updated', null);
            }
            return;
        }

        // direction === 'both'
        $cached = $this->get_cached_value($DB, $itemid, $columnid);

        if ($cached === null) {
            // No baseline yet for this item/column - adopt Monday's value
            // into Moodle as the starting point, same as a fresh toMoodle
            // mapping would. Avoids a spurious "conflict" purely because
            // there's nothing to compare against yet.
            if ($mondayvalue !== $moodlevalue) {
                $this->apply_to_moodle_and_maybe_email($DB, $board, $itemid, $user->id, $mapping, $mondayvalue);
                $this->log($DB, $board->id, $itemid, $user->id, $mapping['field'], null, $mondayvalue, 'updated', null);
            }
            $this->set_cached_value($DB, $itemid, $columnid, $mondayvalue);
            return;
        }

        $mondaychanged = ($mondayvalue !== $cached);
        $moodlechanged = ($moodlevalue !== $cached);

        if (!$mondaychanged && !$moodlechanged) {
            return; // Nothing moved on either side.
        }

        if ($mondaychanged && $moodlechanged) {
            if ($mondayvalue === $moodlevalue) {
                // Both sides coincidentally ended up at the same value -
                // nothing to push, just bring the cache up to date.
                $this->set_cached_value($DB, $itemid, $columnid, $mondayvalue);
                return;
            }
            // Genuine conflict: both sides changed, to different values,
            // since the last sync. Monday.com wins.
            $this->apply_to_moodle_and_maybe_email($DB, $board, $itemid, $user->id, $mapping, $mondayvalue);
            $this->log($DB, $board->id, $itemid, $user->id, $mapping['field'], $moodlevalue, $mondayvalue, 'conflict',
                'Both Monday.com and Moodle changed this field since the last sync - Monday.com\'s value was kept.');
            $this->set_cached_value($DB, $itemid, $columnid, $mondayvalue);
            return;
        }

        if ($mondaychanged) {
            $this->apply_to_moodle_and_maybe_email($DB, $board, $itemid, $user->id, $mapping, $mondayvalue);
            $this->log($DB, $board->id, $itemid, $user->id, $mapping['field'], $cached, $mondayvalue, 'updated', null);
            $this->set_cached_value($DB, $itemid, $columnid, $mondayvalue);
            return;
        }

        // Only Moodle changed.
        $this->apply_to_monday($client, $board, $itemid, $columnid, $mapping, $moodlevalue);
        $this->log($DB, $board->id, $itemid, $user->id, $mapping['field'], $cached, $moodlevalue, 'updated', null);
        $this->set_cached_value($DB, $itemid, $columnid, $moodlevalue);
    }

    /**
     * Read the current value of a mapped field, canonicalised to a plain
     * string in the same shape Monday's 'text' representation uses, so the
     * two sides can be compared directly.
     */
    protected function get_moodle_value(\moodle_database $DB, \stdClass $user, array $mapping): string {
        if ($mapping['type'] === 'standard') {
            return trim((string)($user->{$mapping['field']} ?? ''));
        }

        if ($mapping['type'] === 'advanced') {
            if ($mapping['field'] === 'lastlogin') {
                // lastlogin is toMonday-only (Moodle overwrites it itself
                // on every real login, so it's never written back to
                // Moodle) - format it for a human reading the board,
                // rather than pushing a raw Unix timestamp.
                $timestamp = (int)($user->lastlogin ?? 0);
                return $timestamp > 0 ? date('Y-m-d H:i', $timestamp) : get_string('neverloggedin', 'local_mondaysync');
            }
            return trim((string)($user->{$mapping['field']} ?? ''));
        }

        // Read custom profile field data directly from user_info_data,
        // rather than via profile_load_data() - that helper shapes some
        // field types (notably datetime, which it splits into day/month/
        // year/hour/minute sub-values for its edit form) for form
        // rendering rather than giving back the plain stored value, which
        // isn't what we want here.
        $field = $this->get_custom_field_raw_value($DB, $user->id, $mapping['field']);
        $rawvalue = $field['data'];

        if ($mapping['type'] === 'date') {
            $timestamp = ($rawvalue !== null && $rawvalue !== '') ? (int)$rawvalue : 0;
            return $this->moodle_date_to_canonical($timestamp);
        }

        // A "Text area" custom field is backed by Moodle's rich text
        // editor and stores HTML, not plain text - reading it straight
        // through would push literal <p>/<br> markup etc. to Monday's
        // (plain-text) column. Convert to readable plain text first,
        // using the same core helper Moodle itself uses for HTML -> plain
        // text conversion elsewhere (e.g. plain-text email fallbacks).
        if ($field['datatype'] === 'textarea' && $rawvalue !== null && $rawvalue !== '') {
            global $CFG;
            require_once($CFG->libdir . '/weblib.php');
            $rawvalue = html_to_text($rawvalue);
        }

        return trim((string)($rawvalue ?? ''));
    }

    protected function get_custom_field_raw_value(\moodle_database $DB, int $userid, string $shortname): array {
        $sql = "SELECT d.data, f.datatype
                  FROM {user_info_data} d
                  JOIN {user_info_field} f ON f.id = d.fieldid
                 WHERE d.userid = :userid AND f.shortname = :shortname";
        $record = $DB->get_record_sql($sql, ['userid' => $userid, 'shortname' => $shortname]);
        if (!$record) {
            return ['data' => null, 'datatype' => null];
        }
        return ['data' => (string)$record->data, 'datatype' => $record->datatype];
    }

    /**
     * Unix timestamp -> the same date-only text form used when reading
     * Monday's date columns, so both sides compare like for like.
     */
    protected function moodle_date_to_canonical(int $timestamp): string {
        return $timestamp > 0 ? date('Y-m-d', $timestamp) : '';
    }

    /**
     * Apply a field update to Moodle, and - specifically for the
     * Advanced "auth" field transitioning to "manual" - trigger the
     * once-only welcome email if this board's configured to send one.
     * Centralised here so every place that can write auth (toMoodle,
     * both's baseline-adoption, both's conflict/catch-up cases) gets the
     * same behaviour without repeating the check at each call site.
     */
    protected function apply_to_moodle_and_maybe_email(\moodle_database $DB, \stdClass $board, string $itemid, int $userid, array $mapping, string $newvalue): void {
        $this->apply_to_moodle($userid, $mapping, $newvalue);

        if ($mapping['type'] === 'advanced' && $mapping['field'] === 'auth'
            && $newvalue === 'manual' && !empty($board->createemailpassword)) {
            $this->send_manual_password_email_once($DB, $board, $itemid, $userid);
        }
    }

    /**
     * Send the "here's your new password" welcome email for a manual-auth
     * account - but only ever once per account, regardless of how many
     * times its auth method flips away from and back to "manual" (e.g.
     * created as nologin, later switched to manual once Ops are ready).
     * Tracked in a dedicated table rather than the sync log, since the
     * log is subject to the retention-period purge and this needs to be
     * permanent for as long as the account exists.
     */
    protected function send_manual_password_email_once(\moodle_database $DB, \stdClass $board, string $itemid, int $userid): void {
        if ($DB->record_exists('local_mondaysync_manual_email', ['userid' => $userid])) {
            return;
        }

        global $CFG;
        require_once($CFG->dirroot . '/user/lib.php');

        try {
            $user = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);
            $sent = setnew_password_and_mail($user);
        } catch (\Throwable $e) {
            $this->log($DB, $board->id, $itemid, $userid, 'auth', null, null, 'error',
                'Could not send the manual-auth welcome email: ' . $e->getMessage());
            return;
        }

        if ($sent) {
            $record = new \stdClass();
            $record->userid = $userid;
            $record->timesent = time();
            $DB->insert_record('local_mondaysync_manual_email', $record);
            $this->log($DB, $board->id, $itemid, $userid, 'auth', null, null, 'emailed',
                'Sent the new-password welcome email for this manual-auth account.');
        } else {
            // setnew_password_and_mail() returns false rather than
            // throwing when the mail server rejects it - don't mark this
            // as sent, but also don't loop retrying it every poll; it'll
            // only be attempted again on a genuine future transition back
            // into "manual".
            $this->log($DB, $board->id, $itemid, $userid, 'auth', null, null, 'error',
                'Attempted to send the manual-auth welcome email, but it failed (check the server\'s mail logs) - will not retry automatically unless auth changes away from and back to manual.');
        }
    }

    /**
     * Apply a single field update to a Moodle user.
     */
    protected function apply_to_moodle(int $userid, array $mapping, string $newvalue): void {
        global $CFG;

        if ($mapping['type'] === 'standard' || $mapping['type'] === 'advanced') {
            // user_update_user() lives in /user/lib.php, which isn't always
            // already loaded (it happened to work in earlier testing only
            // because something else in those particular page loads pulled
            // it in as a side effect - a plain unattended cron run doesn't
            // reliably do that). Load it explicitly rather than relying on
            // that.
            require_once($CFG->dirroot . '/user/lib.php');
            $user = new \stdClass();
            $user->id = $userid;
            $user->{$mapping['field']} = $newvalue;
            // updatepassword=false matters here specifically: it's what
            // stops an advanced-field mapping from ever being able to
            // touch the password column, regardless of field name -
            // Moodle ignores ->password entirely when this is false. Don't
            // change this to false without re-checking that implication.
            //
            // triggerevent=true (2026 fix, Titus review #7): the two
            // parameters are independent - only updatepassword ever needed
            // to be false. Suppressing the event too meant other Moodle/
            // Workplace functionality listening for user_updated (Dynamic
            // Rules, audience/programme behaviour, other observers) never
            // learned the account had changed.
            user_update_user($user, false, true);
        } else if ($mapping['type'] === 'date') {
            require_once($CFG->dirroot . '/user/profile/lib.php');
            $data = new \stdClass();
            $data->id = $userid;
            // profile_field_datetime::edit_save_data_preprocess() accepts a raw
            // Unix timestamp directly (confirmed against MOODLE_405_STABLE), so
            // we just need Monday's date text converted to one.
            $data->{'profile_field_' . $mapping['field']} = $this->parse_monday_date($newvalue);
            profile_save_data($data);
        } else {
            require_once($CFG->dirroot . '/user/profile/lib.php');
            $data = new \stdClass();
            $data->id = $userid;
            $data->{'profile_field_' . $mapping['field']} = $newvalue;
            profile_save_data($data);
        }
    }

    /**
     * Push a field's current Moodle value to the corresponding Monday.com
     * column.
     */
    protected function apply_to_monday(monday_client $client, \stdClass $board, string $itemid, string $columnid, array $mapping, string $newvalue): void {
        if ($mapping['type'] === 'advanced' && $mapping['field'] === 'suspended') {
            // Convert the canonical '1'/'0' to whichever labels this board
            // is configured to write, since Ops chooses their own wording
            // for the Monday-side column.
            $newvalue = ($newvalue === '1') ? $board->suspendedlabelyes : $board->suspendedlabelno;
        }

        if ($mapping['type'] === 'date') {
            if ($newvalue === '') {
                $client->set_column_value_json($board->boardid, $itemid, $columnid, []); // Clears the column.
            } else {
                $client->set_column_value_json($board->boardid, $itemid, $columnid, ['date' => $newvalue]);
            }
        } else {
            // Covers text and status columns (setting a status by its label
            // text is an officially documented example of this mutation).
            // Other column types may reject a plain-string value - that
            // surfaces as a GraphQL error, caught and logged by the caller,
            // rather than attempted here for every possible column type.
            $client->set_simple_column_value($board->boardid, $itemid, $columnid, $newvalue);
        }
    }

    /**
     * Convert Monday.com's text representation of a date column (e.g.
     * '2026-07-22' or '2026-07-22 14:30:00') into a Unix timestamp.
     * An empty value clears the field (returns 0), matching how
     * profile_field_datetime treats "no date".
     *
     * @throws \moodle_exception if a non-empty value can't be parsed.
     */
    protected function parse_monday_date(string $value): int {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            throw new \moodle_exception('errorbaddate', 'local_mondaysync', '', $value);
        }
        return $timestamp;
    }

    protected function get_cached_value(\moodle_database $DB, string $itemid, string $columnid): ?string {
        $record = $DB->get_record('local_mondaysync_cache', [
            'monday_itemid' => $itemid,
            'monday_columnid' => $columnid,
        ]);
        return $record ? $record->value : null;
    }

    protected function set_cached_value(\moodle_database $DB, string $itemid, string $columnid, string $value): void {
        $record = $DB->get_record('local_mondaysync_cache', [
            'monday_itemid' => $itemid,
            'monday_columnid' => $columnid,
        ]);

        if ($record) {
            $record->value = $value;
            $record->timemodified = time();
            $DB->update_record('local_mondaysync_cache', $record);
        } else {
            $record = new \stdClass();
            $record->monday_itemid = $itemid;
            $record->monday_columnid = $columnid;
            $record->value = $value;
            $record->timemodified = time();
            $DB->insert_record('local_mondaysync_cache', $record);
        }
    }

    protected function log(\moodle_database $DB, int $boardid, string $itemid, ?int $userid, ?string $field, ?string $oldvalue, ?string $newvalue, string $status, ?string $message): void {
        $record = new \stdClass();
        $record->boardid = $boardid;
        $record->monday_itemid = $itemid;
        $record->userid = $userid;
        $record->field = $field;
        $record->oldvalue = $oldvalue;
        $record->newvalue = $newvalue;
        $record->status = $status;
        $record->message = $message;
        $record->timecreated = time();
        $DB->insert_record('local_mondaysync_log', $record);

        mtrace('local_mondaysync: [' . $status . '] item ' . $itemid . ($field ? ' field ' . $field : '') . ($message ? ' - ' . $message : ''));
    }
}