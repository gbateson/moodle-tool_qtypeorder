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
 * Strings for component 'tool_qtypeorder', language 'en'.
 *
 * @package    tool_qtypeorder
 * @copyright  2015 Gordon Bateson, 2026 updated for Moodle 3.9 to 4.5
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['actions'] = 'Actions';
$string['attempts'] = 'Attempts';
$string['backupconfirm'] = 'I have a full, tested backup of the database and moodledata';
$string['backupconfirmrequired'] = 'Please confirm you have a backup before changing anything.';
$string['backupsaved'] = 'An undo backup of each converted question was saved in {$a}';
$string['cannotcontinue'] = 'Sorry, the migration cannot run on this site at the moment.';
$string['categoryid'] = 'Question category id';
$string['categoryid_help'] = 'Only questions in this question category (not its subcategories). 0 = any.';
$string['checkbadcorrect'] = 'Attempt {$a}: the stored correct order does not match the items.';
$string['checkbadcurrent'] = 'Attempt {$a}: the stored order shown is not a complete set of the items.';
$string['checkbadresponse'] = 'Attempt {$a->qaid}: a saved response does not contain exactly the question\'s items.';
$string['checkfailedrolledback'] = 'The self-check found problems, so this question was rolled back and left unconverted.';
$string['checkfewanswers'] = 'The question has {$a} item(s).';
$string['checkfractions'] = 'The items\' positions are not numbered 1, 2, 3 ...';
$string['checkloadquestion'] = 'The question engine could not load the question: {$a}';
$string['checkmore'] = '... and more problems (only the first ones are listed).';
$string['checknooptions'] = 'There is no qtype_ordering_options row.';
$string['checknostate'] = 'Attempt {$a}: there is no stored item order.';
$string['checkoldrows'] = 'Old qtype_order rows are still present.';
$string['checkoldvar'] = 'Attempt {$a->qaid}: old variable "{$a->name}" is still present.';
$string['checkqtype'] = 'The question type is "{$a}", not "ordering".';
$string['checkrender'] = 'Attempt usage {$a->usageid} could not be displayed: {$a->message}';
$string['choosequestions'] = 'Which questions';
$string['countnooptions'] = '{$a} question(s) are marked as "order" but have no qtype_order options. They cannot be converted and are not counted above.';
$string['countnoquestion'] = '{$a} qtype_order options row(s) belong to questions that no longer exist. They are ignored.';
$string['courseid'] = 'Course id';
$string['courseid_help'] = 'Only questions stored in this course\'s question banks (including its quizzes\' banks), or used by a quiz in this course. 0 = any.';
$string['details'] = 'Show how each saved response is converted';
$string['details_help'] = 'With Simulate or Migrate: list every saved student response, showing the position the student picked for each item and the order it becomes. Use this with one or a few questions, and compare a few against what the students really did.';
$string['detailsfor'] = 'Responses for question {$a->id} "{$a->name}"';
$string['dontknow'] = 'I don\'t know';
$string['errorbackupwrite'] = 'Could not write the undo backup file {$a}';
$string['errorexistinganswers'] = 'The question already has rows in question_answers.';
$string['errorexistingoptions'] = 'The question already has a qtype_ordering_options row.';
$string['errornocategory'] = 'Cannot find the question\'s category.';
$string['errornooptions'] = 'The question has no question_order row.';
$string['errortoofewitems'] = 'The question has {$a} item(s); an ordering question needs at least 2.';
$string['errorwrongqtype'] = 'The question type is "{$a}", not "order".';
$string['intro'] = 'This tool converts OLD Ordering questions (qtype_order) to the standard Ordering question type (qtype_ordering), including all existing attempts at them.

**Take a full backup of the database and moodledata before migrating.**

There are currently **{$a->questions}** OLD Ordering question(s) with **{$a->attempts}** attempt(s) on this site. {$a->converted} question(s) converted by this tool have an undo backup (undo is refused if their attempts have changed since).

