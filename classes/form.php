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
        $this->add_action_buttons(true, get_string('go'));
    }

    /**
     * migrate_qtype_order
     */
    public function migrate_qtype_order() {
        global $CFG, $DB;

        // get form data
        $data = $this->get_data();
        $time = time();

        if (! $data->confirm) {
            return false;
        }
        if (! $rs = $DB->get_recordset('question_order', array())) {
            return false;
        }
        if (! $count = $DB->count_records('question_order', array())) {
            return false;
        }

        $feedbackfields = array('correctfeedback', 'partiallycorrectfeedback', 'incorrectfeedback');
        $purge_caches = false;

        // search/replace strings to remove tags from simple <p>...</p> in question text
        $qtext_search = '/^\s*<p>\s*([^<>]*)\s*<\/p>\s*$/';
        $qtext_replace = '$1';

        // search string to locate qtype_order info in question summary
        $qsummary_search = '/\s*\{[^\}]*\}\s*$/';

        // cache the SQL to determine the length of the name field
        // this is used for "natural sorting" of the step data
        $sql_length_dname = $DB->sql_length('d.name');

        // password salt may be needed to create unique md5 keys
        if (isset($CFG->passwordsaltmain)) {
            $salt = $CFG->passwordsaltmain;
        } else {
            $salt = ''; // complex_random_string()
        }

        // cache of which question_attempts have been updated
        $question_attempts = array();

        // set up progress bar
        $index = 0;
        $bar = new progress_bar('fixsumgrades', 500, true);
        $strupdating = get_string('migratingquestions', 'tool_qtypeorder');

        // migrate each $order record
        foreach ($rs as $order) {
            //upgrade_set_timeout(); // 3 mins

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

                    // remove old subquestions
                    $DB->delete_records('question_order_sub', array('question' => $questionid));

                    foreach ($subs as $id => $sub) {

                        // tidy up question text
                        $sub->questiontext = preg_replace($qtext_search, $qtext_replace, $sub->questiontext);

                        // check this response has not already been converted
                        //  - should only happen during development of this tool
                        $params = array('question' => $questionid,
                                        'fraction' => floatval($sub->answertext));
                        if ($answer = $DB->get_records('question_answers', $params)) {
                            $answer = reset($answer);
                            $answer->answer = $sub->questiontext;
                            $DB->update_record('question_answers', $answer);
                        } else {

                            // add new answer record
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

                        // cache secondary values for this $sub record
                        $subs[$id]->answerid = $answer->id;
                        $subs[$id]->md5key = 'ordering_item_'.md5($salt.$sub->questiontext);
                    }

                    // define the order of the correct response
                    //  - referred to as "choiceorder" in qtype_order
                    //  - should be the same as the "_choiceorder" step data
                    $correctresponse = array_keys($subs);

                    // initialize the order of the current response
                    //  - referred to as "stemorder" in qtype_order
                    //  - will be populated from "_stemorder" step data
                    //  - will be updated from "sub[0-9]+" step data
                    $currentresponse = array();

                    // set names of first and last attempt_step_data records
                    // that are used to denote the current order of items
                    $i_min = 0;
                    $i_max = (count($subs) - 1);

                } else {
                    // shouldn't happen !!
                    $correctresponse = array();
                    $currentresponse = array();
                    $i_min = -1;
                    $i_max = -1;
                }

                // initialize array use to hold unique md5keys for answers
                $md5keys = array();

                // convert any responses for this question
                // (stored in the "question_attempt_step_data" table)
                $select = 'd.id, d.attemptstepid, d.name, d.value, '.
                          's.questionattemptid, s.sequencenumber, s.state, '.
                          'a.questionusageid, a.slot, a.questionid, a.questionsummary, a.rightanswer, a.responsesummary';
                $from   = '{question_attempt_step_data} d '.
                          'LEFT JOIN {question_attempt_steps} s ON s.id = d.attemptstepid '.
                          'LEFT JOIN {question_attempts} a ON a.id = s.questionattemptid';
                $where  = 'a.questionid = ?';
                $order  = "a.questionusageid, a.slot, s.sequencenumber, $sql_length_dname, d.name"; // natural sort of d.name
                $params = array($questionid);
                if ($datas = $DB->get_records_sql("SELECT $select FROM $from WHERE $where ORDER BY $order", $params)) {

                    foreach ($datas as $data) {

                        $id = $data->questionattemptid;
                        if (empty($question_attempts[$id])) {
                            $question_attempts[$id] = true;
                            if ($attempt = $DB->get_record('question_attempts', array('id' => $id))) {
                                $attempt->questionsummary = preg_replace($qsummary_search, '', $attempt->questionsummary);
                                $attempt->rightanswer     = '';
                                $attempt->responsesummary = '';
                                $DB->update_record('question_attempts', $attempt);
                            }
                        }

                        switch ($data->name) {

                            case '_stemorder': // the order displayed at the start of this attempt
                                $this->convert_step_data($subs, $data, '_currentresponse', $currentresponse);
                                $i_max = (count($currentresponse) - 1);
                                break;

                            case '_choiceorder': // the correct order
                                $this->convert_step_data($subs, $data, '_correctresponse', $correctresponse);
                                break;

                            case '_correctresponse':
                                // these data records have already been migrated
                                // and are waiting for the question type
                                // to be changed from "order" to "ordering"
                                // this should only happen during development of this tool
                                $this->revert_step_data($subs, $data, $correctresponse);
                                break;

                            case '_currentresponse':
                                // these data records have already been migrated
                                // and are waiting for the question type
                                // to be changed from "order" to "ordering"
                                // this should only happen during development of this tool
                                $this->revert_step_data($subs, $data, $currentresponse);
                                break;

                            case '-finish':
                                // do nothing
                                break;

                            default:
                                if (substr($data->name, 0, 3)=='sub') {
                                    $i = intval(substr($data->name, 3));
                                    if ($i==$i_min) {
                                        $md5keys = array();
                                    }
                                    if ($pos = intval($data->value)) {
                                        $id = $currentresponse[$i];
                                        $md5keys[$pos] = $subs[$id]->md5key;
                                    }
                                    if ($i==$i_max) {
                                        // update this $data record
                                        ksort($md5keys);
                                        $this->update_step_data($data, 'response_'.$questionid, implode(',', $md5keys));
                                        $md5keys = array();
                                    } else {
                                        // remove this $data record
                                        $this->delete_step_data($data);
                                    }
                                } else {
                                    // unknown step data - shouldn't happen !!
                                    $this->delete_step_data($data);
                                }
                        } // end switch $data->name
                    } // end foreach $datas
                } // end if $datas

                // force caches to be purged
                $purge_caches = true;

            } // end if insert_record

            $index++;
            $bar->update($index, $count, $strupdating.": ($index/$count)");

        } // end foreach $rs
        $rs->close();

        if ($purge_caches) {
            purge_all_caches();
        }
    }

    /**
     * convert_step_data
     *     convert ids from DB table: question_order_sub (used by qtype_order)
     *          to ids from DB table: question_answer (used by qtype_ordering)
     *     update record in DB table: question_attempt_step_data
     *
     * @param array  $subs records from DB table: question_order_subs
     * @param object $olddata a record from DB table: question_attempt_step_data
     * @param string $newname the new name for this $olddata record
     * @param array  $ids (passed by reference) array of question_order_subs ids
     * @return boolean TRUE if $olddata record was updated; otherwise FALSE
     */
    protected function convert_step_data($subs, $olddata, $newname, &$ids) {
        global $DB;
        $ids = $olddata->value;
        $ids = explode(',', $ids);
        $ids = array_filter($ids);
        $ids = array_intersect($ids, array_keys($subs));
        $newvalue = array();
        foreach ($ids as $i => $id) {
            $newvalue[$i] = $subs[$id]->answerid;
        }
        $newvalue = implode(',', $newvalue);
        return $this->update_step_data($olddata, $newname, $newvalue);
    }

    /**
     * revert_step_data
     *
     * @param object $data a record from DB table: question_attempt_step_data
     * @param array  $ids (passed by reference) array of question_order_subs ids
     */
    protected function revert_step_data($subs, $data, &$ids) {
        $ids = explode(',', $data->value);
        foreach ($ids as $i => $id) {
            foreach ($subs as $sub) {
                if ($sub->answerid==$id) {
                    $ids[$i] = $sub->id;
                    break;
                }
            }
        }
    }

    /**
     * update_step_data
     *
     * @param object $olddata a record from DB table: question_attempt_step_data
     * @param string $newname
     * @param string $newvalue
     */
    protected function update_step_data($olddata, $newname, $newvalue) {
        global $DB;
        $newdata = (object)array(
            'id'            => $olddata->id,
            'attemptstepid' => $olddata->attemptstepid,
            'name'          => $newname,
            'value'         => $newvalue,
        );
        return $DB->update_record('question_attempt_step_data', $newdata);
    }

    /**
     * delete_step_data
     *
     * @param object $data a record from DB table: question_attempt_step_data
     */
    protected function delete_step_data($data) {
        global $DB;
        return $DB->delete_records('question_attempt_step_data', array('id' => $data->id));
    }
}
