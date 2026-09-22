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

        // Build the flat option list shared by every column's "sync to" dropdown.
        $targetoptions = ['' => get_string('donotsync', 'local_mondaysync')];

        foreach (\local_mondaysync\mapping_util::SAFE_STANDARD_FIELDS as $fieldname) {
            $label = get_string('standardfieldoption', 'local_mondaysync', $this->standard_field_label($fieldname));
            $targetoptions['standard:' . $fieldname] = $label;
        }

        foreach ($customfields as $shortname => $info) {
            $type = ($info['datatype'] === 'datetime') ? 'date' : 'custom';
            $a = new \stdClass();
            $a->name = $info['name'];
            $a->shortname = $shortname;
            $a->datatype = $info['datatype'];
            $targetoptions[$type . ':' . $shortname] = get_string('customfieldoption', 'local_mondaysync', $a);
        }

        foreach (\local_mondaysync\mapping_util::ADVANCED_FIELDS as $fieldname => $alloweddirections) {
            $targetoptions['advanced:' . $fieldname] = get_string('advancedfieldoption', 'local_mondaysync',
                $this->advanced_field_label($fieldname, $alloweddirections));
        }

        $mform->addElement('static', 'advancedwarning', '', $this->warning(get_string('advancedwarning', 'local_mondaysync')));

        $mform->addElement('static', 'columnfilter', '', $this->column_picker_html($columns, $rawmappings));

        global $PAGE;
        $PAGE->requires->js_amd_inline($this->column_picker_js($columns, $rawmappings));

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

            // Start from the shared options, but this column may need one
            // extra "orphaned" option added just for it, if its saved
            // target no longer exists.
            $columnoptionsforselect = $targetoptions;
            $saved = $rawmappings[$col['id']] ?? null;

            if ($saved !== null) {
                $current = $saved['type'] . ':' . $saved['field'];
                if (!array_key_exists($current, $columnoptionsforselect)) {
                    $desc = \local_mondaysync\mapping_util::describe_mapping_target($saved, $customfields);
                    $columnoptionsforselect[$current] = get_string('orphanedfieldoption', 'local_mondaysync', $desc);
                    $this->allowedorphanvalues[$col['id']] = $current;
                }
            }

            $removehtml = '<button type="button" class="btn btn-link btn-sm p-0 ml-2 local-mondaysync-remove-btn"'
                . ' data-colid="' . s($col['id']) . '">' . s(get_string('removemapping', 'local_mondaysync')) . '</button>';

            $group = [
                $mform->createElement('select', $targetelname, '', $columnoptionsforselect),
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
                $current = $saved['type'] . ':' . $saved['field'];
                if (array_key_exists($current, $columnoptionsforselect)) {
                    $mform->setDefault($targetelname, $current);
                }
                $mform->setDefault($directionelname, $saved['direction']);
            }
        }

        $this->validtargetoptions = array_keys($targetoptions);

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
        $mform->addRule('suspendedlabelyes', get_string('required'), 'required', null, 'server');
        $mform->setDefault('suspendedlabelyes', $this->_customdata['suspendedlabelyes'] ?: 'Suspended');

        $mform->addElement('text', 'suspendedlabelno', get_string('suspendedlabelno', 'local_mondaysync'), ['size' => 30]);
        $mform->setType('suspendedlabelno', PARAM_TEXT);
        $mform->addRule('suspendedlabelno', get_string('required'), 'required', null, 'server');
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
        $corestrings = [
            'firstname', 'lastname', 'city', 'department',
            'institution', 'address', 'phone1', 'phone2', 'description',
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

    function getRow(colid) {
        var select = document.getElementById("id_target_" + colid);
        return select ? select.closest("fieldset") : null;
    }

    function getLabelColumn(colid) {
        var label = document.getElementById("fgroup_id_group_" + colid + "_label");
        return label ? label.closest(".col-md-3") : null;
    }

    function setRowVisible(colid, visible) {
        // Plain "element.style.display = ..." loses to Boost Union\'s
        // .d-flex utility class, which is itself declared with
        // !important - confirmed against the real rendered page. Forcing
        // our own !important on the way down beats that; clearing the
        // inline style entirely on the way back up (rather than forcing
        // a guessed "flex" value) lets the normal CSS cascade - including
        // .d-flex - restore whatever it would naturally be.
        [getRow(colid), getLabelColumn(colid)].forEach(function(el) {
            if (!el) {
                return;
            }
            if (visible) {
                el.style.removeProperty("display");
            } else {
                el.style.setProperty("display", "none", "important");
            }
        });
    }

    function getPickerItem(colid) {
        return document.getElementById("local_mondaysync_picker_item_" + colid);
    }

    function isBroughtOver(colid) {
        var row = getRow(colid);
        return !!(row && row.style.display !== "none");
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