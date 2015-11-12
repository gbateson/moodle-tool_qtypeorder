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
 * Search and qtypeorder strings throughout all texts in the whole database
 *
 * @package    tool_qtypeorder
 * @copyright  1999 onwards Martin Dougiamas (http://dougiamas.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('NO_OUTPUT_BUFFERING', true);

require_once('../../../config.php');
require_once($CFG->dirroot.'/course/lib.php');
require_once($CFG->dirroot.'/lib/adminlib.php');

// for Moodle <=2.5, we must include the form file manually
require_once($CFG->dirroot.'/admin/tool/qtypeorder/classes/form.php');

admin_externalpage_setup('toolqtypeorder');

$form = new tool_qtypeorder_form();

if ($form->is_cancelled()) {
    echo redirect(new moodle_url('/admin/index.php'));
}

echo $OUTPUT->header();

echo $OUTPUT->heading(get_string('pluginname', 'tool_qtypeorder'));

if ($form->is_submitted() && $form->is_validated()) {
    $form->migrate_qtype_order();
}

echo $OUTPUT->box_start(array('class' => 'nottoowide'));
if ($count = $DB->get_field_sql('SELECT COUNT(*) FROM {question_order}')) {
    $text = get_string('pluginname_help', 'tool_qtypeorder', $count);
} else {
    $text = get_string('noquestions', 'tool_qtypeorder');
}
$text = format_text($text, FORMAT_MARKDOWN);
echo $OUTPUT->notification($text);
echo $OUTPUT->box_end();

if ($count) {
    $form->display();
}

echo $OUTPUT->footer();
