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

namespace local_mondaysync\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use core_privacy\tests\provider_testcase;
use core_privacy\tests\request\approved_contextlist;

/**
 * Privacy provider tests for local_mondaysync.
 *
 * Covers what the external review (item 2) asked for: that erasure removes
 * the cached personal data rather than merely detaching it from its owner,
 * and that a user's export contains every dataset rather than only the
 * last one written (each dataset must be exported to its own subcontext,
 * since Moodle writes a single data.json per subcontext).
 *
 * @package    local_mondaysync
 * @category   test
 * @copyright  2026 Inns of Court College of Advocacy (Part of COIC)
 * @license    http://www.gnu.org/licenses/GPL-3.0 GNU GPL v3 or later
 * @covers     \local_mondaysync\privacy\provider
 */
final class provider_test extends provider_testcase {

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Insert a sync log row.
     */
    protected function add_log(int $userid, string $itemid, string $field, string $newvalue): void {
        global $DB;
        $DB->insert_record('local_mondaysync_log', (object) [
            'boardid' => 999,
            'monday_itemid' => $itemid,
            'userid' => $userid,
            'field' => $field,
            'oldvalue' => 'old',
            'newvalue' => $newvalue,
            'status' => 'updated',
            'message' => null,
            'timecreated' => time(),
        ]);
    }

    /**
     * Insert a sync cache row; a null userid models a legacy row whose owner
     * the upgrade-time backfill couldn't work out.
     */
    protected function add_cache(string $itemid, string $columnid, string $value, ?int $userid): void {
        global $DB;
        $DB->insert_record('local_mondaysync_cache', (object) [
            'monday_itemid' => $itemid,
            'monday_columnid' => $columnid,
            'value' => $value,
            'userid' => $userid,
            'timemodified' => time(),
        ]);
    }

    /**
     * Insert a welcome-email marker row.
     */
    protected function add_email(int $userid, int $timesent): void {
        global $DB;
        $DB->insert_record('local_mondaysync_manual_email', (object) ['userid' => $userid, 'timesent' => $timesent]);
    }

    public function test_metadata_declares_the_cache_table(): void {
        $collection = provider::get_metadata(new collection('local_mondaysync'));
        $names = array_map(function ($item) {
            return $item->get_name();
        }, $collection->get_collection());

        $this->assertContains('local_mondaysync_log', $names);
        $this->assertContains('local_mondaysync_manual_email', $names);
        $this->assertContains('local_mondaysync_cache', $names);
    }

    public function test_get_contexts_for_userid(): void {
        $withdata = $this->getDataGenerator()->create_user();
        $cacheonly = $this->getDataGenerator()->create_user();
        $nodata = $this->getDataGenerator()->create_user();
        $this->add_log($withdata->id, 'item1', 'city', 'Leeds');
        $this->add_cache('item2', 'text1', 'cached', $cacheonly->id);

        $system = \context_system::instance();
        $this->assertEquals([$system->id], provider::get_contexts_for_userid($withdata->id)->get_contextids());
        $this->assertEquals([$system->id], provider::get_contexts_for_userid($cacheonly->id)->get_contextids());
        $this->assertEmpty(provider::get_contexts_for_userid($nodata->id)->get_contextids());
    }

    public function test_get_users_in_context(): void {
        $logonly = $this->getDataGenerator()->create_user();
        $cacheonly = $this->getDataGenerator()->create_user();
        $emailonly = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->create_user(); // Has nothing - must not be listed.
        $this->add_log($logonly->id, 'item1', 'city', 'Leeds');
        $this->add_cache('item2', 'text1', 'cached', $cacheonly->id);
        $this->add_email($emailonly->id, time());

        $userlist = new userlist(\context_system::instance(), 'local_mondaysync');
        provider::get_users_in_context($userlist);

        $this->assertEqualsCanonicalizing(
            [$logonly->id, $cacheonly->id, $emailonly->id],
            $userlist->get_userids()
        );
    }

    /**
     * Every dataset must survive in the export. Exporting them all to one
     * subcontext made each call overwrite the last, so only one dataset
     * ever reached the user - this fails against that behaviour.
     */
    public function test_export_user_data_includes_every_dataset(): void {
        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $this->add_log($user->id, 'item1', 'city', 'Leeds');
        $this->add_log($other->id, 'item2', 'city', 'Hull');
        $this->add_email($user->id, 1700000000);
        $this->add_cache('item1', 'text1', 'cachedvalue', $user->id);
        $this->add_cache('item2', 'text1', 'otherscache', $other->id);

        $context = \context_system::instance();
        $this->export_context_data_for_user($user->id, $context, 'local_mondaysync');
        $writer = writer::with_context($context);
        $plugin = get_string('pluginname', 'local_mondaysync');

        $log = $writer->get_data([$plugin, get_string('privacy:export:synclog', 'local_mondaysync')]);
        $this->assertNotEmpty($log);
        $this->assertCount(1, $log->synclog);
        $this->assertEquals('Leeds', $log->synclog[0]->newvalue);

        $email = $writer->get_data([$plugin, get_string('privacy:export:welcomeemail', 'local_mondaysync')]);
        $this->assertNotEmpty($email);
        $this->assertEquals(get_string('yes'), $email->manualauthemail->sent);

        $cache = $writer->get_data([$plugin, get_string('privacy:export:synccache', 'local_mondaysync')]);
        $this->assertNotEmpty($cache);
        $this->assertCount(1, $cache->synccache);
        $this->assertEquals('cachedvalue', $cache->synccache[0]->value);
    }

