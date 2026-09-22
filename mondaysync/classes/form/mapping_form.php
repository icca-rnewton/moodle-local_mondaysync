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
 * Mapping wizard form: matching column, field mappings and direction, orphan handling, user creation, and Workplace tenancy configuration.
 *
 * @package    local_mondaysync
 * @copyright  2026 Inns of Court College of Advocacy (Part of COIC)
 * @license    http://www.gnu.org/licenses/GPL-3.0 GNU GPL v3 or later
 */

namespace local_mondaysync\form;

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->libdir . '/formslib.php');

/**
 * Lets the admin pick the matching column and, for every Monday.com column
 * on the board, which Moodle field (if any) it should sync to and in which
 * direction - all via dropdowns populated from a live column fetch and the
 * site's actual profile fields, rather than hand-typing the config string.
 *
 * Also surfaces "orphaned" mappings rather than silently dropping them, and
 * (further down the page) holds this board's suspended-field labels and
 * optional user-creation configuration.
 */
class mapping_form extends \moodleform {

    /** @var string[] Column IDs actually present on this board, per the live fetch. */
    protected $validcolumnids = [];

    /** @var string[] Column IDs that are a Status-type column - the only kind allowed as the creation trigger. */
    protected $validstatuscolumnids = [];

    /** @var string[] Keys of the target-field dropdown actually offered, shared across all columns. */
    protected $validtargetoptions = [];

    /** @var array [monday_column_id => the one orphaned value that column's dropdown is allowed to keep, if any] */
    protected $allowedorphanvalues = [];

    /** @var string[] Enabled auth plugin shortnames on this site. */
    protected $validauthplugins = [];

    /** @var string[] Valid Moodle Workplace tenant IDs (as strings), if tool_tenant is installed. */
    protected $validtenantids = [];

