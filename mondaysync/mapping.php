<?php
require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

$id = required_param('id', PARAM_INT);

admin_externalpage_setup('local_mondaysync_boards');

global $OUTPUT, $PAGE;

$boardslisturl = new moodle_url('/local/mondaysync/boards.php');

try {
    $board = \local_mondaysync\board_repository::get($id);
} catch (\dml_missing_record_exception $e) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification(get_string('boardnotfound', 'local_mondaysync'), 'error');
    echo html_writer::tag('p', html_writer::link($boardslisturl, get_string('backtoboards', 'local_mondaysync')));
    echo $OUTPUT->footer();
    exit;
}

$PAGE->set_title(get_string('mappingwizard', 'local_mondaysync') . ': ' . $board->name);
$PAGE->set_heading(get_string('mappingwizard', 'local_mondaysync') . ': ' . format_string($board->name));
$PAGE->set_url(new moodle_url('/local/mondaysync/mapping.php', ['id' => $id]));

$backurl = new moodle_url('/local/mondaysync/mapping.php', ['id' => $id]);

$token = trim((string)get_config('local_mondaysync', 'apitoken'));
$apiversion = trim((string)get_config('local_mondaysync', 'apiversion'));

if (empty($token)) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification(get_string('columnsnotconfigured', 'local_mondaysync'), 'warning');
    echo $OUTPUT->footer();
    exit;
}

try {
    $client = new \local_mondaysync\monday_client($token, $apiversion);
    $columns = $client->get_board_columns($board->boardid);
} catch (\Throwable $e) {
    echo $OUTPUT->header();
    echo html_writer::tag('p', html_writer::link($boardslisturl, get_string('backtoboards', 'local_mondaysync')));
    // s() here matters: on errormondayresponse specifically, the message can
    // contain Monday's raw (possibly non-JSON, e.g. an HTML error page) HTTP
    // response body, and notification() doesn't escape its content itself.
    echo $OUTPUT->notification(get_string('columnsfetcherror', 'local_mondaysync', s($e->getMessage())), 'error');
    echo $OUTPUT->footer();
    exit;
}

if (empty($columns)) {
    echo $OUTPUT->header();
    echo html_writer::tag('p', html_writer::link($boardslisturl, get_string('backtoboards', 'local_mondaysync')));
    echo $OUTPUT->notification(get_string('nocolumns', 'local_mondaysync'), 'info');
    echo $OUTPUT->footer();
    exit;
}

$rawmappings = \local_mondaysync\mapping_util::parse_raw((string)$board->fieldmappings);

$customdata = [
    'columns' => $columns,
    'matchingcolumnid' => $board->matchingcolumnid,
    'rawmappings' => $rawmappings,
    'customfields' => \local_mondaysync\mapping_util::get_custom_fields(),
    'suspendedlabelyes' => $board->suspendedlabelyes ?? '',
    'suspendedlabelno' => $board->suspendedlabelno ?? '',
    'createusers' => $board->createusers ?? 0,
    'createtriggercolumnid' => $board->createtriggercolumnid ?? '',
    'createfirstnamecolumnid' => $board->createfirstnamecolumnid ?? '',
    'createlastnamecolumnid' => $board->createlastnamecolumnid ?? '',
    'createemailcolumnid' => $board->createemailcolumnid ?? '',
    'createusernamecolumnid' => $board->createusernamecolumnid ?? '',
    'createdefaultauth' => $board->createdefaultauth ?? '',
    'createemailpassword' => $board->createemailpassword ?? 0,
    'enabledauthplugins' => get_enabled_auth_plugins(),
];

$mform = new \local_mondaysync\form\mapping_form(new moodle_url('/local/mondaysync/mapping.php', ['id' => $id]), $customdata);

if ($mform->is_cancelled()) {
    redirect($boardslisturl);
} else if ($data = $mform->get_data()) {
    $mappings = [];
    foreach ($columns as $col) {
        $targetelname = 'target_' . $col['id'];
        $directionelname = 'direction_' . $col['id'];
        if (!empty($data->$targetelname)) {
            [$type, $field] = explode(':', $data->$targetelname, 2);
            $direction = $data->$directionelname ?? \local_mondaysync\mapping_util::DEFAULT_DIRECTION;
            $mappings[$col['id']] = ['type' => $type, 'field' => $field, 'direction' => $direction];
        }
    }

    // Preserve mappings whose Monday column no longer exists on the live
    // board, unless the admin explicitly ticked "remove" for that one -
    // they're not shown as an editable row (there's no live column to
    // attach one to), just a "keep or remove" choice in their own section.
    $livecolumnids = array_column($columns, 'id');
    $orphanedbycolumn = array_diff_key($rawmappings, array_flip($livecolumnids));
    foreach ($orphanedbycolumn as $columnid => $mapping) {
        $removeelname = 'orphan_remove_' . $columnid;
        if (empty($data->$removeelname)) {
            $mappings[$columnid] = $mapping;
        }
    }

    // Note: deliberately NOT round-tripping through mapping_util::parse()
    // here (unlike earlier versions) - that would silently strip any
    // orphaned-but-kept mapping right back out again, defeating the whole
    // point of surfacing them instead of letting them vanish without a
    // trace. The form's own validation() is the authoritative check here:
    // it only allows a submitted target to be either a currently-valid
    // whitelisted option, or the exact pre-existing orphaned value for
    // that specific column - never a new, unauthorised one.
    $board->matchingcolumnid = $data->matchingcolumnid;
    $board->fieldmappings = \local_mondaysync\mapping_util::build($mappings);
    $board->suspendedlabelyes = trim($data->suspendedlabelyes);
    $board->suspendedlabelno = trim($data->suspendedlabelno);
    $board->createusers = !empty($data->createusers) ? 1 : 0;
    $board->createtriggercolumnid = trim((string)$data->createtriggercolumnid);
    $board->createfirstnamecolumnid = trim((string)$data->createfirstnamecolumnid);
    $board->createlastnamecolumnid = trim((string)$data->createlastnamecolumnid);
    $board->createemailcolumnid = trim((string)$data->createemailcolumnid);
    $board->createusernamecolumnid = trim((string)$data->createusernamecolumnid);
    $board->createdefaultauth = trim((string)$data->createdefaultauth);
    $board->createemailpassword = !empty($data->createemailpassword) ? 1 : 0;
    \local_mondaysync\board_repository::save($board);

    redirect($boardslisturl, get_string('mappingsaved', 'local_mondaysync'), null, \core\output\notification::NOTIFY_SUCCESS);
} else {
    echo $OUTPUT->header();
    echo html_writer::tag('p', html_writer::link($boardslisturl, get_string('backtoboards', 'local_mondaysync')));
    $mform->display();
    echo $OUTPUT->footer();
}