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
     * The first Monday.com availability failure swallowed by a best-effort
     * write during the board currently being processed, if any - see
     * defer_availability() and run().
     *
     * @var api_unavailable_exception|null
     */
    protected ?api_unavailable_exception $deferredavailability = null;

    /**
     * The users whose welcome email is still unfinished, for the board
     * currently being processed - see load_pending_welcome_users().
     *
     * @var array [userid => true]
     */
    protected array $pendingwelcome = [];

    /** Most automatic attempts at a welcome email, in all, before it is handed to an admin. */
    const WELCOME_EMAIL_MAX_ATTEMPTS = 3;

    /** Least time between automatic attempts at the same account's welcome email. */
    const WELCOME_EMAIL_RETRY_INTERVAL = HOURSECS;

    /** How long an "attempt in progress" marker is trusted before the attempt is presumed dead. */
    const WELCOME_EMAIL_STALE_AFTER = 10 * MINSECS;

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

        // External review, item 3: a Monday.com availability failure used
        // to escape this loop on the first board that hit one - so later,
        // possibly perfectly healthy boards never ran, and retention
        // cleanup never ran either. Each board is now isolated: a failure
        // is recorded against that board and the others carry on. The one
        // exception is throttling (HTTP 429), which is shared by every
        // board on the same token - once that happens, sending the rest
        // of the boards' requests would only make it worse, so the
        // remaining boards are skipped and listed as not attempted.
        //
        // Cleanup always runs, and only afterwards is one exception
        // thrown naming what failed - so Moodle still records the task as
        // failed and applies its own retry backoff, which is the point of
        // surfacing availability problems at all.
        global $DB;
        $failures = [];
        $notattempted = [];
        $throttled = false;

        foreach ($boards as $board) {
            if ($throttled) {
                $notattempted[] = $board->name;
                continue;
            }

            $this->deferredavailability = null;
            $failure = null;
            try {
                $this->run_board($client, $board);
                // A best-effort Monday write inside this board (a status
                // correction, an error note) can fail with an availability
                // error without aborting the board - see
                // defer_availability(). It still counts.
                $failure = $this->deferredavailability;
            } catch (api_unavailable_exception $e) {
                $failure = $e;
            }

            if ($failure !== null) {
                $failures[] = '"' . $board->name . '": ' . $failure->getMessage();
                mtrace('local_mondaysync: board "' . $board->name . '" - Monday.com unavailable: ' . $failure->getMessage());
                $this->log($DB, $board->id, '(board)', null, null, null, null, 'error',
                    'Monday.com was unavailable during this board\'s sync, so some or all of it was skipped this run: '
                    . $failure->getMessage());
                if ($failure->is_rate_limited()) {
                    $throttled = true;
                }
            }
        }
        $this->deferredavailability = null;

        $this->cleanup_old_data();

        if (!empty($failures)) {
            $summary = implode('; ', $failures);
            if (!empty($notattempted)) {
                $summary .= '. Not attempted, to avoid making the rate limit worse: ' . implode(', ', $notattempted);
            }
            throw new api_unavailable_exception('errormondayboardsunavailable', 'local_mondaysync', '', $summary);
        }
    }

    /**
     * Remember that a Monday.com availability failure was seen by a
     * best-effort write that deliberately doesn't abort what it's part of
     * (correcting a status, posting an error note) - so the enclosing
     * flow keeps its own audit logging intact, but run() still fails the
     * task at the end rather than the outage going unnoticed. Anything
     * other than an availability failure is ignored here: those are
     * ordinary per-item problems the caller already logs.
     */
    protected function defer_availability(\Throwable $e): void {
        if ($e instanceof api_unavailable_exception && $this->deferredavailability === null) {
            $this->deferredavailability = $e;
        }
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

        // 2026 fix (external review, item 3 / previously fixed and lost):
        // local_mondaysync_cache is NOT audit history - it's the live
        // baseline "both ways" mappings use to tell a genuine conflict
        // apart from one side simply catching up. Purging it on the same
        // schedule as the log meant a field that had simply stayed
        // unchanged for longer than the retention period lost its
        // baseline entirely - the next sync would then treat Monday's
        // current value as if it had just changed, generating a spurious
        // rewrite. The cache is no longer purged by age at all; a
        // genuinely orphaned row (its item/column long gone from Monday)
        // is just inert dead weight from then on, not a correctness risk,
        // so there's nothing to gain from removing it proactively.
        if ($deletedlogs > 0) {
            mtrace("local_mondaysync: cleanup - removed $deletedlogs log row(s) older than $days day(s).");
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
        } catch (api_unavailable_exception $e) {
            // 2026 fix (external review, item 7): let this propagate
            // rather than swallowing it like an ordinary board-specific
            // error - see the exception class's own docblock for why.
            throw $e;
        } catch (\Throwable $e) {
            mtrace('local_mondaysync: board "' . $board->name . '" - failed to fetch columns: ' . $e->getMessage());
            $this->log($DB, $board->id, '(board)', null, null, null, null, 'error',
                'Could not fetch columns from Monday.com this run - board was skipped entirely: ' . $e->getMessage());
            return;
        }
        $livecolumnids = array_column($livecolumns, 'id');
        // Monday's own column type ('date', 'text', 'status', etc.) per
        // column id - needed so the write path can tell when a column is
        // a genuine Date column (which requires Monday's own structured
        // date format) apart from a Text/Status column that merely
        // happens to hold date-looking text.
        $columntypes = array_column($livecolumns, 'type', 'id');

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
                    $triggercolumnid = trim((string)$board->createtriggercolumnid);
                    $statusindices = $this->resolve_trigger_status_indices($livecolumns, $triggercolumnid);
                    if ($statusindices['created'] === null || $statusindices['error'] === null) {
                        // 2026 fix (external review, item 3 / previously
                        // fixed and lost): $cancreate used to be set to
                        // true before this check, so creation still
                        // proceeded even with no way to report a failure
                        // back to Monday. Since a failed attempt never
                        // changes anything on the Monday side (the
                        // trigger column stays at "Create user" forever),
                        // that meant the same failing creation retried on
                        // every single run indefinitely, silently
                        // generating repeated log entries and update
                        // comments. Block creation entirely for this
                        // board until both status options actually exist
                        // - same treatment as a missing required column.
                        $this->log($DB, $board->id, '(board)', null, null, null, null, 'warning',
                            'The user-creation trigger column is missing a status option this needs - it must have both "' .
                            mapping_util::STATUS_CREATED . '" and "' . mapping_util::STATUS_ERROR .
                            '" defined as options on Monday.com (any casing is fine, but both need to exist). User creation is paused for this board until both exist.');
                    } else {
                        $cancreate = true;
                    }
                }
            }
        }

        try {
            $items = $client->get_all_board_items($board->boardid);
        } catch (api_unavailable_exception $e) {
            throw $e;
        } catch (\Throwable $e) {
            mtrace('local_mondaysync: board "' . $board->name . '" - failed to fetch items: ' . $e->getMessage());
            $this->log($DB, $board->id, '(board)', null, null, null, null, 'error',
                'Could not fetch items from Monday.com this run - board was skipped entirely: ' . $e->getMessage());
            return;
        }

        mtrace('local_mondaysync: board "' . $board->name . '" - fetched ' . count($items) . ' item(s)');

        $this->pendingwelcome = $this->load_pending_welcome_users($DB, $board);

        foreach ($items as $item) {
            try {
                $this->process_item($DB, $client, $board, $item, $matchingcolumnid, $mappings, $statusindices, $cancreate, $columntypes);
            } catch (api_unavailable_exception $e) {
                // External review, item 3: this catch used to swallow an
                // availability failure as an ordinary per-item error, so an
                // outage that began mid-board never reached run() (or
                // Moodle's task backoff) at all. Rethrown instead - run()
                // isolates it per board.
                throw $e;
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
            // A saved direction can become disallowed after the fact (a
            // field later restricted to Moodle -> Monday only). parse()
            // drops those silently for safety - they're reported here so
            // it's visible why a mapping stopped doing anything, rather
            // than just going quiet.
            $directionok = !$moodleok
                || mapping_util::is_direction_allowed($mapping['type'], $mapping['field'], $mapping['direction']);

            if ($moodleok && $mondayok && $directionok) {
                continue;
            }

            $reasons = [];
            if (!$mondayok) {
                $reasons[] = 'Monday column no longer exists';
            }
            if (!$moodleok) {
                $reasons[] = 'Moodle field "' . $mapping['field'] . '" no longer exists';
            } else if (!$directionok) {
                $reasons[] = 'Moodle field "' . $mapping['field'] . '" can no longer be synced "' . $mapping['direction']
                    . '" - it is now ' . implode('/', mapping_util::get_allowed_directions($mapping['type'], $mapping['field']))
                    . ' only; re-save this mapping in the wizard';
            }
            $problems[] = $columnid . ' (' . implode('; ', $reasons) . ')';
        }

        if (empty($problems)) {
            return;
        }

        $this->log($DB, $board->id, '(board)', null, null, null, null, 'warning',
            'Mapping(s) skipped: ' . implode(', ', $problems) . '. Review this board\'s mapping wizard.');
    }

    /**
     * Process a single Monday.com board item: match it to a Moodle user
     * (attempting creation first if configured and none exists yet) and
     * apply any changed mapped columns, in whichever direction each mapping
     * is configured for.
     */
    protected function process_item(\moodle_database $DB, monday_client $client, \stdClass $board, array $item, string $matchingcolumnid, array $mappings, array $statusindices, bool $cancreate, array $columntypes): void {
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
        // The column list comes from the field catalogue
        // (mapping_util::get_user_select_fields()), not a separate
        // hand-written list - an earlier version of this query wasn't
        // widened when the catalogue grew, so seven fields (email,
        // country, lang, calendartype, username, confirmed, policyagreed)
        // read back as blank: toMonday could wipe a Monday value, and
        // toMoodle saw every unchanged value as different on every poll.
        $rawmatches = $DB->get_records('user', ['idnumber' => $idnumber, 'deleted' => 0], '',
            mapping_util::get_user_select_fields());

        // Restored (was missing from a stale copy of this file): site
        // administrators and the guest account must never be matched,
        // modified, or created against - regardless of what ID Number
        // happens to be set on them. If a protected account matches,
        // stop entirely for this row rather than falling through to
        // "no match, maybe create" or silently processing other matches.
        $matches = [];
        $matchedprotected = false;
        foreach ($rawmatches as $matchid => $matchuser) {
            if (is_siteadmin($matchid) || isguestuser($matchid)) {
                $matchedprotected = true;
                continue;
            }
            $matches[$matchid] = $matchuser;
        }

        if ($matchedprotected) {
            $this->log($DB, $board->id, $itemid, null, null, null, null, 'skipped',
                'ID Number "' . $idnumber . '" matches a site administrator or the guest account - this row is never processed against a protected account.');
            return;
        }

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

        $this->recover_welcome_email($DB, $client, $board, $itemid, $user);

        foreach ($mappings as $columnid => $mapping) {
            if ($columnid === $matchingcolumnid) {
                continue;
            }
            if (!array_key_exists($columnid, $item['columns'])) {
                continue;
            }

            try {
                $this->process_mapping($DB, $client, $board, $itemid, $user, $columnid, $mapping, $item, $columntypes[$columnid] ?? '');
            } catch (api_unavailable_exception $e) {
                // Same reasoning as the item loop in run_board(): a
                // Monday write failing because Monday is unavailable is
                // not a problem with this one mapping.
                throw $e;
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
            $this->defer_availability($e);
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

        // 2026 fix (previously fixed and lost): mark this account as one
        // this plugin created, regardless of its initial auth method -
        // this is what gates whether the welcome email/password-reset
        // mechanism is ever allowed to touch this account's password at
        // all. An account this plugin did NOT create is never eligible,
        // full stop.
        $this->mark_account_as_plugin_created($DB, $newuserid);

        // Tenant assignment (Moodle Workplace multi-tenancy, if installed)
        // happens immediately, before anything else - specifically before
        // the welcome email below, since that email's content/login URL
        // needs to reflect the correct tenant, not whatever a brand-new
        // account defaults to.
        if (class_exists('\tool_tenant\manager')) {
            $this->assign_tenant($DB, $board, $itemid, $item, $newuserid);
        }

        if ($newuser->auth === 'manual' && !empty($board->createemailpassword)) {
            $this->send_manual_password_email_once($DB, $client, $board, $itemid, $newuserid);
        }

        if ($statusindices['created'] !== null) {
            try {
                $client->set_column_value_json($board->boardid, $itemid, $triggercolumnid, ['index' => $statusindices['created']]);
            } catch (\Throwable $e) {
                // Deferred rather than rethrown: rethrowing here would skip
                // the 'created' audit entry just below for an account that
                // genuinely was created. run() still fails the task at the
                // end if this was an availability failure, and the next run
                // corrects the status via reconcile_creation_status().
                $this->defer_availability($e);
                mtrace('local_mondaysync: created user ' . $newuserid . ' but failed to update the status column: ' . $e->getMessage());
            }
        }

        $this->log($DB, $board->id, $itemid, $newuserid, null, null, null, 'created',
            'New Moodle account created (username: ' . $username . ', auth: ' . $newuser->auth . ').');
    }

    /**
     * Assign a newly-created account to a Moodle Workplace tenant, if this
     * board has tenant handling configured. Resolution order:
     * 1. The per-row Monday column, if configured, its text matches a real
     *    tenant name (case-insensitive), AND that tenant is in the site's
     *    "tenants permitted for Monday-driven provisioning" allowlist. A
     *    warning is logged if the column value is set but doesn't match
     *    anything, or matches a tenant not on the allowlist, rather than
     *    silently ignored either way.
     * 2. The board's configured default tenant, used whenever (1) doesn't
     *    resolve to anything (blank column, no column configured, an
     *    unmatched name, or a match outside the allowlist). This is a
     *    deliberate, trusted choice an admin made in the mapping wizard
     *    (gated behind moodle/site:config, the same trust tier as any
     *    other config in this plugin) - the allowlist deliberately does
     *    NOT apply here, only to the per-row column value, which is
     *    externally-influenced Monday data rather than an admin's own
     *    choice.
     * 3. If neither resolves to a tenant, nothing is done - Workplace's
     *    own default tenant behaviour applies, exactly as if this feature
     *    didn't exist.
     *
     * 2026 fix (external review, item 5): previously, any value in the
     * per-row column that happened to match any tenant name site-wide
     * would provision the new account into that tenant - given Workplace
     * tenancy is meant to be an isolation boundary between separate
     * organisations/cohorts, letting arbitrary externally-influenced
     * board data cross that boundary was a real safety gap. The allowlist
     * closes it for the column-driven path specifically, without
     * restricting the board's own trusted default-tenant setting.
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
        $allowedtenantids = $this->get_allowed_provisioning_tenant_ids();

        $tenantid = null;
        $columnid = trim((string)($board->createtenantcolumnid ?? ''));

        if ($columnid !== '' && array_key_exists($columnid, $item['columns'])) {
            $requestedname = trim($item['columns'][$columnid]['text'] ?? '');
            if ($requestedname !== '') {
                $matchedid = null;
                foreach ($tenants as $tenant) {
                    if (strcasecmp(trim($tenant->name), $requestedname) === 0) {
                        $matchedid = (int)$tenant->id;
                        break;
                    }
                }
                if ($matchedid === null) {
                    $this->log($DB, $board->id, $itemid, $newuserid, 'tenant', null, $requestedname, 'warning',
                        '"' . $requestedname . '" doesn\'t match any Moodle Workplace tenant name - falling back to this board\'s default tenant, if one is configured.');
                } else if ($allowedtenantids !== null && !in_array($matchedid, $allowedtenantids, true)) {
                    $this->log($DB, $board->id, $itemid, $newuserid, 'tenant', null, $requestedname, 'warning',
                        '"' . $requestedname . '" matches a real tenant, but that tenant isn\'t in the site\'s "tenants permitted for Monday-driven provisioning" allowlist - falling back to this board\'s default tenant, if one is configured. Add it to the allowlist in local_mondaysync settings if this should be allowed.');
                } else {
                    $tenantid = $matchedid;
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
            // 2026 fix (external review, item 5): this path only ever runs
            // on a brand-new account (assign_tenant() is only called from
            // process_user_creation()), so there's never a prior
            // tenant_user record - allocate_user() therefore always fires
            // tenant_user_created, never tenant_user_updated. Logging this
            // as 'updated' didn't match what actually happened; 'created'
            // does, consistent with the account-creation log entry itself.
            $this->log($DB, $board->id, $itemid, $newuserid, 'tenant', null, $tenants[$tenantid]->name, 'created', null);
        } catch (\Throwable $e) {
            $this->log($DB, $board->id, $itemid, $newuserid, 'tenant', null, null, 'error',
                'Account was created, but tenant assignment failed: ' . $e->getMessage());
        }
    }

    /**
     * The site-level allowlist of tenant IDs Monday-driven provisioning
     * (the per-row column, specifically) is permitted to select - null if
     * the setting has never been configured (meaning: no restriction,
     * matching this feature's original behaviour rather than silently
     * blocking everything the first time it's used after upgrade), empty
     * array if explicitly configured with nothing allowed.
     */
    protected function get_allowed_provisioning_tenant_ids(): ?array {
        $raw = get_config('local_mondaysync', 'allowedprovisioningtenants');
        if ($raw === false || $raw === '') {
            return null;
        }
        return array_map('intval', explode(',', $raw));
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
                // Not rethrown - the log entry below is what actually
                // matters here - but an availability failure is still
                // remembered so the task fails at the end of the run.
                $this->defer_availability($e);
            }
        }

        try {
            $client->create_update($itemid, 'Moodle account creation failed: ' . htmlspecialchars($reason, ENT_QUOTES));
        } catch (\Throwable $e) {
            // Best-effort - the Moodle log entry below is the reliable
            // record either way, this is just a convenience for Ops. An
            // availability failure is still remembered (see
            // defer_availability()) so the task fails at the end of the run.
            $this->defer_availability($e);
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
    protected function process_mapping(\moodle_database $DB, monday_client $client, \stdClass $board, string $itemid, \stdClass $user, string $columnid, array $mapping, array $item, string $mondaycolumntype): void {
        $direction = $mapping['direction'] ?? mapping_util::DEFAULT_DIRECTION;

        $mondayvalue = trim($item['columns'][$columnid]['text'] ?? '');
        $moodlevalue = $this->get_moodle_value($DB, $user, $mapping);

        if ($mapping['type'] === 'advanced' && $mapping['field'] === 'suspended') {
            // Accept several common phrasings on the way in (Yes/No, 1/0,
            // True/False, Suspended/Not Suspended) rather than requiring
            // one exact string - this field's too consequential to
            // silently misinterpret, so an unrecognised value is an error,
            // not a guess.
            $normalised = mapping_util::parse_suspended_value(
                $mondayvalue,
                $board->suspendedlabelyes ?? null,
                $board->suspendedlabelno ?? null
            );

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

        if ($mapping['type'] === 'standard' && in_array($mapping['field'], mapping_util::FIXED_CHOICE_FIELDS, true)
            && $mondayvalue !== '' && in_array($direction, ['toMoodle', 'both'], true)) {
            // Country, language and calendar type only accept a fixed set
            // of values. Moodle doesn't reject a bad one: user_update_user()
            // silently swaps it for the site's default (core_user::
            // clean_field, with only a developer-level debug message), so
            // a Monday cell holding "United Kingdom" instead of the code
            // "GB" would change the account to the site default country
            // while this log still reported the Monday value as written -
            // and, since Moodle then holds a different value from Monday,
            // look "changed" again on every following poll. Checked here
            // with core's own validation so a bad value is refused and
            // logged accurately instead.
            if (!mapping_util::is_fixed_choice_value_valid($mapping['field'], $mondayvalue)) {
                $this->log($DB, $board->id, $itemid, $user->id, $mapping['field'], null, $mondayvalue, 'error',
                    '"' . $mondayvalue . '" is not a valid value for this field - Moodle expects its own code (for example "GB" for country, "en" for language) - value left unchanged.');
                return;
            }
        }

        if ($mapping['type'] === 'date' && $mondayvalue !== '') {
            // 2026 fix (external review, item 3): Monday's raw date-column
            // text can include a time component ('2026-07-22 14:30:00')
            // even though Moodle's side is always canonicalised to
            // date-only ('2026-07-22' via moodle_date_to_canonical()).
            // Comparing the two directly meant a date mapping could never
            // match even when genuinely unchanged, rewriting on every
            // single poll. Canonicalise Monday's side the same way before
            // comparing - unconditionally (not direction-gated), since
            // even a toMonday-only mapping needs an accurate comparison
            // to decide whether a push is actually needed.
            try {
                $mondayvalue = $this->moodle_date_to_canonical($this->parse_monday_date($mondayvalue));
            } catch (\Throwable $e) {
                $this->log($DB, $board->id, $itemid, $user->id, $mapping['field'], null, $mondayvalue, 'error',
                    'Could not interpret "' . $mondayvalue . '" as a date: ' . $e->getMessage());
                return;
            }
        }

        if ($mapping['type'] === 'custom' && $this->is_checkbox_custom_field($mapping['field'])) {
            // Same underlying bug shape as the date fix above. Monday's
            // checkbox column reports its 'text' as literally "v" when
            // checked and "" when unchecked (confirmed against Monday's
            // own API docs) - but Moodle's side stores and compares
            // against a plain '1'/'0'. Comparing "v"/"" directly against
            // '1'/'0' could never match even when genuinely unchanged,
            // rewriting on every single poll. "Any non-empty text counts
            // as checked" mirrors the same permissive rule Moodle's own
            // PARAM_BOOL cleaning already uses, rather than hardcoding
            // the literal "v" as a magic string.
            $mondayvalue = ($mondayvalue !== '') ? '1' : '0';
        }

        if ($direction === 'toMoodle') {
            if ($mondayvalue !== $moodlevalue) {
                if ($this->blocked_by_clear_protection($mapping, $mondayvalue)) {
                    $this->log($DB, $board->id, $itemid, $user->id, $mapping['field'], $moodlevalue, $mondayvalue, 'skipped',
                        'Monday.com column is blank - left this field unchanged rather than clearing it (clearing on blank is off for this mapping; enable "Allow blank to clear this field" in the mapping wizard if that\'s wanted here).');
                } else {
                    $this->apply_to_moodle_and_maybe_email($DB, $client, $board, $itemid, $user->id, $mapping, $mondayvalue);
                    $this->log($DB, $board->id, $itemid, $user->id, $mapping['field'], $moodlevalue, $mondayvalue, 'updated', null);
                }
            }
            return;
        }

        if ($direction === 'toMonday') {
            $rawtimestamp = null;
            $changed = ($moodlevalue !== $mondayvalue);

            if (mapping_util::is_timestamp_field($mapping['type'], $mapping['field'])) {
                $rawtimestamp = (int)($user->{$mapping['field']} ?? 0);

                if ($mondaycolumntype === 'date') {
                    // A genuine Date column holds a real date, not text, so
                    // comparing Moodle's human-readable string ("Never
                    // logged in", or a local "Y-m-d H:i") against the
                    // column's display text was never reliable: a cleared
                    // cell reads back as "" and so never equalled "Never
                    // logged in" - re-clearing it, and logging "updated",
                    // on every poll - and a real date only matched while
                    // Moodle and the Monday user happened to share a
                    // timezone. Compare what Monday actually stores (its
                    // raw UTC value) against the time this would write.
                    $changed = $this->monday_date_differs_from_timestamp(
                        $rawtimestamp,
                        $item['columns'][$columnid]['value'] ?? null
                    );
                }
            }

            if ($changed) {
                $this->apply_to_monday($client, $board, $itemid, $columnid, $mapping, $moodlevalue, $mondaycolumntype, $rawtimestamp);
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
                if ($this->blocked_by_clear_protection($mapping, $mondayvalue)) {
                    $this->log($DB, $board->id, $itemid, $user->id, $mapping['field'], null, $mondayvalue, 'skipped',
                        'Monday.com column is blank - left this field unchanged rather than clearing it (clearing on blank is off for this mapping; enable "Allow blank to clear this field" in the mapping wizard if that\'s wanted here).');
                } else {
                    $this->apply_to_moodle_and_maybe_email($DB, $client, $board, $itemid, $user->id, $mapping, $mondayvalue);
                    $this->log($DB, $board->id, $itemid, $user->id, $mapping['field'], null, $mondayvalue, 'updated', null);
                }
            }
            $this->set_cached_value($DB, $itemid, $columnid, $mondayvalue, $user->id);
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
                $this->set_cached_value($DB, $itemid, $columnid, $mondayvalue, $user->id);
                return;
            }
            if ($this->blocked_by_clear_protection($mapping, $mondayvalue)) {
                $this->log($DB, $board->id, $itemid, $user->id, $mapping['field'], $moodlevalue, $mondayvalue, 'skipped',
                    'Monday.com column is blank - left this field unchanged rather than clearing it (clearing on blank is off for this mapping; enable "Allow blank to clear this field" in the mapping wizard if that\'s wanted here).');
                return;
            }
            // Genuine conflict: both sides changed, to different values,
            // since the last sync. Monday.com wins.
            $this->apply_to_moodle_and_maybe_email($DB, $client, $board, $itemid, $user->id, $mapping, $mondayvalue);
            $this->log($DB, $board->id, $itemid, $user->id, $mapping['field'], $moodlevalue, $mondayvalue, 'conflict',
                'Both Monday.com and Moodle changed this field since the last sync - Monday.com\'s value was kept.');
            $this->set_cached_value($DB, $itemid, $columnid, $mondayvalue, $user->id);
            return;
        }

        if ($mondaychanged) {
            if ($this->blocked_by_clear_protection($mapping, $mondayvalue)) {
                $this->log($DB, $board->id, $itemid, $user->id, $mapping['field'], $cached, $mondayvalue, 'skipped',
                    'Monday.com column is blank - left this field unchanged rather than clearing it (clearing on blank is off for this mapping; enable "Allow blank to clear this field" in the mapping wizard if that\'s wanted here).');
                return;
            }
            $this->apply_to_moodle_and_maybe_email($DB, $client, $board, $itemid, $user->id, $mapping, $mondayvalue);
            $this->log($DB, $board->id, $itemid, $user->id, $mapping['field'], $cached, $mondayvalue, 'updated', null);
            $this->set_cached_value($DB, $itemid, $columnid, $mondayvalue, $user->id);
            return;
        }

        // Only Moodle changed. Raw timestamp is always null here - a
        // 'both'-direction mapping can never be currentlogin/timecreated
        // (both are restricted to toMonday-only), so there's nothing for
        // it to apply to on this path.
        $this->apply_to_monday($client, $board, $itemid, $columnid, $mapping, $moodlevalue, $mondaycolumntype, null);
        $this->log($DB, $board->id, $itemid, $user->id, $mapping['field'], $cached, $moodlevalue, 'updated', null);
        $this->set_cached_value($DB, $itemid, $columnid, $moodlevalue, $user->id);
    }

    /**
     * Whether a would-be write into Moodle should be blocked because it's
     * a blank value that would clear an existing field, and this specific
     * mapping hasn't explicitly opted in to allowing that.
     *
     * Restored (was missing from a stale copy of this file): a blank
     * Monday cell used to always be treated as a real value to sync,
     * meaning it could silently clear genuine profile data (address,
     * phone number, etc.) across every matched user if a column was ever
     * left unpopulated by mistake. Clearing is opt-in per mapping - blank
     * means "no value given, leave it alone" unless a mapping has
     * specifically been configured to allow it.
     */
    protected function blocked_by_clear_protection(array $mapping, string $newvalue): bool {
        return $newvalue === '' && empty($mapping['allowclear']);
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
            if ($mapping['field'] === 'currentlogin') {
                // The time of the account's most recent login, shown to
                // Ops as "Last login". Moodle's own lastlogin column is
                // deliberately not used: it is the *previous* login
                // (Moodle copies the old currentlogin into it on every
                // login), so it is still 0 after a first login and
                // otherwise lags a login behind. toMonday-only - Moodle
                // overwrites this itself on every real login, so it's
                // never written back to Moodle. Formatted for a human
                // reading the board, rather than pushing a raw Unix
                // timestamp; 0 means the account has never logged in.
                $timestamp = (int)($user->currentlogin ?? 0);
                return $timestamp > 0 ? date('Y-m-d H:i', $timestamp) : get_string('neverloggedin', 'local_mondaysync');
            }
            if ($mapping['field'] === 'timecreated') {
                // Also toMonday-only, same reasoning as currentlogin - this
                // is always set for a genuinely existing account (unlike
                // currentlogin, which is 0 for "never logged in"), so no
                // fallback string is needed.
                $timestamp = (int)($user->timecreated ?? 0);
                return $timestamp > 0 ? date('Y-m-d H:i', $timestamp) : '';
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
    protected function apply_to_moodle_and_maybe_email(\moodle_database $DB, monday_client $client, \stdClass $board, string $itemid, int $userid, array $mapping, string $newvalue): void {
        $this->apply_to_moodle($userid, $mapping, $newvalue);

        if ($mapping['type'] === 'advanced' && $mapping['field'] === 'auth'
            && $newvalue === 'manual' && !empty($board->createemailpassword)) {
            $this->send_manual_password_email_once($DB, $client, $board, $itemid, $userid);
        }

        // Restored (was missing from a stale copy of this file): a write
        // that actually suspends the account must also terminate its
        // active sessions - otherwise someone already logged in stays
        // logged in despite being marked suspended.
        if ($mapping['type'] === 'advanced' && $mapping['field'] === 'suspended' && $newvalue === '1') {
            \core\session\manager::destroy_user_sessions($userid);
        }
    }

    /**
     * Where a welcome email stands, given its marker row:
     *
     * - 'sent'      - delivered; never sent again.
     * - 'running'   - an attempt began a few minutes ago and may still be
     *                 in flight; leave it alone.
     * - 'exhausted' - the automatic attempts have been used up; it is now
     *                 an admin's job (see give_up_on_welcome_email()).
     * - 'wait'      - tried recently; not due again yet.
     * - 'due'       - ready to be attempted.
     *
     * timesent is 0 while pending, negative (minus the start time) while
     * an attempt is in progress, and a real timestamp once sent. Kept as a
     * plain function of the row and the current time so every path that
     * can send a welcome email asks the same question.
     */
    protected function welcome_email_status(\stdClass $record, int $now): string {
        $timesent = (int) $record->timesent;

        if ($timesent > 0) {
            return 'sent';
        }
        if ($timesent < 0 && ($now - abs($timesent)) < self::WELCOME_EMAIL_STALE_AFTER) {
            return 'running';
        }
        if ((int) $record->attempts >= self::WELCOME_EMAIL_MAX_ATTEMPTS) {
            return 'exhausted';
        }
        if ((int) $record->attempts > 0 && ($now - (int) $record->timeattempted) < self::WELCOME_EMAIL_RETRY_INTERVAL) {
            return 'wait';
        }
        return 'due';
    }

    /**
     * Send the "here's your new password" welcome email for a manual-auth
     * account, tracking it so it neither goes out twice nor gets stranded.
     *
     * Only ever acts on an account this plugin created: a row in
     * local_mondaysync_manual_email is the marker for that (seeded at
     * creation, or by the upgrade for older accounts), and an account
     * without one is never touched. The row's timesent/attempts/
     * timeattempted say how far along the send is - see
     * welcome_email_status().
     *
     * Moodle's setnew_password_and_mail() resets the password BEFORE it
     * tries to send, and that ordering lives in core. So an attempt that
     * dies part-way - or whose email fails - leaves the account with a
     * password nobody has been told. External review, item 4: nothing used
     * to revisit that, because the normal triggers (account creation, or
     * the auth value changing to manual) only fire once. Two things
     * address it:
     *
     * - The "attempt in progress" marker (negative timesent, plus the
     *   attempt count) is written BEFORE calling into core, so a crash is
     *   visible afterwards and not mistaken for "never tried".
     * - recover_welcome_email() re-offers every unfinished attempt on
     *   later syncs, through this same function, so an interrupted or
     *   failed send is retried - at most WELCOME_EMAIL_MAX_ATTEMPTS times
     *   in all, no more often than WELCOME_EMAIL_RETRY_INTERVAL apart. If
     *   that runs out, give_up_on_welcome_email() makes sure a person is
     *   told on the Monday row and in the log, so the account is never
     *   left with a reset password and nobody aware of it.
     */
    protected function send_manual_password_email_once(\moodle_database $DB, monday_client $client, \stdClass $board, string $itemid, int $userid): void {
        $record = $DB->get_record('local_mondaysync_manual_email', ['userid' => $userid]);

        if ($record === false) {
            // No row at all - this account wasn't created by this plugin.
            // Never touch its password.
            return;
        }

        $now = time();
        $status = $this->welcome_email_status($record, $now);

        if ($status === 'exhausted' && (int) $record->timesent < 0) {
            // An attempt began, never finished, and there are no attempts
            // left. Settle the marker so this is reported exactly once,
            // and hand it to a person.
            $record->timesent = 0;
            $DB->update_record('local_mondaysync_manual_email', $record);
            $this->give_up_on_welcome_email($DB, $client, $board, $itemid, $userid, (int) $record->attempts);
            return;
        }

        if ($status !== 'due') {
            return;
        }

        // Record the attempt BEFORE calling into core, so one that dies
        // part-way is visible (and counted) rather than looking untried.
        $record->attempts = (int) $record->attempts + 1;
        $record->timeattempted = $now;
        $record->timesent = -$now;
        $DB->update_record('local_mondaysync_manual_email', $record);

        global $CFG;
        require_once($CFG->dirroot . '/user/lib.php');

        try {
            $user = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);
            $sent = setnew_password_and_mail($user);
        } catch (\Throwable $e) {
            // Left marked in-progress on purpose: once the staleness window
            // passes it is retried, and the attempt already counted.
            $this->log($DB, $board->id, $itemid, $userid, 'auth', null, null, 'error',
                'Could not send the manual-auth welcome email (attempt ' . $record->attempts . ' of '
                . self::WELCOME_EMAIL_MAX_ATTEMPTS . '): ' . $e->getMessage());
            return;
        }

        if ($sent) {
            set_user_preference('auth_forcepasswordchange', 1, $userid);
            $record->timesent = time();
            $DB->update_record('local_mondaysync_manual_email', $record);
            $this->log($DB, $board->id, $itemid, $userid, 'auth', null, null, 'emailed',
                'Sent the new-password welcome email for this manual-auth account (a password change will be required at next login).');
            return;
        }

        // setnew_password_and_mail() returns false rather than throwing
        // when the mail server rejects it. The password has already been
        // reset by this point (core's own behaviour, not reorderable from
        // here), so back to pending - retried automatically, within limits.
        $record->timesent = 0;
        $DB->update_record('local_mondaysync_manual_email', $record);

        if ((int) $record->attempts >= self::WELCOME_EMAIL_MAX_ATTEMPTS) {
            $this->give_up_on_welcome_email($DB, $client, $board, $itemid, $userid, (int) $record->attempts);
            return;
        }

        $this->log($DB, $board->id, $itemid, $userid, 'auth', null, null, 'error',
            'Password was reset for the welcome email, but sending it failed (check the server\'s mail logs) - attempt '
            . $record->attempts . ' of ' . self::WELCOME_EMAIL_MAX_ATTEMPTS . '. It will be retried automatically '
            . 'on a later sync, no sooner than an hour from now.');
    }

    /**
     * Stop retrying a welcome email and make sure a person knows - on the
     * Monday item, where Ops will see it, and in the log. Reached once
     * the automatic attempts are used up without the email being delivered
     * (whether the last one failed or was interrupted), at which point the
     * account holds a reset password that nobody has been told.
     */
    protected function give_up_on_welcome_email(\moodle_database $DB, monday_client $client, \stdClass $board, string $itemid, int $userid, int $attempts): void {
        try {
            $client->create_update($itemid, 'This account\'s password was reset so its welcome email could be sent, but the email '
                . 'could not be delivered after ' . $attempts . ' attempts (check the server\'s mail logs). The account currently '
                . 'has a password nobody has been told - a site admin should trigger a password reset for this user via Site '
                . 'administration, or set them to a different auth method. No further automatic attempts will be made.');
        } catch (\Throwable $e) {
            // Advisory note only - deferred, not rethrown, so the audit
            // entry below is still written (see defer_availability()).
            $this->defer_availability($e);
            mtrace('local_mondaysync: failed to post welcome-email note to item ' . $itemid . ': ' . $e->getMessage());
        }

        $this->log($DB, $board->id, $itemid, $userid, 'auth', null, null, 'error',
            'Gave up on the welcome email after ' . $attempts . ' attempts - the account has a reset password that nobody has '
            . 'been told. A site admin needs to trigger a password reset for this user, or change their auth method. '
            . 'A note was posted on this Monday item.');
    }

    /**
     * Re-offer an unfinished welcome email for a matched account - one an
     * attempt began for and never completed, or that failed. Without this
     * nothing ever revisited them (external review, item 4): the normal
     * triggers fire once, at account creation or when the auth value
     * changes to manual, and by then the account is already on manual.
     *
     * Deliberately narrow. Only for an account the plugin created that is
     * still pending (the marker row; $this->pendingwelcome, loaded once per
     * board), on manual auth, not suspended, and never used at all -
     * judged across every login/access column, since Moodle's lastlogin is
     * only the previous login and stays 0 after a first one. Anyone who
     * has already been in has a working password, which resetting would
     * break. Whether and when a send actually goes ahead, and how many
     * times, is decided by send_manual_password_email_once().
     */
    protected function recover_welcome_email(\moodle_database $DB, monday_client $client, \stdClass $board, string $itemid, \stdClass $user): void {
        if (empty($board->createemailpassword)) {
            return;
        }
        if (!isset($this->pendingwelcome[(int) $user->id])) {
            return;
        }
        if (($user->auth ?? '') !== 'manual' || !empty($user->suspended)) {
            return;
        }
        if (!empty($user->firstaccess) || !empty($user->lastaccess) || !empty($user->currentlogin) || !empty($user->lastlogin)) {
            return;
        }

        $this->send_manual_password_email_once($DB, $client, $board, $itemid, (int) $user->id);
    }

    /**
     * The users with a welcome email still unfinished, for a board that
     * has sending turned on - loaded once per board run rather than a
     * lookup per matched user. Includes in-progress markers whatever their
     * attempt count, since an exhausted-but-interrupted one still needs
     * settling and reporting once; pending ones only while attempts remain.
     *
     * @return array [userid => true]
     */
    protected function load_pending_welcome_users(\moodle_database $DB, \stdClass $board): array {
        if (empty($board->createemailpassword)) {
            return [];
        }
        $ids = $DB->get_fieldset_select(
            'local_mondaysync_manual_email',
            'userid',
            'timesent < 0 OR (timesent = 0 AND attempts < :maxattempts)',
            ['maxattempts' => self::WELCOME_EMAIL_MAX_ATTEMPTS]
        );
        return array_fill_keys(array_map('intval', $ids), true);
    }

    /**
     * Mark an account as created by this plugin, eligible for the
     * once-only manual-auth welcome email later (regardless of its
     * initial auth method) - called once, right after creation.
     */
    protected function mark_account_as_plugin_created(\moodle_database $DB, int $userid): void {
        if ($DB->record_exists('local_mondaysync_manual_email', ['userid' => $userid])) {
            return;
        }
        $record = new \stdClass();
        $record->userid = $userid;
        $record->timesent = 0;
        $record->attempts = 0;
        $record->timeattempted = 0;
        $DB->insert_record('local_mondaysync_manual_email', $record);
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
            // 2026 fix (external review, item 6): profile_save_data()
            // just calls each field's own edit_save_data() directly and
            // fires no event of any kind (confirmed against core source)
            // - unlike user_update_user(), which fires user_updated
            // itself for standard/advanced fields. Without this, a
            // custom-field-only change was invisible to Workplace
            // Dynamic Rules and anything else that depends on this
            // event. Fired per mapping here, matching the granularity
            // user_update_user() already uses above (a poll with several
            // changed fields for one user fires several events, same as
            // it already did for standard/advanced fields) - a fuller
            // per-user batching (one event even when multiple fields
            // change together) is a real structural change to how this
            // sync loop is organised, not a one-line fix, and is worth
            // its own separate pass if the redundant firing turns out to
            // matter in practice.
            \core\event\user_updated::create_from_userid($userid)->trigger();
        } else {
            require_once($CFG->dirroot . '/user/profile/lib.php');
            $data = new \stdClass();
            $data->id = $userid;
            $data->{'profile_field_' . $mapping['field']} = $newvalue;
            profile_save_data($data);
            \core\event\user_updated::create_from_userid($userid)->trigger();
        }
    }

    /**
     * Push a field's current Moodle value to the corresponding Monday.com
     * column.
     */
    protected function apply_to_monday(monday_client $client, \stdClass $board, string $itemid, string $columnid, array $mapping, string $newvalue, string $mondaycolumntype = '', ?int $rawtimestamp = null): void {
        if ($mapping['type'] === 'advanced' && $mapping['field'] === 'suspended') {
            // Convert the canonical '1'/'0' to whichever labels this board
            // is configured to write, since Ops chooses their own wording
            // for the Monday-side column.
            $newvalue = ($newvalue === '1') ? $board->suspendedlabelyes : $board->suspendedlabelno;
        }

        if ($mapping['type'] === 'date') {
            if ($newvalue === '') {
                // 2026 fix: confirmed directly against Monday's own Date
                // column docs - clearing requires an empty string via
                // change_simple_column_value, not an empty array via the
                // JSON mutation (which is what this previously sent, and
                // which Monday rejects outright with a GraphQL error).
                // This bug almost certainly predates today - it simply
                // never surfaced before, since nothing had triggered a
                // genuine blank-date write to test it until now.
                $client->set_simple_column_value($board->boardid, $itemid, $columnid, '');
            } else {
                $client->set_column_value_json($board->boardid, $itemid, $columnid, ['date' => $newvalue]);
            }
        } else if (mapping_util::is_timestamp_field($mapping['type'], $mapping['field']) && $mondaycolumntype === 'date') {
            // 2026 addition: currentlogin/timecreated can now write to a
            // genuine Monday "Date" column, not just Text/Status - lets
            // Ops use Monday's own native date filtering/automations
            // against this value (e.g. "if last login is before X, do
            // Y"), which a plain text column can't support. Uses the same
            // structured JSON mechanism as the 'date' mapping type above,
            // with both date and time keys (currentlogin/timecreated carry a
            // meaningful time-of-day, unlike a plain date-only field),
            // explicitly in UTC per Monday's own documented format for
            // this column - gmdate() rather than date(), regardless of
            // the server's configured timezone. A zero timestamp ("never
            // logged in", or - in principle - no creation time at all)
            // has no valid date representation, so it clears the column
            // instead of writing something nonsensical - via an empty
            // string through the simple mutation, same fix as the 'date'
            // type above, not the JSON mutation with an empty array.
            // Validation only allows this combination for
            // currentlogin/timecreated specifically - every other advanced/
            // standard/custom field still can't target a genuine Date
            // column at all, since nothing else here represents a
            // timestamp.
            if (empty($rawtimestamp)) {
                $client->set_simple_column_value($board->boardid, $itemid, $columnid, '');
            } else {
                $client->set_column_value_json($board->boardid, $itemid, $columnid, [
                    'date' => gmdate('Y-m-d', $rawtimestamp),
                    'time' => gmdate('H:i:s', $rawtimestamp),
                ]);
            }
        } else if ($mapping['type'] === 'custom' && $this->is_checkbox_custom_field($mapping['field'])) {
            // Confirmed directly against Monday's own API docs - "The
            // checkbox column does not support change_simple_column_value.
            // You must use change_multiple_column_values to update
            // checkbox values." (change_simple_column_value is exactly
            // what the else branch below uses for everything else) - a
            // checkbox write was always failing with a GraphQL error
            // before this fix, never silently, but never actually taking
            // effect either.
            $client->set_column_value_json($board->boardid, $itemid, $columnid, ['checked' => ($newvalue === '1')]);
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
     * Whether a Monday Date column's current contents differ from the
     * moment a Moodle timestamp represents - used for currentlogin and
     * timecreated, which are written to a Date column as a UTC date and
     * time (see apply_to_monday()).
     *
     * Compares Monday's raw column value (always UTC) rather than its
     * display text, which is formatted in the Monday user's own timezone,
     * and to the minute rather than the second, in case Monday stores
     * less precision than was written. A zero timestamp ("never logged
     * in") means the cell should be empty, so an empty cell is "no
     * difference" and anything else is.
     *
     * @param int $timestamp Moodle's value; 0 means "none".
     * @param string|null $mondayvalue The column's raw JSON value as Monday returned it, or null if empty.
     */
    protected function monday_date_differs_from_timestamp(int $timestamp, ?string $mondayvalue): bool {
        $current = null;
        if ($mondayvalue !== null && $mondayvalue !== '') {
            $decoded = json_decode($mondayvalue, true);
            if (is_array($decoded) && !empty($decoded['date'])) {
                $time = !empty($decoded['time']) ? $decoded['time'] : '00:00:00';
                $parsed = strtotime($decoded['date'] . ' ' . $time . ' UTC');
                $current = ($parsed === false) ? null : intdiv($parsed, 60);
            }
        }

        $wanted = $timestamp > 0 ? intdiv($timestamp, 60) : null;

        return $current !== $wanted;
    }

    /**
     * Whether a custom profile field's own Moodle datatype is 'checkbox' -
     * looked up on demand rather than carried as its own mapping type
     * (unlike 'date', which is a distinct type throughout this class)
     * since only two call sites need to know this, both narrowly scoped
     * to write-format and comparison-format handling specifically.
     */
    protected function is_checkbox_custom_field(string $shortname): bool {
        $fields = mapping_util::get_custom_fields();
        return ($fields[$shortname]['datatype'] ?? null) === 'checkbox';
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

    protected function set_cached_value(\moodle_database $DB, string $itemid, string $columnid, string $value, int $userid): void {
        $record = $DB->get_record('local_mondaysync_cache', [
            'monday_itemid' => $itemid,
            'monday_columnid' => $columnid,
        ]);

        if ($record) {
            $record->value = $value;
            $record->userid = $userid;
            $record->timemodified = time();
            $DB->update_record('local_mondaysync_cache', $record);
        } else {
            $record = new \stdClass();
            $record->monday_itemid = $itemid;
            $record->monday_columnid = $columnid;
            $record->value = $value;
            $record->userid = $userid;
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