    public function definition() {
        $mform = $this->_form;
        $columns = $this->_customdata['columns'];
        $matchingcolumnid = $this->_customdata['matchingcolumnid'];
        $rawmappings = $this->_customdata['rawmappings'];
        $customfields = $this->_customdata['customfields'];

        $livecolumnids = array_column($columns, 'id');

        // --- Matching column ---
        $mform->addElement('header', 'matchingheader', get_string('matchingheader', 'local_mondaysync'));

        $columnoptions = ['' => get_string('choosecolumn', 'local_mondaysync')];
        foreach ($columns as $col) {
            $columnoptions[$col['id']] = $this->column_label($col);
            $this->validcolumnids[] = $col['id'];
            if ($col['type'] === 'status') {
                $this->validstatuscolumnids[] = $col['id'];
            }
        }

        $mform->addElement('select', 'matchingcolumnid', get_string('matchingcolumnid', 'local_mondaysync'), $columnoptions);
        $mform->setType('matchingcolumnid', PARAM_RAW);
        $mform->addRule('matchingcolumnid', get_string('required'), 'required', null, 'server');
        if ($matchingcolumnid) {
            $mform->setDefault('matchingcolumnid', $matchingcolumnid);
        }
        $mform->addElement('static', 'matchingcolumnid_desc', '', get_string('matchingcolumnid_desc', 'local_mondaysync'));

        if ($matchingcolumnid && !in_array($matchingcolumnid, $livecolumnids, true)) {
            $mform->addElement('static', 'matchingcolumnid_missing', '',
                $this->warning(get_string('matchingcolumnmissing', 'local_mondaysync', $matchingcolumnid)));
        }

        // --- Per-column field mapping ---
        $mform->addElement('header', 'mappingheader', get_string('mappingheader', 'local_mondaysync'));
        $mform->addElement('static', 'mappingintro', '', get_string('mappingintro', 'local_mondaysync'));
        $mform->addElement('static', 'directionintro', get_string('direction', 'local_mondaysync'),
            get_string('directionintro', 'local_mondaysync'));

        // Build the grouped option structure shared by every column's "sync
        // to" dropdown - Standard fields, Advanced fields, then one group
        // per custom-profile-field category - filtered to only the fields
        // the site's "Fields available for mapping" setting actually
        // enables (mapping_util::get_enabled_field_keys()), so a site with
        // many custom fields doesn't force every column's dropdown to show
        // all of them regardless of relevance.
        //
        // 2026 redesign (dropdown grouping): previously every option
        // carried a "Standard field: "/"Custom field: "/"Advanced field: "
        // prefix, which was the main source of the "wall of text" this
        // redesign addresses - with real headers doing that job instead,
        // repeating it on every single option became redundant. Genuine
        // <optgroup> isn't achievable here without breaking form
        // submission entirely (confirmed against core source:
        // moodleform::get_data() only ever processes genuinely registered
        // elements, so a hand-built raw <select> with real <optgroup>
        // tags would silently never have its value received on save) -
        // disabled, visually-indented header options are the safe
        // equivalent that stays a real, working form element.
        $enabledkeys = \local_mondaysync\mapping_util::get_enabled_field_keys();

        $standardchoices = [];
        foreach (\local_mondaysync\mapping_util::SAFE_STANDARD_FIELDS as $fieldname) {
            if (in_array('std:' . $fieldname, $enabledkeys, true)) {
                $standardchoices['standard:' . $fieldname] = $this->standard_field_label($fieldname);
            }
        }

        $advancedchoices = [];
        foreach (\local_mondaysync\mapping_util::ADVANCED_FIELDS as $fieldname => $alloweddirections) {
            if (in_array('adv:' . $fieldname, $enabledkeys, true)) {
                $advancedchoices['advanced:' . $fieldname] = $this->advanced_field_label($fieldname, $alloweddirections);
            }
        }

        $targetgroups = [];
        if (!empty($standardchoices)) {
            $targetgroups[] = ['header' => get_string('fieldpicker_standard', 'local_mondaysync'), 'choices' => $standardchoices];
        }
        if (!empty($advancedchoices)) {
            $targetgroups[] = ['header' => get_string('fieldpicker_advanced', 'local_mondaysync'), 'choices' => $advancedchoices];
        }
        foreach (\local_mondaysync\mapping_util::get_custom_fields_by_category() as $categoryname => $fields) {
            $choices = [];
            foreach ($fields as $shortname => $info) {
                if (!in_array('profile:' . $shortname, $enabledkeys, true)) {
                    continue;
                }
                $type = ($info['datatype'] === 'datetime') ? 'date' : 'custom';
                $choices[$type . ':' . $shortname] = $info['name'] . ' (' . $shortname . ')';
            }
            if (!empty($choices)) {
                $targetgroups[] = ['header' => $categoryname, 'choices' => $choices];
            }
        }

        // Flat list of every real (non-header) value across every group,
        // for validation() to check submissions against.
        $this->validtargetoptions = [''];
        foreach ($targetgroups as $group) {
            $this->validtargetoptions = array_merge($this->validtargetoptions, array_keys($group['choices']));
        }

        $mform->addElement('static', 'advancedwarning', '', $this->warning(get_string('advancedwarning', 'local_mondaysync')));

        $mform->addElement('static', 'columnfilter', '', $this->column_picker_html($columns, $rawmappings));

        global $PAGE;
        $PAGE->requires->js_amd_inline($this->column_picker_js($columns, $rawmappings));
        $PAGE->requires->js_amd_inline($this->direction_lock_js($columns));

        // Shared by every column's direction dropdown.
        $directionoptions = [
            'toMoodle' => get_string('directiontomoodle', 'local_mondaysync'),
            'toMonday' => get_string('directiontomonday', 'local_mondaysync'),
            'both' => get_string('directionboth', 'local_mondaysync'),
        ];

        foreach ($columns as $col) {
            $targetelname = 'target_' . $col['id'];
            $directionelname = 'direction_' . $col['id'];
            $label = $this->column_label($col);

            // This column may need one extra "orphaned" option, if its
            // saved target isn't currently offered (no longer exists, or
            // has been disabled via the site's field picker since it was
            // set) - grouped under its own header, same visual treatment
            // as every other group, rather than a bare option with no
            // context.
            $orphangroup = null;
            $saved = $rawmappings[$col['id']] ?? null;
            if ($saved !== null) {
                $current = $saved['type'] . ':' . $saved['field'];
                if (!in_array($current, $this->validtargetoptions, true)) {
                    $desc = \local_mondaysync\mapping_util::describe_mapping_target($saved, $customfields);
                    $orphangroup = ['header' => get_string('orphanedgroupheader', 'local_mondaysync'), 'choices' => [$current => $desc]];
                    $this->allowedorphanvalues[$col['id']] = $current;
                    $this->validtargetoptions[] = $current;
                }
            }

            $targetselect = $mform->createElement('select', $targetelname, '', []);
            $this->populate_grouped_select($targetselect, $targetgroups, $orphangroup);

            $removehtml = '<button type="button" class="btn btn-link btn-sm p-0 ml-2 local-mondaysync-remove-btn"'
                . ' data-colid="' . s($col['id']) . '">' . s(get_string('removemapping', 'local_mondaysync')) . '</button>';

            $group = [
                $targetselect,
                $mform->createElement('select', $directionelname, '', $directionoptions),
                $mform->createElement('advcheckbox', $allowclearelname, '', get_string('allowclear', 'local_mondaysync')),
                $mform->createElement('static', 'remove_' . $col['id'], '', $removehtml),
            ];
            $mform->addGroup($group, 'group_' . $col['id'], $label, ' ', false);
            $mform->setType($targetelname, PARAM_RAW);
            $mform->setType($directionelname, PARAM_RAW);
            $mform->setType($allowclearelname, PARAM_BOOL);
            $mform->setDefault($directionelname, \local_mondaysync\mapping_util::DEFAULT_DIRECTION);
            $mform->setDefault($allowclearelname, 0);

            // Standalone (not a group member) - a plain, simple signal the
            // Remove button sets directly and the save logic checks
            // first, deliberately independent of whatever the grouped
            // <select>'s own submitted value turns out to be. Always
            // starts at 0 on a fresh page load; this is a "did the admin
            // click Remove during this visit" flag, not something ever
            // read back from the saved config.
            $mform->addElement('hidden', 'removed_' . $col['id'], 0);
            $mform->setType('removed_' . $col['id'], PARAM_BOOL);

            if ($saved !== null) {
                // Guaranteed to exist as a real, selectable option by this
                // point - either it was already in $targetgroups, or the
                // orphan handling above added it via $orphangroup.
                $mform->setDefault($targetelname, $saved['type'] . ':' . $saved['field']);
                $mform->setDefault($directionelname, $saved['direction']);
            }
        }

        // --- Orphaned mappings: Monday column no longer exists ---
        $orphanedbycolumn = array_diff_key($rawmappings, array_flip($livecolumnids));

        if (!empty($orphanedbycolumn)) {
            $mform->addElement('header', 'orphanheader', get_string('orphanedmappingsheader', 'local_mondaysync'));
            $mform->addElement('static', 'orphanintro', '', get_string('orphanedmappingsintro', 'local_mondaysync'));

            foreach ($orphanedbycolumn as $columnid => $mapping) {
                $desc = \local_mondaysync\mapping_util::describe_mapping_target($mapping, $customfields);
                $removeelname = 'orphan_remove_' . $columnid;

                $mform->addElement('advcheckbox', $removeelname,
                    get_string('orphanedcolumnlabel', 'local_mondaysync', (object)['columnid' => $columnid, 'target' => $desc]),
                    get_string('removethismapping', 'local_mondaysync'));
                $mform->setDefault($removeelname, 0);
            }
        }

        // --- Suspended field labels ---
        $mform->addElement('header', 'suspendedheader', get_string('suspendedlabelsheader', 'local_mondaysync'));
        $mform->addElement('static', 'suspendedlabelsintro', '', get_string('suspendedlabelsintro', 'local_mondaysync'));

        $mform->addElement('text', 'suspendedlabelyes', get_string('suspendedlabelyes', 'local_mondaysync'), ['size' => 30]);
        $mform->setType('suspendedlabelyes', PARAM_TEXT);
        $mform->setDefault('suspendedlabelyes', $this->_customdata['suspendedlabelyes'] ?: 'Suspended');

        $mform->addElement('text', 'suspendedlabelno', get_string('suspendedlabelno', 'local_mondaysync'), ['size' => 30]);
        $mform->setType('suspendedlabelno', PARAM_TEXT);
        $mform->setDefault('suspendedlabelno', $this->_customdata['suspendedlabelno'] ?: 'Not Suspended');

        // --- User creation ---
        $mform->addElement('header', 'creationheader', get_string('creationheader', 'local_mondaysync'));
        $mform->addElement('static', 'creationintro', '', get_string('creationintro', 'local_mondaysync'));

        $mform->addElement('advcheckbox', 'createusers', '', get_string('createusersenable', 'local_mondaysync'));
        $mform->setDefault('createusers', !empty($this->_customdata['createusers']) ? 1 : 0);

        $statuscolumnoptions = ['' => get_string('choosecolumn', 'local_mondaysync')];
        foreach ($columns as $col) {
            if ($col['type'] === 'status') {
                $statuscolumnoptions[$col['id']] = $this->column_label($col, false);
            }
        }
        $mform->addElement('select', 'createtriggercolumnid', get_string('createtriggercolumn', 'local_mondaysync'), $statuscolumnoptions);
        $mform->setType('createtriggercolumnid', PARAM_RAW);
        $mform->setDefault('createtriggercolumnid', $this->_customdata['createtriggercolumnid'] ?? '');
        $mform->addElement('static', 'createtriggercolumn_desc', '', get_string('createtriggercolumn_desc', 'local_mondaysync'));

        $requiredcolumnfields = [
            'createfirstnamecolumnid' => 'create_firstname_column',
            'createlastnamecolumnid' => 'create_lastname_column',
            'createemailcolumnid' => 'create_email_column',
            'createusernamecolumnid' => 'create_username_column',
        ];
        foreach ($requiredcolumnfields as $elname => $stringkey) {
            $mform->addElement('select', $elname, get_string($stringkey, 'local_mondaysync'), $columnoptions);
            $mform->setType($elname, PARAM_RAW);
            $mform->setDefault($elname, $this->_customdata[$elname] ?? '');
        }

        $authoptions = ['' => get_string('chooseauth', 'local_mondaysync')];
        foreach ($this->_customdata['enabledauthplugins'] as $authname) {
            $authoptions[$authname] = $this->auth_plugin_label($authname);
            $this->validauthplugins[] = $authname;
        }
        $mform->addElement('select', 'createdefaultauth', get_string('createdefaultauth', 'local_mondaysync'), $authoptions);
        $mform->setType('createdefaultauth', PARAM_RAW);
        $mform->setDefault('createdefaultauth', $this->_customdata['createdefaultauth'] ?? '');
        $mform->addElement('static', 'createdefaultauth_desc', '', get_string('createdefaultauth_desc', 'local_mondaysync'));

        $mform->addElement('advcheckbox', 'createemailpassword', '', get_string('createemailpassword', 'local_mondaysync'));
        $mform->setDefault('createemailpassword', !empty($this->_customdata['createemailpassword']) ? 1 : 0);
        $mform->addElement('static', 'createemailpassword_desc', '', get_string('createemailpassword_desc', 'local_mondaysync'));

        // Moodle Workplace multi-tenancy - only shown at all if that plugin
        // is actually installed on this site, so it stays fully invisible
        // (and harmless) on plain Moodle, or after a future move away from
        // Workplace.
        if (class_exists('\tool_tenant\manager')) {
            $mform->addElement('static', 'tenancyintro', get_string('tenancyheader', 'local_mondaysync'),
                get_string('tenancyintro', 'local_mondaysync'));

            $tenantcolumnoptions = ['' => get_string('donotsync', 'local_mondaysync')];
            foreach ($columns as $col) {
                $tenantcolumnoptions[$col['id']] = $this->column_label($col);
            }
            $mform->addElement('select', 'createtenantcolumnid', get_string('createtenantcolumn', 'local_mondaysync'), $tenantcolumnoptions);
            $mform->setType('createtenantcolumnid', PARAM_RAW);
            $mform->setDefault('createtenantcolumnid', $this->_customdata['createtenantcolumnid'] ?? '');
            $mform->addElement('static', 'createtenantcolumn_desc', '', get_string('createtenantcolumn_desc', 'local_mondaysync'));

            $tenantoptions = ['' => get_string('notenantdefault', 'local_mondaysync')];
            foreach (\tool_tenant\tenancy::get_tenants() as $tenant) {
                $label = $tenant->name;
                if (!empty($tenant->idnumber)) {
                    $label .= ' (' . $tenant->idnumber . ')';
                }
                $tenantoptions[$tenant->id] = s($label);
                $this->validtenantids[] = (string)$tenant->id;
            }
            $mform->addElement('select', 'createdefaulttenantid', get_string('createdefaulttenant', 'local_mondaysync'), $tenantoptions);
            $mform->setType('createdefaulttenantid', PARAM_RAW);
            $mform->setDefault('createdefaulttenantid', $this->_customdata['createdefaulttenantid'] ?? '');
            $mform->addElement('static', 'createdefaulttenant_desc', '', get_string('createdefaulttenant_desc', 'local_mondaysync'));
        }

        $this->add_action_buttons(true, get_string('savemapping', 'local_mondaysync'));
    }

