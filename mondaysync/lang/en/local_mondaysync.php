<?php
defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Monday.com Sync';

// Settings page.
$string['settingsheading'] = 'Monday.com Sync settings';
$string['settingsheading_desc'] = 'Configure your Monday.com account connection here: API token and API version. Once saved, go to the "Connected Boards" page (Local plugins) to add one or more boards to sync, and configure each one\'s matching column and field mappings from live dropdowns.';

$string['apitoken'] = 'Monday.com API token';
$string['apitoken_desc'] = 'Personal or app API token from Monday.com (Admin > API in your Monday account). This is sent as the Authorization header on every request, and is shared across all connected boards.';

$string['apiversion'] = 'Monday.com API version';
$string['apiversion_desc'] = 'The Monday.com API version string to request, e.g. 2026-01. Check developer.monday.com for the current version if updates start failing.';

$string['accountsubdomain'] = 'Monday.com account subdomain';
$string['accountsubdomain_desc'] = 'Your team\'s subdomain, e.g. <code>coic-company</code> for <code>coic-company.monday.com</code>. Used to build "Go to board" links on the Connected Boards page. Filled in automatically the first time you add or edit a board using its full URL rather than just its ID.';

$string['logretentiondays'] = 'Sync log/cache retention (days)';
$string['logretentiondays_desc'] = 'Sync log entries and cached column values older than this are automatically deleted at the end of each sync run. The log holds personal data (old/new profile field values), so keeping this reasonably short is good practice as well as good housekeeping. Set to 0 to keep everything indefinitely (not recommended).';

$string['boardid'] = 'Monday.com board ID';

$string['boardidorurl'] = 'Board ID or URL';
$string['boardidorurl_desc'] = 'Paste either the board\'s full URL (e.g. <code>https://coic-company.monday.com/boards/18423283636</code>) or just the numeric ID on its own. Pasting the full URL also teaches the plugin your account\'s subdomain automatically, so "Go to board" links work without any extra setup.';

$string['matchingcolumnid'] = 'Matching column ID';
$string['matchingcolumnid_desc'] = 'The Monday.com column ID (not the display title) on the board that holds the Moodle "ID Number" value for each user. Used to match a board row to a Moodle account.';
$string['matchingcolumnmissing'] = 'The currently-saved matching column ("{$a}") no longer exists on this board. Syncing is paused for this board until you choose a new one below.';

// Task.
$string['synctask'] = 'Sync Monday.com boards to Moodle profile fields';

// Connected Boards page.
$string['boardsheading'] = 'Connected Boards';
$string['boardsintro'] = 'Each connected board is polled on the schedule below and synced independently, using its own matching column and field mappings. All boards share the API token and version set on the settings page.';
$string['noboards'] = 'No boards connected yet. Add one to get started.';
$string['boardname'] = 'Board name';
$string['boardname_desc'] = 'A label to help you tell boards apart - not sent to Monday.com.';
$string['boardenabled'] = 'Enabled';
$string['boardenabled_desc'] = 'Disabled boards are skipped by the scheduled task without being deleted, so you can pause a board without losing its configuration.';
$string['addboard'] = 'Add a board';
$string['editboard'] = 'Edit board';
$string['saveboard'] = 'Save board';
$string['boardsaved'] = 'Board saved.';
$string['boarddeleted'] = 'Board "{$a}" deleted.';
$string['boarddeleteconfirm'] = 'Delete the board "{$a}"? This removes its connection and mapping configuration. Past sync log entries for it are kept.';
$string['enabled'] = 'Enabled';
$string['disabled'] = 'Disabled';
$string['configuremapping'] = 'Configure mapping';
$string['mappingsconfigured'] = 'Mappings configured';
$string['notconfigured'] = 'Not configured yet';
$string['warningmatchingcolumnmissing'] = 'This board\'s matching column no longer exists on Monday.com - syncing is paused. Open "Configure mapping" to fix it.';
$string['warningorphanedmappings'] = '{$a} mapping(s) on this board point at a deleted Moodle field or Monday.com column. Open "Configure mapping" to review.';
$string['warningcreationnotconfigured'] = 'User creation is enabled on this board but not fully configured (trigger column, name/email/username columns, and default auth are all required). Open "Configure mapping" to finish setting it up.';
$string['warningcreationcolumnmissing'] = 'A column used for user creation on this board no longer exists on Monday.com. Open "Configure mapping" to fix it.';
$string['backtoboards'] = '‹ Back to Connected Boards';
$string['migratedboardname'] = 'Migrated board';
$string['gotoboard'] = 'Go to board ↗';
$string['subdomainmissing'] = 'Set your Monday.com account subdomain on the <a href="{$a}">settings page</a> to enable "Go to board" links here - or just add/edit a board using its full URL and it\'ll be picked up automatically.';
$string['boardnotfound'] = 'That board could not be found - it may have been deleted.';
$string['invalidboardid'] = 'That doesn\'t look like a valid board ID or URL - it should be a numeric ID, or a full URL like https://yourteam.monday.com/boards/1234567890.';
$string['boardidduplicate'] = 'Another connected board already uses this board ID.';
$string['boardsaveerror'] = 'Could not save this board: {$a}';

