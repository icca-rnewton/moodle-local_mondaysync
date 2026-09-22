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

// The namespace declaration below must be the very first statement in
// this file, before defined('MOODLE_INTERNAL') - reversing that order is
// a fatal PHP error (confirmed the hard way while building the same
// pattern for local_asyncwatch).
namespace local_mondaysync;

defined('MOODLE_INTERNAL') || die();

/**
 * Admin setting: grouped, checkbox-based picker for which Standard,
 * Advanced, and custom-profile-field-category fields the mapping wizard
 * offers.
 *
 * @package    local_mondaysync
 * @copyright  2026 Inns of Court College of Advocacy (Part of COIC)
 * @license    http://www.gnu.org/licenses/GPL-3.0 GNU GPL v3 or later
 */

/**
 * Stock Moodle admin_setting_configmulticheckbox can't render grouped
 * headings or take its choices from three different sources (core user
 * fields, core account-property fields, and dynamic per-site custom
 * profile field categories) - hence a full custom subclass, same
 * approach as the equivalent picker already built for local_asyncwatch.
 *
 * Storage: a plain comma-separated string of checked keys, each prefixed
 * by which group it belongs to - 'std:firstname', 'adv:auth',
 * 'profile:favouritecolour' - so a core field and a custom field can
 * never collide, and write_setting() can tell at a glance which
 * catalog to validate a submitted key against. This plugin has no
 * existing setting to stay backward-compatible with here, but it's still
 * the simplest reasonable format for a new one.
 */
class admin_setting_fieldpicker extends \admin_setting {

    public function __construct(string $name, string $visiblename, string $description) {
        parent::__construct($name, $visiblename, $description, []);
    }

    /**
     * @return array|null [prefixed key => 1] for every currently-enabled
     * field, or null if this setting is somehow unreadable (matches the
     * base class's documented contract for "couldn't determine a value").
     */
    public function get_setting() {
        // mapping_util::get_enabled_field_keys() already handles the
        // "never explicitly saved" case itself, returning the
        // backward-compatible default (see its own docblock) - no need
        // to duplicate that fallback logic here or lean on how the
        // admin-settings framework applies defaultsetting for a custom
        // subclass, which has enough subtlety that owning the whole
        // contract directly felt safer than trying to replicate it.
        $keys = mapping_util::get_enabled_field_keys();
        $setting = [];
        foreach ($keys as $key) {
            $setting[$key] = 1;
        }
        return $setting;
    }

    /**
     * @param array $data Submitted checked keys (or '' if none/not an array).
     * @return string '' on success, an error message otherwise.
     */
    public function write_setting($data) {
        if (!is_array($data)) {
            return '';
        }

        $validkeys = $this->all_valid_keys();
        $submitted = array_keys($data);
        $accepted = array_values(array_intersect($submitted, $validkeys));

        return ($this->config_write($this->name, implode(',', $accepted)) ? '' : get_string('errorsetting', 'admin'));
    }

    public function output_html($data, $query = '') {
        global $OUTPUT;

        if (!is_array($data)) {
            $data = [];
        }

        $html = '';

        $html .= $this->render_group(
            get_string('fieldpicker_standard', 'local_mondaysync'),
            $this->standard_field_choices(),
            'std:',
            $data
        );

        $html .= $this->render_group(
            get_string('fieldpicker_advanced', 'local_mondaysync'),
            $this->advanced_field_choices(),
            'adv:',
            $data
        );

        foreach (mapping_util::get_custom_fields_by_category() as $categoryname => $fields) {
            $choices = [];
            foreach ($fields as $shortname => $info) {
                $choices[$shortname] = $info['name'] . ' (' . $shortname . ')';
            }
            $html .= $this->render_group($categoryname, $choices, 'profile:', $data);
        }

        // A small <style> block, scoped to this picker's own class, purely
        // for spacing between each checkbox and its label - safe as plain
        // inline CSS (unlike the mapping wizard's inline <script> issue
        // elsewhere in this plugin, a <style> block has no "must survive
        // the original page parse" timing constraint - its rules apply
        // globally the moment it's in the DOM, regardless of when or how
        // it got inserted).
        $style = '<style>.local-mondaysync-fieldpicker-item label { margin-left: 0.4em; }</style>';

        $element = $style . \html_writer::tag('div', $html, ['class' => 'local-mondaysync-fieldpicker']);

        return format_admin_setting($this, $this->visiblename, $element, $this->description, true, '', null, $query);
    }

