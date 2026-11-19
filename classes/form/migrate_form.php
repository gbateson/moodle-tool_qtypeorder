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

namespace tool_qtypeorder\form;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');

use tool_qtypeorder\migrator;

/**
 * Preview / simulate / migrate / check / undo form.
 *
 * @package    tool_qtypeorder
 * @copyright  2015 Gordon Bateson, 2026 updated for Moodle 3.9 to 4.5
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class migrate_form extends \moodleform {

    /**
     * Form definition.
     */
    public function definition() {
        $mform = $this->_form;
        $tool = 'tool_qtypeorder';

        $mform->addElement('header', 'choosehdr', get_string('choosequestions', $tool));
        $mform->addElement('text', 'questionids', get_string('questionids', $tool), ['size' => 40]);
        $mform->setType('questionids', PARAM_TEXT);
        $mform->addHelpButton('questionids', 'questionids', $tool);

        $mform->addElement('text', 'courseid', get_string('courseid', $tool), ['size' => 8]);
        $mform->setType('courseid', PARAM_INT);
        $mform->setDefault('courseid', 0);
        $mform->addHelpButton('courseid', 'courseid', $tool);

        $mform->addElement('text', 'categoryid', get_string('categoryid', $tool), ['size' => 8]);
        $mform->setType('categoryid', PARAM_INT);
        $mform->setDefault('categoryid', 0);
        $mform->addHelpButton('categoryid', 'categoryid', $tool);

        $mform->addElement('text', 'limit', get_string('limit', $tool), ['size' => 6]);
        $mform->setType('limit', PARAM_INT);
        $mform->setDefault('limit', 0);
        $mform->addHelpButton('limit', 'limit', $tool);

        $mform->addElement('advcheckbox', 'details', get_string('details', $tool));
        $mform->addHelpButton('details', 'details', $tool);

        $mform->addElement('header', 'actionhdr', get_string('actions', $tool));
        $mform->setExpanded('actionhdr');
        $mform->addElement('static', 'modeshelp', '', get_string('modeshelp', $tool));
        $mform->addElement('advcheckbox', 'backupconfirm', get_string('backupconfirm', $tool));

        $buttons = [];
        foreach (migrator::MODES as $mode) {
            $buttons[] = $mform->createElement('submit', $mode, get_string('mode' . $mode, $tool));
        }
        $mform->addGroup($buttons, 'buttonar', '', ' ', false);
        $mform->closeHeaderBefore('buttonar');
    }

    /**
     * The mode chosen by the button that was pressed.
     *
     * @param \stdClass $data
     * @return string
     */
    public static function get_mode(\stdClass $data): string {
        foreach (migrator::MODES as $mode) {
            if (!empty($data->$mode)) {
                return $mode;
            }
        }
        return 'preview';
    }

    /**
     * Require the backup confirmation before changing anything, and ids for undo.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        $tool = 'tool_qtypeorder';
        if ((!empty($data['migrate']) || !empty($data['undo'])) && empty($data['backupconfirm'])) {
            $errors['backupconfirm'] = get_string('backupconfirmrequired', $tool);
        }
        if (!empty($data['undo']) && !migrator::parse_ids($data['questionids'] ?? '')) {
            $errors['questionids'] = get_string('undoneedsids', $tool);
        }
        if (trim($data['questionids'] ?? '') !== '' && !preg_match('/^[\d\s,;]+$/', $data['questionids'])) {
            $errors['questionids'] = get_string('questionidsinvalid', $tool);
        }
        if (!empty($data['limit']) && $data['limit'] < 0) {
            $errors['limit'] = get_string('error');
        }
        return $errors;
    }
}
