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
 * Privacy provider for local_mondaysync.
 *
 * @package    local_mondaysync
 * @copyright  2026 Inns of Court College of Advocacy (Part of COIC)
 * @license    http://www.gnu.org/licenses/GPL-3.0 GNU GPL v3 or later
 */

namespace local_mondaysync\privacy;

defined('MOODLE_INTERNAL') || die();

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\writer;

/**
 * Privacy provider for local_mondaysync.
 *
 * Personal data touched by this plugin:
 * - local_mondaysync_log: an audit trail of profile field changes applied
 *   to a user (old/new values), and of any new account this plugin has
 *   created, tied to userid.
 * - local_mondaysync_manual_email: a permanent record of whether a
 *   manual-auth account has been sent its "here's your new password"
 *   welcome email, tied to userid - kept indefinitely (unlike the log,
 *   which is subject to a retention purge) so the email is never sent
 *   twice.
 * - local_mondaysync_cache: last-seen Monday.com column values for "Both
 *   ways" mappings, used to detect genuine conflicts. Stores userid
 *   directly - excluding it purely because it has no direct userid
 *   column would miss the point: the *values* stored (an old address,
 *   phone number, etc.) are still real personal data regardless of how
 *   the table is indexed. Storing userid directly (rather than tracing it
 *   via log history, which is itself subject to a retention purge and can
 *   be gone by the time a request is processed) makes export/delete
 *   reliable.
 * - Monday.com itself: this plugin sends each user's ID Number to
 *   Monday.com as part of matching board rows to Moodle accounts, and (for
 *   boards configured to create accounts) reads personal data the other
 *   way to create them. Declared below as an external location.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\core_userlist_provider {

    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(
            'local_mondaysync_log',
            [
                'userid' => 'privacy:metadata:local_mondaysync_log:userid',
                'field' => 'privacy:metadata:local_mondaysync_log:field',
                'oldvalue' => 'privacy:metadata:local_mondaysync_log:oldvalue',
                'newvalue' => 'privacy:metadata:local_mondaysync_log:newvalue',
                'timecreated' => 'privacy:metadata:local_mondaysync_log:timecreated',
            ],
            'privacy:metadata:local_mondaysync_log'
        );

        $collection->add_database_table(
            'local_mondaysync_manual_email',
            [
                'userid' => 'privacy:metadata:local_mondaysync_manual_email:userid',
                'timesent' => 'privacy:metadata:local_mondaysync_manual_email:timesent',
            ],
            'privacy:metadata:local_mondaysync_manual_email'
        );

        $collection->add_database_table(
            'local_mondaysync_cache',
            [
                'userid' => 'privacy:metadata:local_mondaysync_cache:userid',
                'value' => 'privacy:metadata:local_mondaysync_cache:value',
                'timemodified' => 'privacy:metadata:local_mondaysync_cache:timemodified',
            ],
            'privacy:metadata:local_mondaysync_cache'
        );

        $collection->add_external_location_link(
            'monday.com',
            [
                'idnumber' => 'privacy:metadata:mondaycom:idnumber',
                'fieldvalue' => 'privacy:metadata:mondaycom:fieldvalue',
                'newaccountdata' => 'privacy:metadata:mondaycom:newaccountdata',
            ],
            'privacy:metadata:mondaycom'
        );

        return $collection;
    }

    public static function get_contexts_for_userid(int $userid): contextlist {
        global $DB;

        $contextlist = new contextlist();

        if ($DB->record_exists('local_mondaysync_log', ['userid' => $userid])
            || $DB->record_exists('local_mondaysync_manual_email', ['userid' => $userid])
            || $DB->record_exists('local_mondaysync_cache', ['userid' => $userid])) {
            $contextlist->add_system_context();
        }

        return $contextlist;
    }

    public static function get_users_in_context(userlist $userlist): void {
        global $DB;

        $context = $userlist->get_context();
        if ($context->contextlevel != CONTEXT_SYSTEM) {
            return;
        }

        $userlist->add_from_sql('userid', "SELECT DISTINCT userid FROM {local_mondaysync_log} WHERE userid IS NOT NULL", []);
        $userlist->add_from_sql('userid', "SELECT DISTINCT userid FROM {local_mondaysync_manual_email}", []);
        $userlist->add_from_sql('userid', "SELECT DISTINCT userid FROM {local_mondaysync_cache} WHERE userid IS NOT NULL", []);
    }

    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $user = $contextlist->get_user();

        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel != CONTEXT_SYSTEM) {
                continue;
            }

            $sql = "SELECT l.*, b.name AS boardname
                      FROM {local_mondaysync_log} l
                 LEFT JOIN {local_mondaysync_board} b ON b.id = l.boardid
                     WHERE l.userid = :userid
                  ORDER BY l.timecreated ASC";
            $records = $DB->get_records_sql($sql, ['userid' => $user->id]);

            if (!empty($records)) {
                $data = [];
                foreach ($records as $record) {
                    $data[] = (object) [
                        'board' => $record->boardname ?? get_string('privacy:unknownboard', 'local_mondaysync'),
                        'monday_item_id' => $record->monday_itemid,
                        'field' => $record->field,
                        'oldvalue' => $record->oldvalue,
                        'newvalue' => $record->newvalue,
                        'status' => $record->status,
                        'timecreated' => transform::datetime($record->timecreated),
                    ];
                }

                writer::with_context($context)->export_data(
                    [get_string('pluginname', 'local_mondaysync')],
                    (object) ['synclog' => $data]
                );
            }

            $emailrecord = $DB->get_record('local_mondaysync_manual_email', ['userid' => $user->id]);
            if ($emailrecord) {
                writer::with_context($context)->export_data(
                    [get_string('pluginname', 'local_mondaysync')],
                    (object) ['manualauthemailsent' => (object) [
                        'timesent' => transform::datetime($emailrecord->timesent),
                    ]]
                );
            }

            $cacherecords = $DB->get_records('local_mondaysync_cache', ['userid' => $user->id], 'timemodified ASC');
            if (!empty($cacherecords)) {
                $cachedata = [];
                foreach ($cacherecords as $record) {
                    $cachedata[] = (object) [
                        'monday_item_id' => $record->monday_itemid,
                        'monday_column_id' => $record->monday_columnid,
                        'value' => $record->value,
                        'timemodified' => transform::datetime($record->timemodified),
                    ];
                }
                writer::with_context($context)->export_data(
                    [get_string('pluginname', 'local_mondaysync')],
                    (object) ['synccache' => $cachedata]
                );
            }
        }
    }

    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;

        if ($context->contextlevel != CONTEXT_SYSTEM) {
            return;
        }

        $DB->delete_records('local_mondaysync_log');
        $DB->delete_records('local_mondaysync_manual_email');
        // Only clear the userid link, not the whole cache row - the row
        // itself is still needed for ordinary sync change-detection to
        // keep working for whoever's still matched to that item; it's the
        // *personal data link* being erased, not the plugin's own
        // operational state for an item that may still be actively synced.
        $DB->set_field('local_mondaysync_cache', 'userid', null, []);
    }

    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        $user = $contextlist->get_user();

        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel != CONTEXT_SYSTEM) {
                continue;
            }
            $DB->delete_records('local_mondaysync_log', ['userid' => $user->id]);
            $DB->delete_records('local_mondaysync_manual_email', ['userid' => $user->id]);
            $DB->set_field('local_mondaysync_cache', 'userid', null, ['userid' => $user->id]);
        }
    }

    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;

        $context = $userlist->get_context();
        if ($context->contextlevel != CONTEXT_SYSTEM) {
            return;
        }

        $userids = $userlist->get_userids();
        if (empty($userids)) {
            return;
        }

        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $DB->delete_records_select('local_mondaysync_log', "userid $insql", $inparams);
        $DB->delete_records_select('local_mondaysync_manual_email', "userid $insql", $inparams);
        $DB->set_field_select('local_mondaysync_cache', 'userid', null, "userid $insql", $inparams);
    }
}