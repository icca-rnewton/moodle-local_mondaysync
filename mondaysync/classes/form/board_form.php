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
 * Form for adding or editing a connected board.
 *
 * @package    local_mondaysync
 * @copyright  2026 Inns of Court College of Advocacy (Part of COIC)
 * @license    http://www.gnu.org/licenses/GPL-3.0 GNU GPL v3 or later
 */

namespace local_mondaysync\form;

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->libdir . '/formslib.php');

class board_form extends \moodleform {

    public function definition() {
        $mform = $this->_form;

        $mform->addElement('text', 'name', get_string('boardname', 'local_mondaysync'), ['size' => 40]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', get_string('required'), 'required', null, 'server');
        $mform->addElement('static', 'name_desc', '', get_string('boardname_desc', 'local_mondaysync'));

        $mform->addElement('text', 'boardid', get_string('boardidorurl', 'local_mondaysync'), ['size' => 50]);
        $mform->setType('boardid', PARAM_RAW_TRIMMED);
        $mform->addRule('boardid', get_string('required'), 'required', null, 'server');
        $mform->addElement('static', 'boardid_desc', '', get_string('boardidorurl_desc', 'local_mondaysync'));

        $mform->addElement('advcheckbox', 'enabled', get_string('boardenabled', 'local_mondaysync'));
        $mform->setDefault('enabled', 1);
        $mform->addElement('static', 'enabled_desc', '', get_string('boardenabled_desc', 'local_mondaysync'));

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);

        $this->add_action_buttons(true, get_string('saveboard', 'local_mondaysync'));
    }

    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        if (!empty($data['boardid'])) {
            $parsed = \local_mondaysync\board_repository::parse_board_input($data['boardid']);

            if (!preg_match('/^\d+$/', $parsed['boardid'])) {
                $errors['boardid'] = get_string('invalidboardid', 'local_mondaysync');
            } else if (\local_mondaysync\board_repository::boardid_in_use($parsed['boardid'], (int)($data['id'] ?? 0))) {
                $errors['boardid'] = get_string('boardidduplicate', 'local_mondaysync');
            }
        }

        return $errors;
    }
}