// Log viewer.
$string['logviewer'] = 'Monday.com Sync log';
$string['nologs'] = 'No sync activity recorded yet. The scheduled task may not have run yet — check Site administration > Server > Scheduled tasks.';
$string['col_time'] = 'Time';
$string['col_itemid'] = 'Monday item ID';
$string['col_user'] = 'Moodle user';
$string['col_field'] = 'Field';
$string['col_oldvalue'] = 'Old value';
$string['col_newvalue'] = 'New value';
$string['col_status'] = 'Status';
$string['col_message'] = 'Message';

// Shared errors used by both the mapping wizard and the log/columns fetch.
$string['columnsnotconfigured'] = 'Set the API token on the settings page first, then come back here.';
$string['columnsfetcherror'] = 'Could not fetch columns from Monday.com: {$a}';
$string['nocolumns'] = 'Monday.com returned no columns for this board ID - double check it on the board\'s edit page.';
$string['errornoboardaccess'] = 'Monday.com returned no board with ID {$a}. Double-check the board ID, and confirm the Monday.com account that owns the API token is actually a member of this board — a personal API token can only see boards its owner has access to.';

// Mapping wizard.
$string['mappingwizard'] = 'Monday.com column mapping wizard';
$string['matchingheader'] = 'User matching';
$string['choosecolumn'] = 'Choose a column…';
$string['mappingheader'] = 'Field mappings';
$string['mappingintro'] = 'For each column on the board, choose which Moodle field (if any) it should be kept in sync with, and in which direction. Columns left as "Do not sync" are ignored. Date columns mapped to a "Date/time" custom profile field are converted automatically - no separate setting needed.';
$string['direction'] = 'Direction';
$string['directionintro'] = 'Monday → Moodle (default): only Monday.com can change this field - edits made directly on the Moodle profile are overwritten on the next sync. Moodle → Monday: only Moodle can change this column. Both ways: either side can change it, and whichever side changed since the last sync gets pushed to the other. If it genuinely changes on <em>both</em> sides between syncs (a real conflict, not just one side catching up), <strong>Monday.com\'s value is kept</strong> - there\'s no reliable way to tell which edit happened more recently (Moodle doesn\'t record a per-field edit time), so this is a deliberate, fixed rule rather than a guess.';
$string['directiontomoodle'] = 'Monday → Moodle';
$string['directiontomonday'] = 'Moodle → Monday';
$string['directionboth'] = 'Both ways';
$string['only'] = 'only';