    /**
     * A pending (0) or in-progress (negative) welcome email has no real
     * timestamp - it must export as "not sent", not as a nonsense date.
     */
    public function test_export_welcome_email_not_yet_sent(): void {
        $pending = $this->getDataGenerator()->create_user();
        $inprogress = $this->getDataGenerator()->create_user();
        $this->add_email($pending->id, 0);
        $this->add_email($inprogress->id, -1700000000);

        $context = \context_system::instance();
        $plugin = get_string('pluginname', 'local_mondaysync');
        $subcontext = [$plugin, get_string('privacy:export:welcomeemail', 'local_mondaysync')];

        foreach ([$pending, $inprogress] as $user) {
            writer::reset();
            $this->export_context_data_for_user($user->id, $context, 'local_mondaysync');
            $email = writer::with_context($context)->get_data($subcontext);
            $this->assertEquals(get_string('no'), $email->manualauthemail->sent);
            $this->assertSame('', $email->manualauthemail->timesent);
        }
    }

    /**
     * Erasure must delete the cached personal data itself, not just clear
     * its userid - clearing the owner leaves the value stored while making
     * it impossible for a later privacy request to find.
     */
    public function test_delete_data_for_user_deletes_cache_rows(): void {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $context = \context_system::instance();

        $this->add_log($user->id, 'item1', 'city', 'Leeds');
        $this->add_log($other->id, 'item2', 'city', 'Hull');
        $this->add_email($user->id, 1700000000);
        $this->add_email($other->id, 1700000000);
        $this->add_cache('item1', 'text1', 'owned-by-user', $user->id);
        $this->add_cache('item1', 'text2', 'unowned-legacy-for-users-item', null);
        $this->add_cache('item2', 'text1', 'owned-by-other', $other->id);
        $this->add_cache('item9', 'text1', 'unowned-unrelated', null);

        provider::delete_data_for_user(new approved_contextlist($user, 'local_mondaysync', [$context->id]));

        // The user's value is gone from the table entirely - not merely unowned.
        $this->assertFalse($DB->record_exists('local_mondaysync_cache', ['value' => 'owned-by-user']));
        // An unowned legacy row for the same Monday item was cached for this person too.
        $this->assertFalse($DB->record_exists('local_mondaysync_cache', ['value' => 'unowned-legacy-for-users-item']));
        // Nobody else's data is touched.
        $this->assertTrue($DB->record_exists('local_mondaysync_cache', ['value' => 'owned-by-other']));
        $this->assertTrue($DB->record_exists('local_mondaysync_cache', ['value' => 'unowned-unrelated']));

        $this->assertFalse($DB->record_exists('local_mondaysync_log', ['userid' => $user->id]));
        $this->assertFalse($DB->record_exists('local_mondaysync_manual_email', ['userid' => $user->id]));
        $this->assertTrue($DB->record_exists('local_mondaysync_log', ['userid' => $other->id]));
        $this->assertTrue($DB->record_exists('local_mondaysync_manual_email', ['userid' => $other->id]));
    }

    public function test_delete_data_for_user_ignores_other_contexts(): void {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $this->add_cache('item1', 'text1', 'kept', $user->id);

        $usercontext = \context_user::instance($user->id);
        provider::delete_data_for_user(new approved_contextlist($user, 'local_mondaysync', [$usercontext->id]));

        $this->assertTrue($DB->record_exists('local_mondaysync_cache', ['value' => 'kept']));
    }

    public function test_delete_data_for_users(): void {
        global $DB;
        $one = $this->getDataGenerator()->create_user();
        $two = $this->getDataGenerator()->create_user();
        $three = $this->getDataGenerator()->create_user();
        $context = \context_system::instance();

        $this->add_log($one->id, 'item1', 'city', 'Leeds');
        $this->add_cache('item1', 'text1', 'one', $one->id);
        $this->add_cache('item2', 'text1', 'two', $two->id);
        $this->add_cache('item3', 'text1', 'three', $three->id);

        provider::delete_data_for_users(new approved_userlist($context, 'local_mondaysync', [$one->id, $two->id]));

        $this->assertFalse($DB->record_exists('local_mondaysync_cache', ['value' => 'one']));
        $this->assertFalse($DB->record_exists('local_mondaysync_cache', ['value' => 'two']));
        $this->assertTrue($DB->record_exists('local_mondaysync_cache', ['value' => 'three']));
        $this->assertFalse($DB->record_exists('local_mondaysync_log', ['userid' => $one->id]));
    }

    public function test_delete_data_for_all_users_in_context(): void {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $this->add_log($user->id, 'item1', 'city', 'Leeds');
        $this->add_email($user->id, 1700000000);
        $this->add_cache('item1', 'text1', 'cached', $user->id);
        $this->add_cache('item9', 'text1', 'unowned', null);

        provider::delete_data_for_all_users_in_context(\context_system::instance());

        $this->assertEquals(0, $DB->count_records('local_mondaysync_log'));
        $this->assertEquals(0, $DB->count_records('local_mondaysync_manual_email'));
        $this->assertEquals(0, $DB->count_records('local_mondaysync_cache'));
    }
}