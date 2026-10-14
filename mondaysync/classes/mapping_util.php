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
 * Parses and builds the field-mappings config string shared between the sync engine and the mapping wizard.
 *
 * @package    local_mondaysync
 * @copyright  2026 Inns of Court College of Advocacy (Part of COIC)
 * @license    http://www.gnu.org/licenses/GPL-3.0 GNU GPL v3 or later
 */

namespace local_mondaysync;

defined('MOODLE_INTERNAL') || die();

/**
 * Parses and builds the local_mondaysync/fieldmappings config value, shared
 * between the sync engine and the column-mapping wizard so both agree on
 * exactly the same format.
 *
 * Format: one mapping per line,
 * "monday_column_id => type:field:direction:allowclear", where type is one
 * of 'standard', 'custom', 'date', or 'advanced', direction is one of
 * 'toMoodle' (Monday -> Moodle, the original one-way behaviour and the
 * default), 'toMonday' (Moodle -> Monday), or 'both', and allowclear is
 * '1'/'0' - whether a blank Monday value is allowed to clear the Moodle
 * field (default '0', opt-in, since always clearing on blank could
 * silently wipe real profile data if a column was ever left unpopulated
 * by mistake).
 *
 * Both the direction and allowclear segments are optional on read, for
 * backward compatibility with mappings saved before each existed (missing
 * direction defaults to 'toMoodle', missing allowclear defaults to '0' -
 * both preserving prior behaviour exactly). Both are always written on save.
 */
class mapping_util {

    /**
     * The full catalog of standard Moodle user fields this plugin knows
     * how to expose - not necessarily all shown in the mapping wizard's
     * dropdown at once. Which of these are actually offered on a given
     * site is controlled by the "Fields available for mapping" setting
     * (see admin_setting_fieldpicker) - this constant is the ceiling on
     * what that setting can possibly enable, not the current selection.
     */
    const SAFE_STANDARD_FIELDS = [
        'firstname', 'lastname', 'alternatename', 'email', 'idnumber',
        'institution', 'department', 'address',
        'phone1', 'phone2', 'description', 'city',
        'country', 'lang', 'calendartype',
    ];

    /**
     * Standard fields restricted to a narrower set of directions than the
     * three general ones. Anything not listed here can be used in all
     * three directions.
     *
     * email and idnumber are Moodle -> Monday only (external review,
     * item 1): email controls where Moodle sends password-reset links, so
     * letting a Monday edit replace an existing account's address would
     * let whoever controls that cell redirect the account; idnumber is
     * the value this plugin uses to match a Monday row to a Moodle
     * account in the first place, so letting the sync rewrite it can
     * destabilise every later match. Both stay available as an export so
     * Ops can see them on the board, but nothing on Monday can change
     * them in Moodle.
     *
     * @var array [fieldname => string[] of allowed directions]
     */
    const STANDARD_FIELD_DIRECTIONS = [
        'email' => ['toMonday'],
        'idnumber' => ['toMonday'],
    ];

    /**
     * Standard fields whose value must be one of a fixed set of choices
     * (country code, language pack, calendar type). Moodle silently
     * replaces an invalid value for these with the site default rather
     * than rejecting it (core_user::clean_field), so they're checked
     * before writing - see is_fixed_choice_value_valid().
     *
     * @var string[]
     */
    const FIXED_CHOICE_FIELDS = ['country', 'lang', 'calendartype'];

    /**
     * Advanced (core account-property) fields, each with the set of
     * directions it's actually allowed to be used in.
     *
     * auth and suspended are the only ones that can be written from
     * Monday - they control login access directly, so an admin choosing
     * to expose them is expected to understand that risk (the wizard
     * labels them clearly, and the site-level field picker can switch
     * them off).
     *
     * username, confirmed and policyagreed are Moodle -> Monday only
     * (external review, item 1) - not ordinary profile data: changing
     * username can change which external identity resolves to an
     * account; writing confirmed can bypass or reverse Moodle's email
     * confirmation; writing policyagreed flips Moodle's flag without
     * creating the underlying policy-acceptance record. Visible on the
     * board, never changeable from it.
     *
     * currentlogin/timecreated are Moodle -> Monday only for a technical
     * reason rather than a risk one: Moodle overwrites both itself (on
     * every real login, and once at account creation respectively), so a
     * write into either from Monday would just get silently clobbered -
     * there's no point offering a direction that can never actually take
     * effect.
     *
     * currentlogin is the single "Last login" field (labelled that way
     * for Ops): the time of the account's most recent login. Moodle's own
     * lastlogin column is deliberately not offered - it holds the
     * *previous* login (on every login Moodle copies the old currentlogin
     * into it), so it is still 0 after an account's first login and
     * otherwise lags one login behind. Offering both would only invite
     * choosing the wrong one.
     *
     * Like SAFE_STANDARD_FIELDS, this is the full catalog this plugin
     * knows about, not necessarily what's currently offered - see
     * admin_setting_fieldpicker.
     *
     * @var array [fieldname => string[] of allowed directions]
     */
    const ADVANCED_FIELDS = [
        'auth' => ['toMoodle', 'toMonday', 'both'],
        'suspended' => ['toMoodle', 'toMonday', 'both'],
        'username' => ['toMonday'],
        'confirmed' => ['toMonday'],
        'policyagreed' => ['toMonday'],
        'currentlogin' => ['toMonday'],
        'timecreated' => ['toMonday'],
    ];