For large sites, use the command-line script instead: `php admin/tool/qtypeorder/cli/migrate.php --help`';
$string['items'] = 'Items';
$string['limit'] = 'Maximum questions to process';
$string['limit_help'] = 'Process at most this many questions in one run (0 = all). Use a small number for a first trial, or to avoid web server time-outs.';
$string['messages'] = 'Messages';
$string['modecheck'] = 'Check converted';
$string['modeshelp'] = '<ul>
<li><strong>Preview</strong>: look at each question and report problems. Changes nothing.</li>
<li><strong>Simulate</strong>: do the full conversion of each question and its attempts, self-check the result, then roll everything back. Changes nothing, but finds every problem a real run would hit.</li>
<li><strong>Migrate</strong>: convert for real. An undo backup of each question is saved first, and each question is self-checked afterwards (and rolled back if the check fails).</li>
<li><strong>Check converted</strong>: self-check questions this tool has already converted.</li>
<li><strong>Undo</strong>: put the listed questions back as they were. Refused if a question has new attempts or grading since it was converted.</li>
</ul>
Suggested order: Preview everything, Simulate one question with details, Migrate it and look at it in the question bank and quiz reviews, then Simulate and Migrate everything (in batches if needed).';
$string['modemigrate'] = 'Migrate';
$string['modepreview'] = 'Preview';
$string['modesimulate'] = 'Simulate';
$string['modeundo'] = 'Undo';
$string['needsattention'] = 'Questions';
$string['newresponse'] = 'Converted order';
$string['nomatch'] = 'No questions matched.';
$string['noorderingtable'] = 'The Ordering question type (qtype_ordering) is not installed: its table qtype_ordering_options is missing. It is part of core from Moodle 4.3. On Moodle 3.9 to 4.2, install the qtype_ordering plugin first.';
$string['noordertable'] = 'The database tables for the OLD Ordering question type (question_order, question_order_sub) are missing, so there is nothing to convert.';
$string['noresponse'] = '(no response)';
$string['nothingchanged'] = 'Nothing has been changed.';
$string['oldresponse'] = 'Order shown → position picked';
$string['pluginname'] = 'Migrate Ordering questions';
$string['privacy:metadata'] = 'The Migrate Ordering questions tool does not store any personal data. Undo backups in moodledata contain copies of quiz attempt data, which is deleted with them.';
$string['progress'] = 'Processing question {$a->index} of {$a->total}';
$string['questionattemptid'] = 'Attempt';
$string['questionid'] = 'Question id';
$string['questionids'] = 'Question ids';
$string['questionids_help'] = 'One or more question ids, separated by commas or spaces. Leave empty for all questions.';
$string['questionidsinvalid'] = 'Use only numbers separated by commas or spaces.';
$string['results'] = 'Results: {$a}';
$string['statuschecked'] = 'Checked, OK';
$string['statuscheckfailed'] = 'Check found problems';
$string['statusconverted'] = 'Converted';
$string['statusfailed'] = 'Failed (rolled back)';
$string['statusready'] = 'Ready';
$string['statussimulated'] = 'Simulated';
$string['statusskipped'] = 'Skipped';
$string['statusundone'] = 'Undone';
$string['step'] = 'Step';
$string['summary'] = '{$a->questions} question(s): {$a->counts}. Attempts: {$a->attempts}. Questions with warnings: {$a->warnings}.';
$string['undobadbackup'] = 'The undo backup file {$a} cannot be read.';
$string['undomissingfile'] = 'The content of file "{$a}" is no longer in moodledata, so it cannot be restored.';
$string['undoneedsids'] = 'Undo needs the ids of the questions to put back.';
$string['undonewattempts'] = 'Attempts at this question have been added, removed or regraded since it was converted, so it cannot be put back.';
$string['undonewsteps'] = 'Attempts at this question have changed since it was converted (for example regraded or manually graded), so it cannot be put back.';
$string['undonobackup'] = 'There is no undo backup for this question.';
$string['undonotconverted'] = 'The question type is "{$a}", not "ordering", so there is nothing to undo.';
$string['warningbadposition'] = 'An item has a non-numeric or padded position value ("{$a}").';
$string['warningblankitems'] = '{$a} blank item(s) will be dropped (qtype_order ignored them too).';
$string['warningduplicateposition'] = 'Two or more items share the same correct position; they will be ordered by creation order.';
$string['warningduplicatetext'] = 'Two or more items have identical text. qtype_ordering cannot tell them apart, so grading of student responses for this question may be wrong. Edit the items so each is unique.';
$string['warningnoshownumcorrect'] = 'The question is set to show the number of correct items, but the installed version of qtype_ordering doesn\'t have that setting. It will be dropped.';
$string['warningnostate'] ='{$a} attempt(s) had no stored item order; the items were placed in creation order.';