    /**
     * Server-side check that every submitted value is actually one of the
     * options this form offered - closes off a crafted POST setting a
     * matching column, mapping target, direction, or user-creation option
     * outside what the dropdowns show - while still allowing a column's
     * one specific orphaned value to be resubmitted unchanged (it's inert
     * either way; parse() never executes it, this is purely about not
     * silently discarding it without the admin choosing to).
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        if (!in_array($data['matchingcolumnid'], $this->validcolumnids, true)) {
            $errors['matchingcolumnid'] = get_string('invalidselection', 'local_mondaysync');
        }

        // Validated here rather than via addRule(..., 'required', ...) -
        // that mechanism forces its containing header section to always
        // render expanded (confirmed against core forms source: any
        // client-side required rule inside a header makes Moodle
        // force-expand it, regardless of setExpanded() never being
        // called), which was why "Suspended field labels" always opened
        // by default while "User creation" (validated the same way as
        // here) didn't.
        if (trim((string)($data['suspendedlabelyes'] ?? '')) === '') {
            $errors['suspendedlabelyes'] = get_string('required');
        }
        if (trim((string)($data['suspendedlabelno'] ?? '')) === '') {
            $errors['suspendedlabelno'] = get_string('required');
        }

        foreach ($this->_customdata['columns'] as $col) {
            $targetelname = 'target_' . $col['id'];
            $directionelname = 'direction_' . $col['id'];
            $submitted = $data[$targetelname] ?? '';
            $allowedorphan = $this->allowedorphanvalues[$col['id']] ?? null;

            if (!empty($submitted) && !in_array($submitted, $this->validtargetoptions, true) && $submitted !== $allowedorphan) {
                $errors['group_' . $col['id']] = get_string('invalidselection', 'local_mondaysync');
                continue;
            }
            if (isset($data[$directionelname]) && !in_array($data[$directionelname], \local_mondaysync\mapping_util::DIRECTIONS, true)) {
                $errors['group_' . $col['id']] = get_string('invalidselection', 'local_mondaysync');
                continue;
            }

            // Advanced fields with a restricted direction set (e.g.
            // lastlogin: Moodle -> Monday only) - catch a mismatched
            // direction here with a clear message, rather than letting it
            // save and then silently do nothing (parse() would otherwise
            // just drop it at execution time with no obvious explanation).
            if (!empty($submitted) && strpos($submitted, 'advanced:') === 0) {
                $advfield = substr($submitted, strlen('advanced:'));
                $direction = $data[$directionelname] ?? '';
                if (!\local_mondaysync\mapping_util::is_direction_allowed('advanced', $advfield, $direction)) {
                    $a = new \stdClass();
                    $a->field = $advfield;
                    $a->directions = implode(', ', array_map(
                        function ($d) {
                            return get_string('direction' . strtolower($d), 'local_mondaysync');
                        },
                        \local_mondaysync\mapping_util::ADVANCED_FIELDS[$advfield] ?? []
                    ));
                    $errors['group_' . $col['id']] = get_string('directionrestricted', 'local_mondaysync', $a);
                }
            }
        }

        // 2026 fix (external review, item 3): nothing prevented two
        // different Monday columns being mapped to the same Moodle field.
        // Since each column tracks its own independent sync-cache
        // baseline, that would make the two mappings fight each other on
        // every poll - each one only knows about its own last-seen value,
        // not that the other changed the same field moments earlier - and
        // there's no algorithmically "correct" outcome to compute when
        // two Monday columns disagree about one Moodle field. Reject the
        // configuration outright rather than attempt conflict-resolution
        // for something that's fundamentally ambiguous.
        $targetcolumns = [];
        foreach ($this->_customdata['columns'] as $col) {
            $submitted = $data['target_' . $col['id']] ?? '';
            if ($submitted !== '') {
                $targetcolumns[$submitted][] = $col['id'];
            }
        }
        foreach ($targetcolumns as $colids) {
            if (count($colids) > 1) {
                foreach ($colids as $dupcolid) {
                    $errors['group_' . $dupcolid] = get_string('duplicatetarget', 'local_mondaysync');
                }
            }
        }

        // User creation: if enabled, the trigger column and all four
        // required field mappings, plus a default auth, must be set - and
        // each must be one of the options actually offered, not a
        // tampered value.
        if (!empty($data['createusers'])) {
            if (empty($data['createtriggercolumnid'])) {
                $errors['createtriggercolumnid'] = get_string('required');
            } else if (!in_array($data['createtriggercolumnid'], $this->validstatuscolumnids, true)) {
                $errors['createtriggercolumnid'] = get_string('invalidselection', 'local_mondaysync');
            } else if (!empty($data['target_' . $data['createtriggercolumnid']])) {
                // 2026 fix (external review, item 3): nothing stopped the
                // trigger column also being picked in the ordinary
                // per-column mapping dropdown - the regular sync engine
                // would then process it as a normal mapping on every
                // poll, fighting with (and overwriting) the trigger-
                // specific status logic that column is meant to drive.
                $errors['createtriggercolumnid'] = get_string('triggercolumnalsomapped', 'local_mondaysync');
            }

            foreach (['createfirstnamecolumnid', 'createlastnamecolumnid', 'createemailcolumnid', 'createusernamecolumnid'] as $elname) {
                if (empty($data[$elname])) {
                    $errors[$elname] = get_string('required');
                } else if (!in_array($data[$elname], $this->validcolumnids, true)) {
                    $errors[$elname] = get_string('invalidselection', 'local_mondaysync');
                }
            }

            if (empty($data['createdefaultauth'])) {
                $errors['createdefaultauth'] = get_string('required');
            } else if (!in_array($data['createdefaultauth'], $this->validauthplugins, true)) {
                $errors['createdefaultauth'] = get_string('invalidselection', 'local_mondaysync');
            }
        } else if (!empty($data['createdefaultauth']) && !in_array($data['createdefaultauth'], $this->validauthplugins, true)) {
            // Still validate even when creation is off, in case it's re-enabled later with stale data.
            $errors['createdefaultauth'] = get_string('invalidselection', 'local_mondaysync');
        }

        // Tenant fields are both optional - only validate that a submitted
        // value (if any) is actually one of the options offered.
        if (!empty($data['createtenantcolumnid']) && !in_array($data['createtenantcolumnid'], $this->validcolumnids, true)) {
            $errors['createtenantcolumnid'] = get_string('invalidselection', 'local_mondaysync');
        }
        if (isset($data['createdefaulttenantid']) && $data['createdefaulttenantid'] !== ''
            && !in_array((string)$data['createdefaulttenantid'], $this->validtenantids, true)) {
            $errors['createdefaulttenantid'] = get_string('invalidselection', 'local_mondaysync');
        }

        return $errors;
    }

    /**
     * Human-readable label for a standard Moodle user field, using core
     * strings where they exist.
     */
    protected function standard_field_label(string $fieldname): string {
        // These two don't have a core string matching the field name
        // directly (verified against the real lang files rather than
        // guessed) - 'lang' needs 'preferredlanguage', and 'calendartype'
        // needs 'preferredcalendar' from the 'calendar' component
        // specifically, not the default 'moodle' one.
        $explicit = [
            'lang' => get_string('preferredlanguage'),
            'calendartype' => get_string('preferredcalendar', 'calendar'),
        ];
        if (isset($explicit[$fieldname])) {
            return $explicit[$fieldname];
        }

        $corestrings = [
            'firstname', 'lastname', 'city', 'department',
            'institution', 'address', 'phone1', 'phone2', 'description',
            'email', 'idnumber', 'country',
        ];
        if (in_array($fieldname, $corestrings, true)) {
            return get_string($fieldname);
        }
        // Fallback for anything without a matching core string.
        return ucfirst(preg_replace('/(?<!^)[A-Z]/', ' $0', $fieldname));
    }

