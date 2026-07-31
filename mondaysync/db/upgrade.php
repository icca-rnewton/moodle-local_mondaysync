<?php
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

    return true;
}