// Advanced fields (core account properties, not ordinary profile data).
$string['advancedfieldoption'] = 'Advanced field: {$a}';
$string['advancedfield_auth'] = 'Authentication method (auth)';
$string['advancedfield_suspended'] = 'Account suspended (suspended)';
$string['advancedfield_lastlogin'] = 'Last login';
$string['advancedwarning'] = '"Advanced" fields below control login access directly, not ordinary profile data - used well (e.g. suspending an account when Monday marks someone as departed) they\'re powerful, but a bad or unexpected value here can genuinely lock someone out. For <strong>Authentication method</strong>, the Monday column\'s value must exactly match an auth plugin shortname enabled on this site (e.g. "manual", "nologin") - anything else effectively breaks that account\'s login. For <strong>Account suspended</strong>, map to a column whose value is exactly "0" or "1". Double-check both before turning on a live sync.';
$string['neverloggedin'] = 'Never logged in';
$string['directionrestricted'] = '{$a->field} can only be synced {$a->directions}.';

// Orphaned mappings (deleted Moodle field or Monday column).
$string['orphanedfieldoption'] = '⚠ Deleted: {$a} (kept as configured - pick a new target, or "Do not sync" to clear it)';
$string['deletedfielddesc'] = 'a deleted field ({$a})';
$string['orphanedmappingsheader'] = 'Mappings for columns no longer on this board';
$string['orphanedmappingsintro'] = 'These were mapped to a Monday.com column that no longer exists on this board (deleted, or the column ID changed). They\'re inert - nothing syncs for them - until you either remove them here or the column comes back.';
$string['orphanedcolumnlabel'] = 'Column ID <code>{$a->columnid}</code> (was mapped to: {$a->target})';
$string['removethismapping'] = 'Remove this mapping';
$string['donotsync'] = '— Do not sync —';
$string['standardfieldoption'] = 'Standard field: {$a}';
$string['customfieldoption'] = 'Custom field: {$a->name} ({$a->shortname}, {$a->datatype})';
$string['savemapping'] = 'Save mapping';
$string['mappingsaved'] = 'Mapping saved.';
$string['invalidselection'] = 'That selection isn\'t one of the available options - please choose from the dropdown.';

// Suspended field labels.
$string['suspendedlabelsheader'] = 'Suspended field labels';
$string['suspendedlabelsintro'] = 'If you map a column to the Advanced "Account suspended" field with a Moodle → Monday (or Both ways) direction, these are the exact labels written to Monday.com. On the way in from Monday, several common phrasings are understood automatically regardless of what\'s set here - Yes/No, 1/0, True/False, Suspended/Not Suspended - so Ops can label the column however reads naturally.';
$string['suspendedlabelyes'] = 'Label written when suspended';
$string['suspendedlabelno'] = 'Label written when not suspended';

// User creation.
$string['creationheader'] = 'User creation';
$string['creationintro'] = 'Optionally, this board can create new Moodle accounts for rows with no existing match. A Status column on the board acts as the trigger - set it to "Create user" and the account is created on the next sync, flipping to "User created" on success or "Error" on failure. There\'s no delete option here by design - a human stays the final decision-maker for anything irreversible.';
$string['createusersenable'] = 'Allow this board to create new Moodle accounts';
$string['createtriggercolumn'] = 'Trigger column';
$string['createtriggercolumn_desc'] = 'Must be a Status-type column (only Status columns are offered here). Ops sets it to exactly "Create user" to request an account; the plugin writes back "User created" or "Error".';
$string['create_firstname_column'] = 'First name column';
$string['create_lastname_column'] = 'Last name column';
$string['create_email_column'] = 'Email column';
$string['create_username_column'] = 'Username column';
$string['chooseauth'] = 'Choose an authentication method…';
$string['createdefaultauth'] = 'Default authentication method for new accounts';
$string['createdefaultauth_desc'] = 'Only auth methods currently enabled on this site are listed. This is just the starting value for a brand-new account - if you also map a column to the Advanced "auth" field, later changes on Monday will update it normally from that point on, the same as for any other account.';
$string['createemailpassword'] = 'Email new manual-auth accounts their password';
$string['createemailpassword_desc'] = 'Moodle generates a password and emails it to the account directly (the same mechanism used by Moodle\'s own bulk user upload tool) - not relevant for SSO-based methods like SAML2, which don\'t use a Moodle-stored password at all. This applies whenever an account\'s authentication method becomes "Manual accounts" - at creation if that\'s the default above, or later if you also map a column to the Advanced "auth" field and it changes to manual (e.g. a staged "created as nologin, switched to manual once ready" workflow). Sent once per account, however many times it moves away from and back to manual afterward.';

