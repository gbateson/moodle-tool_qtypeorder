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
 * admin/tool/qtypeorder.php
 *
 * @package    tool
 * @subpackage qtypeorder
 * @copyright  2015 Gordon Bateson {@link http://quizport.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** Prevent direct access to this script */
defined('MOODLE_INTERNAL') || die;

/** Include required files */
require_once("$CFG->libdir/formslib.php");

/**
 * tool_qtypeorder_form
 *
 * @package    tool
 * @subpackage qtypeorder
 * @copyright  2015 Gordon Bateson (gordon.bateson@gmail.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since      Moodle 2.0
 */
class tool_qtypeorder_form extends moodleform {

    /**
     * constructor
     */
    public function tool_qtypeorder_form($action=null, $customdata=null, $method='post', $target='', $attributes=null, $editable=true) {
        parent::moodleform($action, $customdata, $method, $target, $attributes, $editable);
    }

    /**
     * definition
     */
    public function definition() {
        $mform = $this->_form;
        $tool = 'tool_qtypeorder';

        // number of users
        $name = 'confirm';
        $label = get_string($name);
        $mform->addElement('selectyesno', $name, $label);
        $mform->setType($name, PARAM_INT);
        $mform->setDefault($name, 0);

        // ==================================
        // action buttons
        // ==================================
        //
        $label = get_string('go');
        $this->add_action_buttons(true, $label);
    }

    /**
     * add_heading
     *
     * @param object $mform
     * @param string $name
     * @param string $plugin
     * @param boolean $expanded
     */
    public function add_heading($mform, $name, $plugin, $expanded) {
        $label = get_string($name, $plugin);
        $mform->addElement('header', $name, $label);
        if (method_exists($mform, 'setExpanded')) {
            $mform->setExpanded($name, $expanded);
        }
    }

    /**
     * migrate_qtype_order
     */
    public function migrate_qtype_order() {
        global $DB;

        // get form data
        $data = $this->get_data();
        $time = time();

        if (! $data->confirm) {
            return false;
        }
        if (! $orders = $DB->get_records('question_order')) {
            return false;
        }

        $feedbackfields = array('correctfeedback', 'partiallycorrectfeedback', 'incorrectfeedback');
        $purge_all_caches = false;

        // migrate each $order record
        foreach ($orders as $order) {

            $questionid = $order->question;
            $layouttype = ($order->horizontal ? 1 : 0);

            // transfer basic fields
            $ordering = (object)array(
                'questionid'  => $questionid,
                'layouttype'  => $layouttype,
                'selecttype'  => 0, // ALL
                'selectcount' => 0,
            );

            // add feedback fields - cautiously :-)
            foreach ($feedbackfields as $feedbackfield) {
                $formatfield = $feedbackfield.'format';
                if (isset($order->$feedbackfield)) {
                    $ordering->$feedbackfield = $order->$feedbackfield;
                } else {
                    $ordering->$feedbackfield = '';
                }
                if (isset($order->$formatfield)) {
                    $ordering->$formatfield = $order->$formatfield;
                } else {
                    $ordering->$feedbackfield = FORMAT_MOODLE;
                }
            }

            // insert the new $ordering record
            if ($ordering->id = $DB->insert_record('qtype_ordering_options', $ordering)) {

                // change the "qtype" of the question to "ordering"
                $DB->set_field('question', 'qtype', 'ordering', array('id' => $questionid));

                // remove the old "order" question
                $DB->delete_records('question_order', array('question' => $questionid));

                // transfer the subquestions (= items to be ordered)
                if ($subs = $DB->get_records('question_order_sub', array('question' => $questionid), 'answertext')) {
                    foreach ($subs as $sub) {
                        $answer = (object)array(
                            'question' => $questionid,
                            'answer'   => $sub->questiontext,
                            'answerformat' => $sub->questiontextformat,
                            'fraction' => floatval($sub->answertext),
                            'feedback' => '',
                            'feedbackformat' => FORMAT_MOODLE
                        );
                        $answer->id = $DB->insert_record('question_answers', $answer);
                    }
                    // remove old subquestions
                    $DB->delete_records('question_order_sub', array('question' => $questionid));
                }

                //
                $purge_all_caches = true;
            }
        }

        if ($purge_all_caches) {
            purge_all_caches();
        }
    }
}
