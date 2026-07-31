<?php
namespace local_mondaysync;

defined('MOODLE_INTERNAL') || die();

/**
 * Parses and builds the local_mondaysync/fieldmappings config value, shared
 * between the sync engine and the column-mapping wizard so both agree on
 * exactly the same format.
 *
 * Format: one mapping per line, "monday_column_id => type:field:direction",
 * where type is one of 'standard', 'custom', 'date', or 'advanced', and
 * direction is one of 'toMoodle' (Monday -> Moodle, the original one-way
 * behaviour and the default), 'toMonday' (Moodle -> Monday), or 'both'.
 *
 * The direction segment is optional on read for backward compatibility with
 * mappings saved before two-way sync existed (a bare "type:field" with no
 * third part defaults to 'toMoodle', preserving exactly the old behaviour).
 * It's always written on save.
 */
class mapping_util {

    /** @var string[] Standard Moodle user fields safe to expose in the mapping wizard. */
    const SAFE_STANDARD_FIELDS = [
        'firstname', 'lastname', 'alternatename',
        'institution', 'department', 'address',
        'phone1', 'phone2', 'description', 'city',
    ];

    /**
     * Advanced (core account-property) fields, each with the set of
     * directions it's actually allowed to be used in. auth/suspended
     * genuinely control login access, so an admin choosing these is
     * expected to understand that risk - the wizard labels them clearly.
     * lastlogin is restricted to toMonday only: Moodle overwrites it
     * itself on every real login, so a write into it from Monday would
     * just get clobbered next time the person signs in.
     *
     * @var array [fieldname => string[] of allowed directions]
     */
    const ADVANCED_FIELDS = [
        'auth' => ['toMoodle', 'toMonday', 'both'],
        'suspended' => ['toMoodle', 'toMonday', 'both'],
        'lastlogin' => ['toMonday'],
    ];

    /** @var string[] Valid sync directions. */
    const DIRECTIONS = ['toMoodle', 'toMonday', 'both'];

    /** @var string Default direction for mappings saved before direction existed. */
    const DEFAULT_DIRECTION = 'toMoodle';

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
     * @return string|null '1', '0', or null if the text isn't recognised.
     */
    public static function parse_suspended_value(string $text): ?string {
        $normalised = strtolower(trim($text));

        $truthy = ['yes', 'y', '1', 'true', 'suspended'];
        $falsy = ['no', 'n', '0', 'false', 'not suspended'];

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

            $parts = array_map('trim', explode(':', $target, 3));
            $type = $parts[0] ?? '';
            $field = $parts[1] ?? '';
            $direction = $parts[2] ?? self::DEFAULT_DIRECTION;

            if (!in_array($type, ['standard', 'custom', 'date', 'advanced'], true) || $field === '') {
                continue;
            }
            if (!in_array($direction, self::DIRECTIONS, true)) {
                $direction = self::DEFAULT_DIRECTION;
            }

            $mappings[$columnid] = ['type' => $type, 'field' => $field, 'direction' => $direction];
        }

        return $mappings;
    }

    /**
     * Whether a type/field combination is currently valid to sync - i.e.
     * still on the standard-field whitelist, still a recognised advanced
     * field, or (for custom/date) still an existing custom profile field.
     */
    public static function is_valid_field(string $type, string $field, ?array $customfields = null): bool {
        if ($type === 'standard') {
            return in_array($field, self::SAFE_STANDARD_FIELDS, true);
        }
        if ($type === 'advanced') {
            return array_key_exists($field, self::ADVANCED_FIELDS);
        }
        if ($customfields === null) {
            $customfields = self::get_custom_fields();
        }
        return array_key_exists($field, $customfields);
    }

    /**
     * Whether a direction is allowed for a given type/field. Only
     * 'advanced' fields can have a restricted set (see ADVANCED_FIELDS);
     * everything else just needs to be one of the three general directions.
     */
    public static function is_direction_allowed(string $type, string $field, string $direction): bool {
        if ($type === 'advanced' && array_key_exists($field, self::ADVANCED_FIELDS)) {
            return in_array($direction, self::ADVANCED_FIELDS[$field], true);
        }
        return in_array($direction, self::DIRECTIONS, true);
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
            $lines[] = $columnid . ' => ' . $mapping['type'] . ':' . $mapping['field'] . ':' . $direction;
        }
        return implode("\n", $lines);
    }

    /**
     * Fetch the list of custom user profile fields available for mapping.
     *
     * @return array [shortname => ['name' => display name, 'datatype' => Moodle datatype]]
     */
    public static function get_custom_fields(): array {
        global $DB;

        $fields = $DB->get_records('user_info_field', null, 'sortorder', 'id, shortname, name, datatype');
        $out = [];
        foreach ($fields as $field) {
            $out[$field->shortname] = [
                'name' => format_string($field->name),
                'datatype' => $field->datatype,
            ];
        }
        return $out;
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