    /**
     * Human-readable label for an advanced field, noting any direction
     * restriction directly in the label text itself (there's no JS in this
     * wizard to dynamically constrain the direction dropdown based on the
     * chosen target, so the restriction needs to be visible up front).
     */
    protected function advanced_field_label(string $fieldname, array $alloweddirections): string {
        $labels = [
            'auth' => get_string('advancedfield_auth', 'local_mondaysync'),
            'suspended' => get_string('advancedfield_suspended', 'local_mondaysync'),
            'lastlogin' => get_string('advancedfield_lastlogin', 'local_mondaysync'),
            'timecreated' => get_string('advancedfield_timecreated', 'local_mondaysync'),
            'username' => get_string('advancedfield_username', 'local_mondaysync'),
            'confirmed' => get_string('advancedfield_confirmed', 'local_mondaysync'),
            'policyagreed' => get_string('advancedfield_policyagreed', 'local_mondaysync'),
        ];
        $label = $labels[$fieldname] ?? $fieldname;

        if (count($alloweddirections) < count(\local_mondaysync\mapping_util::DIRECTIONS)) {
            $directionnames = array_map(
                function ($d) {
                    return get_string('direction' . strtolower($d), 'local_mondaysync');
                },
                $alloweddirections
            );
            $label .= ' (' . implode(', ', $directionnames) . ' ' . get_string('only', 'local_mondaysync') . ')';
        }

        return $label;
    }