    /**
     * The Advanced fields that hold a Unix timestamp and are written to
     * Monday as a date (and, for a genuine Monday Date column, as a UTC
     * date and time). One list, used by the sync engine and the wizard
     * alike - these were previously spelled out separately in several
     * places, which is how the lists drift apart.
     *
     * @var string[]
     */
    const TIMESTAMP_FIELDS = ['currentlogin', 'timecreated'];

    /**
     * User columns the sync engine itself needs but that are not mappable
     * fields: used to tell whether an account has ever been used at all
     * (see sync_manager::recover_welcome_email()). Moodle's lastlogin is
     * only the previous login - it stays 0 after a first one - so "never
     * used" is judged from all of these together. lastlogin is no longer
     * a mappable field but is still loaded here for that check, and
     * currentlogin is listed even though it is also in the catalogue so
     * the check doesn't silently depend on that staying so.
     *
     * @var string[]
     */
    const INTERNAL_USER_FIELDS = ['firstaccess', 'lastaccess', 'lastlogin', 'currentlogin'];

    /** @var string[] Valid sync directions. */
    const DIRECTIONS = ['toMoodle', 'toMonday', 'both'];

    /** @var string Default direction for mappings saved before direction existed. */
    const DEFAULT_DIRECTION = 'toMoodle';

    /**
     * Standard fields enabled by default (used as the fallback when the
     * site's "Fields available for mapping" setting has never been
     * saved) - exactly the set this plugin has always offered, so
     * upgrading doesn't change anything for an already-working board.
     * The newer additions to SAFE_STANDARD_FIELDS (email, idnumber,
     * country, lang, calendartype) are deliberately not included here -
     * they're opt-in, an admin has to explicitly enable them.
     *
     * @var string[]
     */
    const DEFAULT_ENABLED_STANDARD_FIELDS = [
        'firstname', 'lastname', 'alternatename',
        'institution', 'department', 'address',
        'phone1', 'phone2', 'description', 'city',
    ];

    /**
     * Same idea as DEFAULT_ENABLED_STANDARD_FIELDS, for advanced fields.
     * timecreated is included since it was added alongside this same
     * feature; username/confirmed/policyagreed are deliberately not -
     * genuinely new, higher-stakes capabilities an admin should
     * consciously opt into rather than have appear automatically.
     *
     * @var string[]
     */
    const DEFAULT_ENABLED_ADVANCED_FIELDS = ['auth', 'suspended', 'currentlogin', 'timecreated'];

    /**
     * Canonical text this plugin writes to a board's creation-trigger Status
     * column, and expects (case-insensitively) when reading it back.
     */
    const STATUS_NOT_CREATED = 'Not yet created';
    const STATUS_CREATE = 'Create user';
    const STATUS_CREATED = 'User created';
    const STATUS_ERROR = 'Error';

    /**
     * Interpret a piece of text from a Monday.com column as a suspended/
     * not-suspended boolean, accepting several common phrasings so Ops can
     * label their Status column however reads naturally to them, rather
     * than needing to match one exact string.
     *
     * @param string $text Text from the Monday.com column.
     * @param string|null $customyeslabel This board's configured "label written when suspended" - also accepted as input.
     * @param string|null $customnolabel This board's configured "label written when not suspended" - also accepted as input.
     * @return string|null '1', '0', or null if the text isn't recognised.
     */
    public static function parse_suspended_value(string $text, ?string $customyeslabel = null, ?string $customnolabel = null): ?string {
        $normalised = strtolower(trim($text));

        $truthy = ['yes', 'y', '1', 'true', 'suspended'];
        $falsy = ['no', 'n', '0', 'false', 'not suspended'];

        // A board's own configured labels (used when *writing* to Monday)
        // must also be accepted on the way back *in* - otherwise a
        // 'toMonday' mapping can never recognise its own just-written
        // label on the next poll, comparing canonical '0'/'1' against raw
        // label text forever and rewriting on every single run even
        // though nothing actually changed.
        if ($customyeslabel !== null && $customyeslabel !== '' && strtolower(trim($customyeslabel)) === $normalised) {
            return '1';
        }
        if ($customnolabel !== null && $customnolabel !== '' && strtolower(trim($customnolabel)) === $normalised) {
            return '0';
        }

        if (in_array($normalised, $truthy, true)) {
            return '1';
        }
        if (in_array($normalised, $falsy, true)) {
            return '0';
        }
        return null;
    }