// Moodle Workplace multi-tenancy (only shown if that plugin is installed).
$string['tenancyheader'] = 'Tenant (Moodle Workplace)';
$string['tenancyintro'] = 'This site has Moodle Workplace\'s multi-tenancy feature installed. Optionally, assign new accounts to a specific tenant as part of creation - before anything else happens, including the welcome email above, so its content reflects the correct tenant from the start.';
$string['createtenantcolumn'] = 'Tenant name column (optional)';
$string['createtenantcolumn_desc'] = 'A Monday.com column whose text should exactly match one of this site\'s tenant names (case doesn\'t matter). Leave as "Do not sync" to always use the default tenant below instead.';
$string['notenantdefault'] = '— No default (use Workplace\'s own default tenant) —';
$string['createdefaulttenant'] = 'Default tenant';
$string['createdefaulttenant_desc'] = 'Used whenever the tenant column above is blank, not configured, or doesn\'t match a real tenant name. Since boards here typically correspond to one tenant each, this is usually the main mechanism - the per-row column above is more for the occasional exception.';

// Errors.
$string['errormondayhttp'] = 'Monday.com API returned an unexpected HTTP status: {$a}';
$string['errormondayresponse'] = 'Could not decode Monday.com API response: {$a}';
$string['errormondaygraphql'] = 'Monday.com API returned GraphQL errors: {$a}';
$string['errornotconfigured'] = 'local_mondaysync is not fully configured (missing API token). Skipping sync.';
$string['errorbaddate'] = 'Could not parse "{$a}" as a date - value left unchanged.';

// Privacy.
$string['privacy:metadata:local_mondaysync_log'] = 'A record of profile field changes this plugin has applied to a user, and of any new Moodle account it has created from a Monday.com board row, kept for audit and debugging.';
$string['privacy:metadata:local_mondaysync_log:userid'] = 'The Moodle user whose profile field was changed, or who was created.';
$string['privacy:metadata:local_mondaysync_log:field'] = 'Which profile field was changed (not applicable to account-creation entries).';
$string['privacy:metadata:local_mondaysync_log:oldvalue'] = 'The field\'s value before this change.';
$string['privacy:metadata:local_mondaysync_log:newvalue'] = 'The field\'s value after this change.';
$string['privacy:metadata:local_mondaysync_log:timecreated'] = 'When the change (or account creation) was made.';
$string['privacy:metadata:local_mondaysync_manual_email'] = 'A permanent record of whether a manual-auth account has already been sent its new-password welcome email, kept indefinitely (unlike the sync log) so the email is never sent twice.';
$string['privacy:metadata:local_mondaysync_manual_email:userid'] = 'The Moodle user who was, or will be, sent the welcome email.';
$string['privacy:metadata:local_mondaysync_manual_email:timesent'] = 'When the welcome email was sent.';
$string['privacy:metadata:mondaycom'] = 'To match a Monday.com board row to a Moodle account, this plugin sends the user\'s ID Number to Monday.com. For any field mapped with a "Moodle → Monday" or "Both ways" direction, that field\'s current value is also sent to Monday.com to keep the corresponding board column in sync. In the other direction, if a board is configured to create new Moodle accounts, this plugin reads personal data (first name, last name, email address, and the value used as username) from that board\'s columns to create the account.';
$string['privacy:metadata:mondaycom:idnumber'] = 'The Moodle user\'s ID Number profile field.';
$string['privacy:metadata:mondaycom:fieldvalue'] = 'The current value of any profile field mapped with a Moodle → Monday, or Both ways, sync direction.';
$string['privacy:metadata:mondaycom:newaccountdata'] = 'First name, last name, email address, and username read from Monday.com to create a new Moodle account, where a board is configured to do so.';
$string['privacy:unknownboard'] = '(board since deleted)';