    /**
     * Human-readable label for an auth plugin, falling back to its raw
     * shortname if it doesn't have a proper display-name string (unlikely
     * for any real plugin, but shouldn't be able to break the page).
     */
    protected function auth_plugin_label(string $authname): string {
        try {
            return get_string('pluginname', 'auth_' . $authname) . ' (' . $authname . ')';
        } catch (\Throwable $e) {
            return $authname;
        }
    }

    /**
     * Populate a <select> element with "Do not sync", then each group's
     * disabled header option followed by its real, indented options - the
     * closest achievable equivalent to <optgroup> that still submits
     * correctly through moodleform's own element-export mechanism.
     * Genuine <optgroup> isn't safely achievable here: confirmed against
     * core source that moodleform::get_data() only ever processes
     * elements actually registered via addElement()/createElement() (it
     * calls exportValue() on each one in $this->_elements) - a hand-built
     * raw <select> with real <optgroup> tags, rendered as a 'static'
     * element instead of a real form element, would never have its
     * submitted value picked up at all, silently breaking every save for
     * that field. Disabled, visually-indented header options stay a
     * genuine, working form element instead.
     *
     * True bold text isn't achievable inside a plain <option> either - a
     * hard HTML constraint (browsers only ever render option content as
     * plain text), not a Moodle one. Header rows use a visual convention
     * instead ("── Heading ──") that reads clearly as "not a selectable
     * item" even without real bold, and are marked disabled so they
     * genuinely can't be selected regardless of how they look.
     *
     * @param mixed $select The MoodleQuickForm_select element to populate (not type-hinted - not confident enough in the exact class name to risk a wrong hint causing a fatal error).
     * @param array $groups [['header' => string, 'choices' => [value => label]], ...]
     * @param array|null $trailinggroup One more group (same shape) added after every other group - used for a column's orphaned-mapping option, which needs its own header but should always sort last.
     */
    protected function populate_grouped_select($select, array $groups, ?array $trailinggroup = null): void {
        $select->addOption(get_string('donotsync', 'local_mondaysync'), '');

        $allgroups = $groups;
        if ($trailinggroup !== null) {
            $allgroups[] = $trailinggroup;
        }

        $indent = "\u{00A0}\u{00A0}\u{00A0}\u{00A0}";
        $headerindex = 0;
        foreach ($allgroups as $group) {
            $headerindex++;
            $select->addOption('── ' . $group['header'] . ' ──', '__header' . $headerindex . '__', ['disabled' => 'disabled']);
            foreach ($group['choices'] as $value => $label) {
                $select->addOption($indent . $label, $value);
            }
        }
    }