    /**
     * Parse the config string into every well-formed mapping line, WITHOUT
     * checking whether the target field still actually exists in Moodle.
     * Used where the caller needs to see the full configured state -
     * including anything now orphaned - rather than only what's currently
     * safe to execute. Never use this result to actually apply an update;
     * use parse() for that.
     *
     * @return array [monday_column_id => ['type' => ..., 'field' => ..., 'direction' => ...]]
     */
    public static function parse_raw(string $raw): array {
        $mappings = [];
        $lines = preg_split('/\r\n|\r|\n/', trim($raw));

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '=>') === false) {
                continue;
            }
            [$columnid, $target] = array_map('trim', explode('=>', $line, 2));
            if ($columnid === '' || $target === '' || strpos($target, ':') === false) {
                continue;
            }

            $parts = array_map('trim', explode(':', $target, 4));
            $type = $parts[0] ?? '';
            $field = $parts[1] ?? '';
            $direction = $parts[2] ?? self::DEFAULT_DIRECTION;
            $allowclear = ($parts[3] ?? '0') === '1';

            if (!in_array($type, ['standard', 'custom', 'date', 'advanced'], true) || $field === '') {
                continue;
            }
            if (!in_array($direction, self::DIRECTIONS, true)) {
                $direction = self::DEFAULT_DIRECTION;
            }

            $mappings[$columnid] = ['type' => $type, 'field' => $field, 'direction' => $direction, 'allowclear' => $allowclear];
        }

        return $mappings;
    }

    /**
     * Whether a type/field combination is currently valid to sync - i.e.
     * still on the standard-field catalog AND enabled via the site's
     * "Fields available for mapping" setting, still a recognised and
     * enabled advanced field, or (for custom/date) still an existing AND
     * enabled custom profile field.
     *
     * Deliberately checks the site-enabled subset here, not just the full
     * catalog - if an admin disables a field that's already in use by a
     * saved mapping, that mapping should show up as orphaned and stop
     * executing, exactly like it already does when a custom profile
     * field gets deleted entirely. Same mental model, same machinery,
     * just one more way a field can stop being valid.
     */
    public static function is_valid_field(string $type, string $field, ?array $customfields = null): bool {
        $enabled = self::get_enabled_field_keys();

        if ($type === 'standard') {
            return in_array($field, self::SAFE_STANDARD_FIELDS, true) && in_array('std:' . $field, $enabled, true);
        }
        if ($type === 'advanced') {
            return array_key_exists($field, self::ADVANCED_FIELDS) && in_array('adv:' . $field, $enabled, true);
        }
        if ($customfields === null) {
            $customfields = self::get_custom_fields();
        }
        return array_key_exists($field, $customfields) && in_array('profile:' . $field, $enabled, true);
    }

    /**
     * The directions a given type/field may be used in. Standard fields
     * can be restricted (STANDARD_FIELD_DIRECTIONS) and so can Advanced
     * ones (ADVANCED_FIELDS); everything else (custom and date profile
     * fields) is unrestricted.
     *
     * This and get_forced_direction() are the one place that answers
     * "which directions does this field allow" - the wizard validation,
     * the direction-lock script, the save step in mapping.php and the
     * sync engine all ask here rather than each keeping their own idea
     * of it.
     *
     * @return string[]
     */
    public static function get_allowed_directions(string $type, string $field): array {
        if ($type === 'standard' && array_key_exists($field, self::STANDARD_FIELD_DIRECTIONS)) {
            return self::STANDARD_FIELD_DIRECTIONS[$field];
        }
        if ($type === 'advanced' && array_key_exists($field, self::ADVANCED_FIELDS)) {
            return self::ADVANCED_FIELDS[$field];
        }
        return self::DIRECTIONS;
    }

    /**
     * Whether a type/field is an Advanced field holding a Unix timestamp
     * (see TIMESTAMP_FIELDS).
     */
    public static function is_timestamp_field(string $type, string $field): bool {
        return $type === 'advanced' && in_array($field, self::TIMESTAMP_FIELDS, true);
    }

    /**
     * Whether a direction is allowed for a given type/field.
     */
    public static function is_direction_allowed(string $type, string $field, string $direction): bool {
        return in_array($direction, self::get_allowed_directions($type, $field), true);
    }

    /**
     * The one direction a field is locked to, or null if it can be used in
     * more than one. The server uses this to decide the direction for a
     * locked field regardless of what the browser posts - a disabled
     * <select> doesn't submit at all.
     */
    public static function get_forced_direction(string $type, string $field): ?string {
        $allowed = self::get_allowed_directions($type, $field);
        return count($allowed) === 1 ? reset($allowed) : null;
    }

    /**
     * Every locked field and its forced direction, keyed 'type:field' -
     * the same value format the mapping wizard's dropdown uses - for the
     * direction-lock script.
     *
     * @return array ['standard:email' => 'toMonday', ...]
     */
    public static function get_forced_directions(): array {
        $out = [];
        foreach (array_keys(self::STANDARD_FIELD_DIRECTIONS) as $field) {
            $forced = self::get_forced_direction('standard', $field);
            if ($forced !== null) {
                $out['standard:' . $field] = $forced;
            }
        }
        foreach (array_keys(self::ADVANCED_FIELDS) as $field) {
            $forced = self::get_forced_direction('advanced', $field);
            if ($forced !== null) {
                $out['advanced:' . $field] = $forced;
            }
        }
        return $out;
    }

    /**
     * A short " (Moodle -> Monday only)" style note for a field label, or
     * an empty string if the field isn't restricted - so a locked field
     * says so in the dropdown and the admin picker, not just after it's
     * been selected.
     */
    public static function restriction_suffix(string $type, string $field): string {
        $allowed = self::get_allowed_directions($type, $field);
        if (count($allowed) >= count(self::DIRECTIONS)) {
            return '';
        }
        $names = array_map(function ($d) {
            return get_string('direction' . strtolower($d), 'local_mondaysync');
        }, $allowed);
        return ' (' . implode(', ', $names) . ' ' . get_string('only', 'local_mondaysync') . ')';
    }

    /**
     * The column list for loading a matched Moodle user, built from the
     * field catalogue (external review, item 1) - previously a separate
     * hand-written list that wasn't widened when the catalogue grew, so
     * seven newly-added fields read back as blank and were treated as
     * changed on every poll. Anything in the catalogue is loaded; id and
     * idnumber are always loaded since the matching itself needs them.
     */
    public static function get_user_select_fields(): string {
        $fields = array_merge(
            ['id', 'idnumber'],
            self::SAFE_STANDARD_FIELDS,
            array_keys(self::ADVANCED_FIELDS),
            self::INTERNAL_USER_FIELDS
        );
        return implode(', ', array_unique($fields));
    }

    /**
     * Whether a value is acceptable for a fixed-choice Moodle field
     * (country, language, calendar type) - delegated to core_user's own
     * validation, the same check user_update_user() runs internally
     * before it silently replaces an invalid value with the site default
     * (confirmed against core source). An empty value is accepted: it
     * means "no value", handled separately by the blank-clear protection.
     */
    public static function is_fixed_choice_value_valid(string $field, string $value): bool {
        if ($value === '') {
            return true;
        }
        return \core_user::validate([$field => $value]) === true;
    }

    /**
     * Parse the config string into every mapping that's currently safe and
     * valid to execute - this is what the sync engine and mapping wizard
     * defaults should use. Anything pointing at a field that no longer
     * exists, or used with a direction it isn't allowed in, is silently
     * excluded here (by design, for execution safety); use parse_raw() if
     * you need to see it anyway (e.g. to flag it as orphaned in the UI).
     *
     * @return array [monday_column_id => ['type' => 'standard'|'custom'|'date'|'advanced', 'field' => string, 'direction' => string]]
     */
    public static function parse(string $raw): array {
        $rawmappings = self::parse_raw($raw);
        $customfields = null;
        $out = [];

        foreach ($rawmappings as $columnid => $mapping) {
            if (!in_array($mapping['type'], ['standard', 'advanced'], true) && $customfields === null) {
                $customfields = self::get_custom_fields();
            }
            if (!self::is_valid_field($mapping['type'], $mapping['field'], $customfields)) {
                continue;
            }
            if (!self::is_direction_allowed($mapping['type'], $mapping['field'], $mapping['direction'])) {
                continue;
            }
            $out[$columnid] = $mapping;
        }

        return $out;
    }

    /**
     * Build the config string from a structured mappings array (as produced
     * by the mapping wizard form).
     *
     * @param array $mappings [monday_column_id => ['type' => ..., 'field' => ..., 'direction' => ...]]
     */
    public static function build(array $mappings): string {
        $lines = [];
        foreach ($mappings as $columnid => $mapping) {
            $direction = $mapping['direction'] ?? self::DEFAULT_DIRECTION;
            $allowclear = !empty($mapping['allowclear']) ? '1' : '0';
            $lines[] = $columnid . ' => ' . $mapping['type'] . ':' . $mapping['field'] . ':' . $direction . ':' . $allowclear;
        }
        return implode("\n", $lines);
    }

    /**
     * Fetch the list of custom user profile fields available for mapping.
     *
     * @return array [shortname => ['name' => display name, 'datatype' => Moodle datatype, 'category' => category display name]]
     */
    public static function get_custom_fields(): array {
        global $DB;

        $sql = "SELECT f.id, f.shortname, f.name, f.datatype, c.name AS categoryname
                  FROM {user_info_field} f
                  JOIN {user_info_category} c ON c.id = f.categoryid
              ORDER BY c.sortorder, f.sortorder";
        $fields = $DB->get_records_sql($sql);
        $out = [];
        foreach ($fields as $field) {
            $out[$field->shortname] = [
                'name' => format_string($field->name),
                'datatype' => $field->datatype,
                'category' => format_string($field->categoryname),
            ];
        }
        return $out;
    }

    /**
     * Same as get_custom_fields(), but grouped by category name - for
     * rendering the mapping dropdown and the site-level field picker,
     * both of which need custom fields organised by their Moodle-defined
     * category rather than as one flat list. Preserves the category and
     * field ordering get_custom_fields() already sorts by.
     *
     * @return array [categoryname => [shortname => ['name' => ..., 'datatype' => ...]]]
     */
    public static function get_custom_fields_by_category(): array {
        $grouped = [];
        foreach (self::get_custom_fields() as $shortname => $info) {
            $grouped[$info['category']][$shortname] = $info;
        }
        return $grouped;
    }

    /**
     * The prefixed field keys ('std:firstname', 'adv:auth',
     * 'profile:favouritecolour') currently enabled for the mapping
     * dropdown, per the "Fields available for mapping" site setting
     * (admin_setting_fieldpicker). If that setting has never been
     * explicitly saved, falls back to a backward-compatible default -
     * the fields this plugin has always offered, plus every custom
     * profile field currently on the site - so upgrading to this feature
     * doesn't silently orphan any already-working board's mappings until
     * an admin deliberately visits the new setting and changes something.
     *
     * Shared by both the admin_setting_fieldpicker class (to know what's
     * checked when first rendered) and is_valid_field() (to know what's
     * actually safe to offer/execute) - one source of truth for the
     * default rather than two copies of the same fallback logic.
     */
    public static function get_enabled_field_keys(): array {
        $raw = get_config('local_mondaysync', 'enabledfields');

        if ($raw === false) {
            $keys = [];
            foreach (self::DEFAULT_ENABLED_STANDARD_FIELDS as $field) {
                $keys[] = 'std:' . $field;
            }
            foreach (self::DEFAULT_ENABLED_ADVANCED_FIELDS as $field) {
                $keys[] = 'adv:' . $field;
            }
            foreach (array_keys(self::get_custom_fields()) as $shortname) {
                $keys[] = 'profile:' . $shortname;
            }
            return $keys;
        }

        if ($raw === '') {
            return [];
        }

        return explode(',', $raw);
    }

    /**
     * Plain-text description of what a mapping points at, for display in
     * places (like an orphaned-mapping list) that just need to show what
     * used to be configured rather than offer it as a selectable option.
     */
    public static function describe_mapping_target(array $mapping, ?array $customfields = null): string {
        if ($mapping['type'] === 'standard') {
            return get_string('standardfieldoption', 'local_mondaysync', $mapping['field']);
        }

        if ($mapping['type'] === 'advanced') {
            return get_string('advancedfieldoption', 'local_mondaysync', $mapping['field']);
        }

        if ($customfields === null) {
            $customfields = self::get_custom_fields();
        }

        if (array_key_exists($mapping['field'], $customfields)) {
            $a = new \stdClass();
            $a->name = $customfields[$mapping['field']]['name'];
            $a->shortname = $mapping['field'];
            $a->datatype = $customfields[$mapping['field']]['datatype'];
            return get_string('customfieldoption', 'local_mondaysync', $a);
        }

        return get_string('deletedfielddesc', 'local_mondaysync', $mapping['field']);
    }
}