    /**
     * One "group" of the picker: a bold heading, and one checkbox per
     * choice, each named as this setting's full form name with the
     * prefixed key as its array index - e.g.
     * local_mondaysync_enabledfields[std:firstname].
     *
     * @param string $heading
     * @param array $choices [unprefixed key => display label]
     * @param string $prefix 'std:', 'adv:', or 'profile:'
     * @param array $checked [prefixed key => 1] currently-enabled keys
     */
    protected function render_group(string $heading, array $choices, string $prefix, array $checked): string {
        if (empty($choices)) {
            return '';
        }

        $rows = '';
        foreach ($choices as $key => $label) {
            $prefixedkey = $prefix . $key;
            $inputname = $this->get_full_name() . '[' . $prefixedkey . ']';
            $inputid = $this->get_id() . '_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $prefixedkey);
            $ischecked = !empty($checked[$prefixedkey]);

            $checkbox = \html_writer::checkbox($inputname, 1, $ischecked, $label, [
                'id' => $inputid,
            ]);
            $rows .= \html_writer::tag('div', $checkbox, ['class' => 'local-mondaysync-fieldpicker-item']);
        }

        $headinghtml = \html_writer::tag('strong', s($heading));
        return \html_writer::tag('div', $headinghtml . $rows, ['class' => 'local-mondaysync-fieldpicker-group mb-3']);
    }

    /**
     * @return array [fieldname => display label] for every field
     * SAFE_STANDARD_FIELDS currently knows about, using the same labels
     * the mapping wizard itself uses.
     */
    protected function standard_field_choices(): array {
        $choices = [];
        foreach (mapping_util::SAFE_STANDARD_FIELDS as $fieldname) {
            $choices[$fieldname] = $this->standard_field_label($fieldname);
        }
        return $choices;
    }

    /**
     * @return array [fieldname => display label] for every field
     * ADVANCED_FIELDS currently knows about.
     */
    protected function advanced_field_choices(): array {
        $choices = [];
        foreach (array_keys(mapping_util::ADVANCED_FIELDS) as $fieldname) {
            $choices[$fieldname] = $this->advanced_field_label($fieldname);
        }
        return $choices;
    }

    /**
     * Human-readable label for a standard Moodle user field, using core
     * strings where they exist - same helper as mapping_form.php's own
     * version, duplicated rather than shared since forms and admin
     * settings classes don't have a natural common ancestor to hang a
     * shared helper off without more restructuring than this warrants.
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
            'firstname', 'lastname', 'email', 'idnumber', 'city', 'department',
            'institution', 'address', 'phone1', 'phone2', 'description',
            'country',
        ];
        if (in_array($fieldname, $corestrings, true)) {
            try {
                return get_string($fieldname);
            } catch (\Throwable $e) {
                // Falls through to the generic fallback below.
            }
        }
        return ucfirst(preg_replace('/(?<!^)[A-Z]/', ' $0', $fieldname));
    }

    protected function advanced_field_label(string $fieldname): string {
        $labels = [
            'auth' => get_string('advancedfield_auth', 'local_mondaysync'),
            'suspended' => get_string('advancedfield_suspended', 'local_mondaysync'),
            'lastlogin' => get_string('advancedfield_lastlogin', 'local_mondaysync'),
            'timecreated' => get_string('advancedfield_timecreated', 'local_mondaysync'),
            'username' => get_string('advancedfield_username', 'local_mondaysync'),
            'confirmed' => get_string('advancedfield_confirmed', 'local_mondaysync'),
            'policyagreed' => get_string('advancedfield_policyagreed', 'local_mondaysync'),
        ];
        return $labels[$fieldname] ?? $fieldname;
    }

    /**
     * Every prefixed key write_setting() is allowed to accept - the
     * whitelist a submitted POST is checked against, so a crafted
     * request can't enable a field outside the real catalog.
     */
    protected function all_valid_keys(): array {
        $keys = [];
        foreach (mapping_util::SAFE_STANDARD_FIELDS as $field) {
            $keys[] = 'std:' . $field;
        }
        foreach (array_keys(mapping_util::ADVANCED_FIELDS) as $field) {
            $keys[] = 'adv:' . $field;
        }
        foreach (array_keys(mapping_util::get_custom_fields()) as $shortname) {
            $keys[] = 'profile:' . $shortname;
        }
        return $keys;
    }
}