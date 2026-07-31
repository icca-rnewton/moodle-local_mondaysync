<?php
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
            $columnoptions[$col['id']] = $col['title'] . ' (' . $col['id'] . ', ' . $col['type'] . ')';
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

        // Shared by every column's direction dropdown.
        $directionoptions = [
            'toMoodle' => get_string('directiontomoodle', 'local_mondaysync'),
            'toMonday' => get_string('directiontomonday', 'local_mondaysync'),
            'both' => get_string('directionboth', 'local_mondaysync'),
        ];

        foreach ($columns as $col) {
            $targetelname = 'target_' . $col['id'];
            $directionelname = 'direction_' . $col['id'];
            $label = $col['title'] . ' (' . $col['id'] . ', ' . $col['type'] . ')';

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

            $group = [
                $mform->createElement('select', $targetelname, '', $columnoptionsforselect),
                $mform->createElement('select', $directionelname, '', $directionoptions),
            ];
            $mform->addGroup($group, 'group_' . $col['id'], $label, ' ', false);
            $mform->setType($targetelname, PARAM_RAW);
            $mform->setType($directionelname, PARAM_RAW);
            $mform->setDefault($directionelname, \local_mondaysync\mapping_util::DEFAULT_DIRECTION);

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
                $statuscolumnoptions[$col['id']] = $col['title'] . ' (' . $col['id'] . ')';
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

        // User creation: if enabled, the trigger column and all four
        // required field mappings, plus a default auth, must be set - and
        // each must be one of the options actually offered, not a
        // tampered value.
        if (!empty($data['createusers'])) {
            if (empty($data['createtriggercolumnid'])) {
                $errors['createtriggercolumnid'] = get_string('required');
            } else if (!in_array($data['createtriggercolumnid'], $this->validstatuscolumnids, true)) {
                $errors['createtriggercolumnid'] = get_string('invalidselection', 'local_mondaysync');
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
}