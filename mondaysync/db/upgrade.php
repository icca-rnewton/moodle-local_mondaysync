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
 * Upgrade steps for local_mondaysync.
 *
 * @package    local_mondaysync
 * @copyright  2026 Inns of Court College of Advocacy (Part of COIC)
 * @license    http://www.gnu.org/licenses/GPL-3.0 GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * @param int $oldversion
 * @return bool
 */
function xmldb_local_mondaysync_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026072300) {

        // Define table local_mondaysync_board to be created.
        $table = new xmldb_table('local_mondaysync_board');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('boardid', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, null);
        $table->add_field('matchingcolumnid', XMLDB_TYPE_CHAR, '100', null, null, null, null);
        $table->add_field('fieldmappings', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('enabled', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('sortorder', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Define field boardid to be added to local_mondaysync_log.
        $logtable = new xmldb_table('local_mondaysync_log');
        $field = new xmldb_field('boardid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'id');
        if (!$dbman->field_exists($logtable, $field)) {
            $dbman->add_field($logtable, $field);
        }

        // Migrate the old single-board settings into the first board row,
        // so existing installs (like the one this was developed against)
        // don't need to be reconfigured from scratch.
        $oldboardid = trim((string) get_config('local_mondaysync', 'boardid'));
        if ($oldboardid !== '') {
            $existing = $DB->record_exists('local_mondaysync_board', []);
            if (!$existing) {
                $record = new stdClass();
                $record->name = get_string('migratedboardname', 'local_mondaysync');
                $record->boardid = $oldboardid;
                $record->matchingcolumnid = trim((string) get_config('local_mondaysync', 'matchingcolumnid'));
                $record->fieldmappings = (string) get_config('local_mondaysync', 'fieldmappings');
                $record->enabled = 1;
                $record->sortorder = 0;
                $record->timecreated = time();
                $record->timemodified = time();
                $DB->insert_record('local_mondaysync_board', $record);
            }
        }

        // These settings are superseded by the local_mondaysync_board table.
        unset_config('boardid', 'local_mondaysync');
        unset_config('matchingcolumnid', 'local_mondaysync');
        unset_config('fieldmappings', 'local_mondaysync');

        upgrade_plugin_savepoint(true, 2026072300, 'local', 'mondaysync');
    }

    if ($oldversion < 2026072304) {
        $table = new xmldb_table('local_mondaysync_board');
        $index = new xmldb_index('boardid', XMLDB_INDEX_UNIQUE, ['boardid']);

        if (!$dbman->index_exists($table, $index)) {
            // Only add the unique index if there isn't already duplicate
            // data that would make it fail - defensive, since this table
            // could theoretically already hold duplicates from before
            // board_form validation started preventing them.
            $duplicates = $DB->get_records_sql(
                "SELECT boardid FROM {local_mondaysync_board} GROUP BY boardid HAVING COUNT(*) > 1"
            );

            if (empty($duplicates)) {
                $dbman->add_index($table, $index);
            } else {
                debugging('local_mondaysync: skipped adding unique index on local_mondaysync_board.boardid - ' .
                    'duplicate boardid values already exist. Resolve manually (Connected Boards page) then ' .
                    'the index can be added by a future upgrade.', DEBUG_DEVELOPER);
            }
        }

        upgrade_plugin_savepoint(true, 2026072304, 'local', 'mondaysync');
    }

    if ($oldversion < 2026072500) {
        $table = new xmldb_table('local_mondaysync_board');

        $newfields = [
            new xmldb_field('suspendedlabelyes', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, 'Suspended'),
            new xmldb_field('suspendedlabelno', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, 'Not Suspended'),
            new xmldb_field('createusers', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0'),
            new xmldb_field('createtriggercolumnid', XMLDB_TYPE_CHAR, '100', null, null, null, null),
            new xmldb_field('createfirstnamecolumnid', XMLDB_TYPE_CHAR, '100', null, null, null, null),
            new xmldb_field('createlastnamecolumnid', XMLDB_TYPE_CHAR, '100', null, null, null, null),
            new xmldb_field('createemailcolumnid', XMLDB_TYPE_CHAR, '100', null, null, null, null),
            new xmldb_field('createusernamecolumnid', XMLDB_TYPE_CHAR, '100', null, null, null, null),
            new xmldb_field('createdefaultauth', XMLDB_TYPE_CHAR, '100', null, null, null, null),
            new xmldb_field('createemailpassword', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0'),
        ];

        foreach ($newfields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        upgrade_plugin_savepoint(true, 2026072500, 'local', 'mondaysync');
    }

    if ($oldversion < 2026072506) {
        $table = new xmldb_table('local_mondaysync_manual_email');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timesent', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('userid', XMLDB_KEY_FOREIGN_UNIQUE, ['userid'], 'user', ['id']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026072506, 'local', 'mondaysync');
    }

    if ($oldversion < 2026072509) {
        $table = new xmldb_table('local_mondaysync_board');

        $newfields = [
            new xmldb_field('createtenantcolumnid', XMLDB_TYPE_CHAR, '100', null, null, null, null),
            new xmldb_field('createdefaulttenantid', XMLDB_TYPE_INTEGER, '10', null, null, null, null),
        ];

        foreach ($newfields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        upgrade_plugin_savepoint(true, 2026072509, 'local', 'mondaysync');
    }

    if ($oldversion < 2026072700) {
        $table = new xmldb_table('local_mondaysync_cache');
        $field = new xmldb_field('userid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'value');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $index = new xmldb_index('userid', XMLDB_INDEX_NOTUNIQUE, ['userid']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        // Best-effort backfill (Titus review #17): for existing cache rows
        // with no userid yet, try to trace one via the log table's
        // history for the same monday_itemid, while that history still
        // exists (it's subject to its own retention purge - this window
        // narrows over time, which is exactly why userid is now stored
        // directly going forward rather than relying on this tracing at
        // all). Rows with no matching log entry, or no userid recorded on
        // that entry, are simply left as before (userid null) - they'll
        // pick one up naturally next time that item/column is synced.
        // Done as a small per-row PHP loop rather than a single SQL
        // statement, since a portable "most recent matching log row"
        // subquery isn't straightforward across different DB engines,
        // and this is realistically a small table.
        $rows = $DB->get_records_select('local_mondaysync_cache', 'userid IS NULL', [], '', 'id, monday_itemid');
        foreach ($rows as $row) {
            $loguserid = $DB->get_field_sql(
                "SELECT userid FROM {local_mondaysync_log}
                  WHERE monday_itemid = :itemid AND userid IS NOT NULL
               ORDER BY timecreated DESC",
                ['itemid' => $row->monday_itemid],
                IGNORE_MULTIPLE
            );
            if ($loguserid) {
                $DB->set_field('local_mondaysync_cache', 'userid', $loguserid, ['id' => $row->id]);
            }
        }

        upgrade_plugin_savepoint(true, 2026072700, 'local', 'mondaysync');
    }

    if ($oldversion < 2026101304) {
        // External review (item 4): local_mondaysync_manual_email changed
        // meaning. It started as "this account has been sent its welcome
        // email"; it is now also the marker for "this account was created
        // by this plugin", which is what makes an account eligible for
        // that email at all, and it records the progress of a send so an
        // interrupted or failed one can be retried a bounded number of
        // times instead of being stranded.
        $table = new xmldb_table('local_mondaysync_manual_email');

        $field = new xmldb_field('attempts', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'timesent');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $field = new xmldb_field('timeattempted', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'attempts');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Accounts this plugin created before that marker existed have no
        // row, so the new code would treat them as ineligible and never
        // send them anything. The sync log is the only record of which
        // accounts the plugin created (an entry with status 'created' and
        // no field - the tenant assignment also logs 'created', but with a
        // field). Seed a pending marker for those that are still stranded:
        // still on manual auth and never used at all - checked across every
        // login/access column, since Moodle's lastlogin is only the
        // *previous* login and stays 0 after a first one - so nobody who
        // is already in has their working password reset. Accounts that
        // already have a row are left exactly as they are. The log is
        // subject to its own retention purge, so an account created longer
        // ago than that can't be identified this way.
        $sql = "SELECT DISTINCT l.userid
                  FROM {local_mondaysync_log} l
                  JOIN {user} u ON u.id = l.userid
                 WHERE l.status = :created
                   AND l.field IS NULL
                   AND u.deleted = 0
                   AND u.auth = :auth
                   AND u.firstaccess = 0 AND u.lastaccess = 0
                   AND u.currentlogin = 0 AND u.lastlogin = 0
                   AND NOT EXISTS (SELECT 1 FROM {local_mondaysync_manual_email} m WHERE m.userid = u.id)";
        $userids = $DB->get_fieldset_sql($sql, ['created' => 'created', 'auth' => 'manual']);
        foreach ($userids as $userid) {
            $DB->insert_record('local_mondaysync_manual_email', (object) [
                'userid' => $userid,
                'timesent' => 0,
                'attempts' => 0,
                'timeattempted' => 0,
            ]);
        }

        upgrade_plugin_savepoint(true, 2026101304, 'local', 'mondaysync');
    }

    if ($oldversion < 2026101305) {
        // The "Last login" field now reads Moodle's currentlogin (the
        // account's most recent login) instead of lastlogin, which is only
        // the *previous* login and stays 0 after a first one. The stored
        // field key changed with it, so carry existing settings across
        // rather than leaving them pointing at a field that no longer
        // exists (which would silently stop syncing until re-saved).
        //
        // Saved board mappings: lines of "columnid => advanced:lastlogin:...".
        $boards = $DB->get_records('local_mondaysync_board', null, '', 'id, fieldmappings');
        foreach ($boards as $board) {
            $raw = (string) $board->fieldmappings;
            $updated = preg_replace('/(=>\s*advanced:)lastlogin(?=\s*(?::|$))/m', '${1}currentlogin', $raw);
            if ($updated !== null && $updated !== $raw) {
                $DB->set_field('local_mondaysync_board', 'fieldmappings', $updated, ['id' => $board->id]);
            }
        }

        // The site-level "fields available for mapping" list. Only touched if
        // it was ever explicitly saved - otherwise the defaults (which
        // already name the new field) apply, and a deliberately-disabled
        // field stays disabled.
        $enabled = get_config('local_mondaysync', 'enabledfields');
        if ($enabled !== false && $enabled !== '') {
            $keys = array_map(function ($key) {
                return $key === 'adv:lastlogin' ? 'adv:currentlogin' : $key;
            }, explode(',', $enabled));
            $new = implode(',', array_unique($keys));
            if ($new !== $enabled) {
                set_config('enabledfields', $new, 'local_mondaysync');
            }
        }

        upgrade_plugin_savepoint(true, 2026101305, 'local', 'mondaysync');
    }

    if ($oldversion < 2026102300) {
        // Grade sync: each board can be linked to one course, whose
        // gradebook its grade mappings read from and write to. Existing
        // boards get no course (null), so nothing they already do changes,
        // and no saved data needs migrating - no grade mappings existed
        // before this version.
        //
        // The index is deliberately not unique: several boards have no
        // course (null), and not every supported database allows repeated
        // nulls in a unique index. "One board per course" is checked by
        // the board form instead.
        //
        // Both steps check first, so running this again (an interrupted
        // upgrade, or a redeploy) changes nothing.
        $table = new xmldb_table('local_mondaysync_board');

        $field = new xmldb_field('courseid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'createdefaulttenantid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $index = new xmldb_index('courseid', XMLDB_INDEX_NOTUNIQUE, ['courseid']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        upgrade_plugin_savepoint(true, 2026102300, 'local', 'mondaysync');
    }

    return true;
}