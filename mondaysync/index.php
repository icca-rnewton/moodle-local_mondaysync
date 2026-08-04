<?php
require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('local_mondaysync_log');

global $DB, $OUTPUT, $PAGE;

$PAGE->set_title(get_string('logviewer', 'local_mondaysync'));
$PAGE->set_heading(get_string('logviewer', 'local_mondaysync'));

echo $OUTPUT->header();

$sql = "SELECT l.*, u.firstname, u.lastname, b.name AS boardname
          FROM {local_mondaysync_log} l
     LEFT JOIN {user} u ON u.id = l.userid
     LEFT JOIN {local_mondaysync_board} b ON b.id = l.boardid
      ORDER BY l.timecreated DESC";
$logs = $DB->get_records_sql($sql, [], 0, 200);

if (empty($logs)) {
    echo $OUTPUT->notification(get_string('nologs', 'local_mondaysync'), 'info');
} else {
    $table = new html_table();
    $table->head = [
        get_string('col_time', 'local_mondaysync'),
        get_string('boardname', 'local_mondaysync'),
        get_string('col_itemid', 'local_mondaysync'),
        get_string('col_user', 'local_mondaysync'),
        get_string('col_field', 'local_mondaysync'),
        get_string('col_oldvalue', 'local_mondaysync'),
        get_string('col_newvalue', 'local_mondaysync'),
        get_string('col_status', 'local_mondaysync'),
        get_string('col_message', 'local_mondaysync'),
    ];

    foreach ($logs as $log) {
        $username = $log->userid ? fullname($log) . ' (#' . $log->userid . ')' : '-';
        $status = $log->status;
        if ($status === 'updated' || $status === 'created' || $status === 'emailed') {
            $status = html_writer::span($status, 'badge badge-success');
        } else if ($status === 'conflict' || $status === 'warning') {
            $status = html_writer::span($status, 'badge badge-warning');
        } else if ($status === 'error') {
            $status = html_writer::span($status, 'badge badge-danger');
        } else {
            $status = html_writer::span($status, 'badge badge-secondary');
        }

        $table->data[] = [
            userdate($log->timecreated),
            $log->boardname ? s($log->boardname) : '-',
            s($log->monday_itemid),
            $username,
            s($log->field ?? ''),
            s($log->oldvalue ?? ''),
            s($log->newvalue ?? ''),
            $status,
            s($log->message ?? ''),
        ];
    }

    echo html_writer::table($table);
}

echo $OUTPUT->footer();