    protected function warning(string $text): string {
        return \html_writer::span('⚠ ' . $text, 'text-danger');
    }

    /**
     * Build a display label for a Monday.com column - "Title (id, type)" or
     * "Title (id)" - with everything escaped.
     *
     * 2026 fix (external review, Critical #1): Monday column titles are
     * externally-supplied data (anyone able to rename a board column
     * controls this text) and were being interpolated into select option
     * text and group labels completely unescaped across four separate
     * locations in this file. Moodle's HTML_QuickForm_select renders
     * option text with zero escaping of its own (confirmed directly
     * against core source) - a column renamed to something like
     * "</option><script>...</script>" would execute when an admin opened
     * this page. Centralising every column-label construction through
     * this one escaped helper, rather than fixing four call sites
     * individually, so a future fifth usage can't reintroduce the same
     * gap by simply forgetting to escape it again.
     */
    protected function column_label(array $col, bool $includetype = true): string {
        $label = $col['title'] . ' (' . $col['id'];
        if ($includetype) {
            $label .= ', ' . $col['type'];
        }
        $label .= ')';
        return s($label);
    }

    /**
     * "Select columns to map" picker: a compact, searchable, scrollable
     * checklist of every column, with a "Bring over for mapping" button
     * that reveals the full target/direction/allow-clear controls only
     * for the columns actually chosen - rather than showing every
     * column's full controls all at once, which gets overwhelming fast
     * on a board with many columns.
     *
     * Purely client-side (no server round-trip) - every column's controls
     * already exist in the page exactly as before, this just adds a
     * layer on top that shows/hides them. Already-mapped columns (from a
     * previous save) start already "brought over", visible below without
     * needing to be picked again each time the wizard's opened.
     *
     * Finds each column's row by walking up from the target select's own
     * reliable id (id_target_<columnid>, set explicitly by this form) to
     * its enclosing <fieldset> - confirmed against the real rendered HTML
     * that this fieldset wraps all of a row's controls (target, direction,
     * allow-clear, remove) plus a legend containing the column's title,
     * so it's both the right thing to show/hide and searchable by name.
     * Deliberately not relying on any Moodle/YUI-generated wrapper id for
     * the row itself - those are assigned at runtime and non-deterministic
     * (confirmed by inspecting the real rendered page), unlike the ids
     * this form sets explicitly on its own elements.
     *
     * The visible column name/title sits in a *separate* label column, a
     * sibling of the fieldset rather than inside it - confirmed against
     * the real rendered page - with its own stable, predictable id
     * (fgroup_id_group_<columnid>_label), so that's toggled alongside the
     * fieldset rather than assumed to be part of it.
     */
    protected function column_picker_html(array $columns, array $rawmappings): string {
        $pickeritems = '';
        $initiallymapped = [];

        foreach ($columns as $col) {
            $colid = $col['id'];
            $initiallymapped[$colid] = isset($rawmappings[$colid]);
            $pickeritems .= '<div class="local-mondaysync-picker-item py-1" id="local_mondaysync_picker_item_' . s($colid) . '">'
                . '<label class="mb-0 font-weight-normal"><input type="checkbox" class="local-mondaysync-picker-checkbox mr-1" value="' . s($colid) . '"> '
                . s($col['title']) . ' <span class="text-muted small">(' . s($colid) . ', ' . s($col['type']) . ')</span>'
                . '</label></div>';
        }

        $searchlabel = get_string('columnfiltersearch', 'local_mondaysync');
        $bringoverlabel = get_string('bringoverformapping', 'local_mondaysync');

        $html = '<div class="local-mondaysync-picker border rounded p-3 mb-4">'
            . '<p class="mb-2">' . s(get_string('pickerintro', 'local_mondaysync')) . '</p>'
            . '<input type="text" id="local_mondaysync_picker_search" class="form-control mb-2"'
            . ' placeholder="' . s($searchlabel) . '" style="max-width:320px">'
            . '<div id="local_mondaysync_picker_list" class="border rounded p-2 mb-2 bg-white" style="max-height:220px; overflow-y:auto;">'
            . $pickeritems
            . '</div>'
            . '<button type="button" id="local_mondaysync_bringover" class="btn btn-secondary btn-sm">' . s($bringoverlabel) . '</button>'
            . ' <span id="local_mondaysync_picker_count" class="text-muted small ml-2"></span>'
            . '</div>';

        return $html;
    }

