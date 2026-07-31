<?php
namespace local_mondaysync\task;

defined('MOODLE_INTERNAL') || die();

class sync_task extends \core\task\scheduled_task {

    public function get_name() {
        return get_string('synctask', 'local_mondaysync');
    }

    public function execute() {
        $manager = new \local_mondaysync\sync_manager();
        $manager->run();
    }
}
