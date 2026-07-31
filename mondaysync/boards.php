<?php
require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('local_mondaysync_boards');

global $DB, $OUTPUT, $PAGE;

$action = optional_param('action', '', PARAM_ALPHA);
$id = optional_param('id', 0, PARAM_INT);

$listurl = new moodle_url('/local/mondaysync/boards.php');

$PAGE->set_title(get_string('boardsheading', 'local_mondaysync'));
$PAGE->set_heading(get_string('boardsheading', 'local_mondaysync'));

// --- Handle delete (with confirmation) ---
if ($action === 'delete' && $id) {
    try {
        $board = \local_mondaysync\board_repository::get($id);
    } catch (\dml_missing_record_exception $e) {
        redirect($listurl, get_string('boardnotfound', 'local_mondaysync'), null, \core\output\notification::NOTIFY_ERROR);
    }
    $confirm = optional_param('confirm', 0, PARAM_BOOL);

    if ($confirm && confirm_sesskey()) {
        \local_mondaysync\board_repository::delete($id);
        redirect($listurl, get_string('boarddeleted', 'local_mondaysync', $board->name), null,
            \core\output\notification::NOTIFY_SUCCESS);
    }

    echo $OUTPUT->header();
    echo $OUTPUT->confirm(
        get_string('boarddeleteconfirm', 'local_mondaysync', $board->name),
        new moodle_url('/local/mondaysync/boards.php', ['action' => 'delete', 'id' => $id, 'confirm' => 1, 'sesskey' => sesskey()]),
        $listurl
    );
    echo $OUTPUT->footer();
    exit;
}

// --- Handle enable/disable toggle ---
if ($action === 'enable' && $id && confirm_sesskey()) {
    \local_mondaysync\board_repository::set_enabled($id, true);
    redirect($listurl);
}
if ($action === 'disable' && $id && confirm_sesskey()) {
    \local_mondaysync\board_repository::set_enabled($id, false);
    redirect($listurl);
}

// --- Handle add/edit form ---
if ($action === 'add' || $action === 'edit') {
    if ($id) {
        try {
            $board = \local_mondaysync\board_repository::get($id);
        } catch (\dml_missing_record_exception $e) {
            redirect($listurl, get_string('boardnotfound', 'local_mondaysync'), null, \core\output\notification::NOTIFY_ERROR);
        }
    } else {
        $board = new stdClass();
    }

    // IMPORTANT: pass the actual URL (including the query string) as this
    // form's action explicitly. Left to its default, moodleform submits
    // back to the *current URL with its query string stripped* - which
    // silently drops action=add / action=edit&id=X, so boards.php's own
    // routing above never recognises the POST-back as an add/edit
    // submission and just falls through to the plain board list. That's
    // what was causing saves to appear to do nothing at all.
    $formurl = new moodle_url('/local/mondaysync/boards.php', array_filter(['action' => $action, 'id' => $id]));
    $mform = new \local_mondaysync\form\board_form($formurl);
    $mform->set_data($board);

    if ($mform->is_cancelled()) {
        redirect($listurl);
    } else if ($data = $mform->get_data()) {
        $parsed = \local_mondaysync\board_repository::parse_board_input($data->boardid);
        $data->boardid = $parsed['boardid'];

        // If they pasted a full URL and we don't have an account subdomain
        // yet, learn it automatically rather than making them set it separately.
        if ($parsed['subdomain'] && trim((string) get_config('local_mondaysync', 'accountsubdomain')) === '') {
            set_config('accountsubdomain', $parsed['subdomain'], 'local_mondaysync');
        }

        try {
            \local_mondaysync\board_repository::save($data);
        } catch (\Throwable $e) {
            echo $OUTPUT->header();
            echo $OUTPUT->notification(get_string('boardsaveerror', 'local_mondaysync', s($e->getMessage())), 'error');
            $mform->display();
            echo $OUTPUT->footer();
            exit;
        }

        redirect($listurl, get_string('boardsaved', 'local_mondaysync'), null,
            \core\output\notification::NOTIFY_SUCCESS);
    }

    echo $OUTPUT->header();
    echo $OUTPUT->heading($id ? get_string('editboard', 'local_mondaysync') : get_string('addboard', 'local_mondaysync'));
    $mform->display();
    echo $OUTPUT->footer();
    exit;
}

// --- Default: list boards ---
echo $OUTPUT->header();

echo html_writer::tag('p', get_string('boardsintro', 'local_mondaysync'));

$subdomain = trim((string) get_config('local_mondaysync', 'accountsubdomain'));
if ($subdomain === '') {
    echo $OUTPUT->notification(
        get_string('subdomainmissing', 'local_mondaysync', (new moodle_url('/admin/settings.php', ['section' => 'local_mondaysync']))->out()),
        'info'
    );
}

$boards = \local_mondaysync\board_repository::get_all();

if (empty($boards)) {
    echo $OUTPUT->notification(get_string('noboards', 'local_mondaysync'), 'info');
} else {
    $table = new html_table();
    $table->head = [
        get_string('boardname', 'local_mondaysync'),
        get_string('boardid', 'local_mondaysync'),
        get_string('col_status', 'local_mondaysync'),
        get_string('mappingsconfigured', 'local_mondaysync'),
        '',
    ];

    foreach ($boards as $board) {
        $mappedcount = count(\local_mondaysync\mapping_util::parse((string)$board->fieldmappings));

        $orphanwarning = \local_mondaysync\board_repository::get_orphan_warning($board);

        $status = $board->enabled
            ? html_writer::span(get_string('enabled', 'local_mondaysync'), 'badge badge-success')
            : html_writer::span(get_string('disabled', 'local_mondaysync'), 'badge badge-secondary');

        $toggleaction = $board->enabled ? 'disable' : 'enable';
        $togglelabel = $board->enabled ? get_string('disable') : get_string('enable');

        $links = [];

        $goto = \local_mondaysync\board_repository::board_url($board->boardid);
        if ($goto) {
            $links[] = html_writer::link($goto, get_string('gotoboard', 'local_mondaysync'), ['target' => '_blank']);
        }

        $links[] = html_writer::link(
            new moodle_url('/local/mondaysync/mapping.php', ['id' => $board->id]),
            get_string('configuremapping', 'local_mondaysync')
        );
        $links[] = html_writer::link(
            new moodle_url('/local/mondaysync/boards.php', ['action' => 'edit', 'id' => $board->id]),
            get_string('edit')
        );
        $links[] = html_writer::link(
            new moodle_url('/local/mondaysync/boards.php', ['action' => $toggleaction, 'id' => $board->id, 'sesskey' => sesskey()]),
            $togglelabel
        );
        $links[] = html_writer::link(
            new moodle_url('/local/mondaysync/boards.php', ['action' => 'delete', 'id' => $board->id]),
            get_string('delete')
        );

        $namecell = s($board->name);
        if ($orphanwarning) {
            $namecell .= ' ' . html_writer::span('⚠', 'text-danger', ['title' => $orphanwarning]);
        }

        $table->data[] = [
            $namecell,
            s($board->boardid),
            $status,
            $board->matchingcolumnid ? $mappedcount : get_string('notconfigured', 'local_mondaysync'),
            implode(' | ', $links),
        ];
    }

    echo html_writer::table($table);
}

echo $OUTPUT->single_button(
    new moodle_url('/local/mondaysync/boards.php', ['action' => 'add']),
    get_string('addboard', 'local_mondaysync')
);

echo $OUTPUT->footer();