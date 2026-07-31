<?php
defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {

    $settings = new admin_settingpage('local_mondaysync', get_string('pluginname', 'local_mondaysync'));
    $ADMIN->add('localplugins', $settings);

    $settings->add(new admin_setting_heading(
        'local_mondaysync/settingsheading',
        get_string('settingsheading', 'local_mondaysync'),
        get_string('settingsheading_desc', 'local_mondaysync')
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'local_mondaysync/apitoken',
        get_string('apitoken', 'local_mondaysync'),
        get_string('apitoken_desc', 'local_mondaysync'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'local_mondaysync/apiversion',
        get_string('apiversion', 'local_mondaysync'),
        get_string('apiversion_desc', 'local_mondaysync'),
        '2026-01',
        PARAM_RAW_TRIMMED
    ));

    $settings->add(new admin_setting_configtext(
        'local_mondaysync/accountsubdomain',
        get_string('accountsubdomain', 'local_mondaysync'),
        get_string('accountsubdomain_desc', 'local_mondaysync'),
        '',
        PARAM_ALPHANUMEXT
    ));

    $settings->add(new admin_setting_configtext(
        'local_mondaysync/logretentiondays',
        get_string('logretentiondays', 'local_mondaysync'),
        get_string('logretentiondays_desc', 'local_mondaysync'),
        '90',
        PARAM_INT
    ));

    // Individual boards (board ID, matching column, field mappings) are
    // managed on the Connected Boards page, not here.

    // Link through to the Connected Boards page - add/edit/enable/disable
    // boards, and reach each board's mapping wizard from there.
    $ADMIN->add('localplugins', new admin_externalpage(
        'local_mondaysync_boards',
        get_string('boardsheading', 'local_mondaysync'),
        new moodle_url('/local/mondaysync/boards.php'),
        'moodle/site:config'
    ));

    // Link through to a simple log viewer.
    $ADMIN->add('localplugins', new admin_externalpage(
        'local_mondaysync_log',
        get_string('logviewer', 'local_mondaysync'),
        new moodle_url('/local/mondaysync/index.php'),
        'moodle/site:config'
    ));
}