    /**
     * The picker's behaviour, registered via $PAGE->requires->js_amd_inline()
     * rather than embedded as an inline <script> tag in form content -
     * confirmed by inspecting the real rendered page that Moodle's own
     * client-side JS rebuilds this group's surrounding DOM after the
     * initial page load, and a <script> tag inserted as part of a DOM
     * rebuild (rather than present during the browser's original HTML
     * parse) never executes at all, silently, with no error of any kind.
     * Using Moodle's actual page-JS mechanism runs independently of
     * whatever that rebuild does.
     */
    /**
     * For every column, greys out and forces the direction dropdown
     * whenever its target field only ever allows one direction (e.g.
     * lastlogin, timecreated - both toMonday-only since Moodle overwrites
     * them itself) - purely a visual/UX aid, giving that clearly *before*
     * an attempted save rather than only as a validation error after one.
     * Generic over whatever mapping_util::ADVANCED_FIELDS currently
     * restricts, so a future single-direction field gets this treatment
     * automatically rather than needing its own JS added.
     *
     * The server (mapping.php), not this script, is what actually
     * enforces the direction for these fields - disabling the visible
     * <select> here is safe specifically because a disabled element's
     * value doesn't submit at all, and mapping.php now forces the
     * correct direction server-side regardless of what (if anything) was
     * submitted for these fields.
     *
     * Registered via $PAGE->requires->js_amd_inline() rather than an
     * inline <script>, same reasoning as column_picker_js() - see that
     * method's own docblock.
     */
    protected function direction_lock_js(array $columns): string {
        $singledirections = [];
        foreach (\local_mondaysync\mapping_util::ADVANCED_FIELDS as $fieldname => $alloweddirections) {
            if (count($alloweddirections) === 1) {
                $singledirections['advanced:' . $fieldname] = $alloweddirections[0];
            }
        }

        $columnidsjson = json_encode(array_column($columns, 'id'));
        $singledirectionsjson = json_encode($singledirections);

        return '
(function() {
    var columnIds = ' . $columnidsjson . ';
    var singleDirections = ' . $singledirectionsjson . ';

    function applyLock(colid) {
        var targetSelect = document.getElementById("id_target_" + colid);
        var directionSelect = document.getElementById("id_direction_" + colid);
        if (!targetSelect || !directionSelect) {
            return;
        }
        var forced = singleDirections[targetSelect.value];
        if (forced) {
            directionSelect.value = forced;
            directionSelect.disabled = true;
        } else {
            directionSelect.disabled = false;
        }
    }

    columnIds.forEach(function(colid) {
        applyLock(colid);
        var targetSelect = document.getElementById("id_target_" + colid);
        if (targetSelect) {
            targetSelect.addEventListener("change", function() {
                applyLock(colid);
            });
        }
    });
})();
';
    }

    protected function column_picker_js(array $columns, array $rawmappings): string {
        $initiallymapped = [];
        foreach ($columns as $col) {
            $initiallymapped[$col['id']] = isset($rawmappings[$col['id']]);
        }

        // Placeholder token substituted client-side, so word order stays
        // correct for any future translation rather than being hardcoded
        // in JS.
        $counttemplate = get_string('pickercount', 'local_mondaysync', (object)['available' => '__AVAILABLE__']);

        $columnidsjson = json_encode(array_column($columns, 'id'));
        $initiallymappedjson = json_encode($initiallymapped);
        $counttemplatejson = json_encode($counttemplate);

        return '
(function() {
    var columnIds = ' . $columnidsjson . ';
    var initiallyMapped = ' . $initiallymappedjson . ';
    var countTemplate = ' . $counttemplatejson . ';

    var pickerSearch = document.getElementById("local_mondaysync_picker_search");
    var bringOverBtn = document.getElementById("local_mondaysync_bringover");
    var pickerCount = document.getElementById("local_mondaysync_picker_count");

    function getRowWrapper(colid) {
        // The stable, predictable id Moodle gives this row\'s actual outer
        // wrapper - confirmed directly from the real rendered page
        // (fgroup_id_group_<colid>), covering both the label column and
        // the fieldset as descendants in one element, unlike the
        // fieldset/label-column pair this used to target separately.
        return document.getElementById("fgroup_id_group_" + colid);
    }

    function setRowVisible(colid, visible) {
        // Plain "element.style.display = ..." loses to Boost Union\'s
        // .d-flex/.row utility classes, which are declared with
        // !important - confirmed against the real rendered page. Forcing
        // our own !important on the way down beats that; clearing the
        // inline style entirely on the way back up (rather than forcing
        // a guessed value) lets the normal CSS cascade restore whatever
        // it would naturally be. Hiding the wrapper itself (rather than
        // just its children) also correctly collapses its own .mb-3
        // margin, rather than leaving an empty, still-margined box behind.
        var wrapper = getRowWrapper(colid);
        if (!wrapper) {
            return;
        }
        if (visible) {
            wrapper.style.removeProperty("display");
        } else {
            wrapper.style.setProperty("display", "none", "important");
        }
    }

    function getPickerItem(colid) {
        return document.getElementById("local_mondaysync_picker_item_" + colid);
    }

    function isBroughtOver(colid) {
        var wrapper = getRowWrapper(colid);
        return !!(wrapper && wrapper.style.display !== "none");
    }

    function updatePickerCount() {
        if (!pickerCount) {
            return;
        }
        var available = 0;
        columnIds.forEach(function(colid) {
            var item = getPickerItem(colid);
            if (item && item.style.display !== "none") {
                available++;
            }
        });
        pickerCount.textContent = countTemplate.replace("__AVAILABLE__", available);
    }

    function applyPickerSearch() {
        var term = pickerSearch ? pickerSearch.value.toLowerCase() : "";
        columnIds.forEach(function(colid) {
            var item = getPickerItem(colid);
            if (!item) {
                return;
            }
            if (isBroughtOver(colid)) {
                item.style.display = "none";
                return;
            }
            var matches = term === "" || item.textContent.toLowerCase().indexOf(term) !== -1;
            item.style.display = matches ? "" : "none";
        });
        updatePickerCount();
    }

    function bringOver(colid) {
        var removedflag = document.getElementsByName("removed_" + colid)[0];
        if (removedflag) {
            removedflag.value = "0";
        }
        setRowVisible(colid, true);
        applyPickerSearch();
    }

    function removeMapping(colid) {
        var target = document.getElementById("id_target_" + colid);
        var direction = document.getElementById("id_direction_" + colid);
        var allowclear = document.getElementById("id_allowclear_" + colid);
        if (target) {
            target.value = "";
        }
        if (direction) {
            direction.value = "toMoodle";
            // If this column\'s target was previously a single-direction
            // field (e.g. timecreated), direction_lock_js() would have
            // disabled this select - clear that directly here rather
            // than relying on the other script\'s own change handler,
            // since resetting .value programmatically doesn\'t fire a
            // "change" event on its own.
            direction.disabled = false;
        }
        if (allowclear) {
            allowclear.checked = false;
        }

        var removedflag = document.getElementsByName("removed_" + colid)[0];
        if (removedflag) {
            removedflag.value = "1";
        }

        setRowVisible(colid, false);

        var item = getPickerItem(colid);
        if (item) {
            var checkbox = item.querySelector("input[type=checkbox]");
            if (checkbox) {
                checkbox.checked = false;
            }
        }

        applyPickerSearch();
    }

    columnIds.forEach(function(colid) {
        setRowVisible(colid, !!initiallyMapped[colid]);
    });
    applyPickerSearch();

    if (pickerSearch) {
        pickerSearch.addEventListener("input", applyPickerSearch);
    }

    if (bringOverBtn) {
        bringOverBtn.addEventListener("click", function() {
            columnIds.forEach(function(colid) {
                var item = getPickerItem(colid);
                if (!item || item.style.display === "none") {
                    return;
                }
                var checkbox = item.querySelector("input[type=checkbox]");
                if (checkbox && checkbox.checked) {
                    bringOver(colid);
                }
            });
        });
    }

    document.addEventListener("click", function(e) {
        var btn = e.target.closest ? e.target.closest(".local-mondaysync-remove-btn") : null;
        if (btn) {
            e.preventDefault();
            removeMapping(btn.getAttribute("data-colid"));
        }
    });
})();
';
    }
}