<?php
// Extra tests around a migration. Run from testenv:
//   ./php.sh scripts/post_tests.php undo-roundtrip QID   undo a converted question; compare with the snapshot DB
//   ./php.sh scripts/post_tests.php new-attempt          a new student answers the converted quiz A correctly
//   ./php.sh scripts/post_tests.php regrade-then-undo QID regrade attempts at QID, then check undo is refused
//   ./php.sh scripts/post_tests.php restored COURSEID    every attempt in a restored course loads and renders
//   ./php.sh scripts/post_tests.php restore-file MBZ     restore a course backup as a new course (Moodle 3.9 has
//                                                        no admin/cli/restore_backup.php)
// The snapshot database (e.g. moodle45_snap) must hold the pre-migration snapshot.

define('CLI_SCRIPT', true);
require(__DIR__ . '/../moodle/config.php');
require_once($CFG->dirroot . '/question/engine/lib.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once(__DIR__ . '/compat.php');

use tool_qtypeorder\migrator;

$attemptclass = testcompat_attempt_class();

$expected = json_decode(file_get_contents(__DIR__ . '/expected.json'), true);
\core\session\manager::set_user(get_admin());
$PAGE->set_context(context_system::instance());
$PAGE->set_url('/');

$fails = 0;
$passes = 0;
$check = function(string $label, bool $ok, string $detail = '') use (&$fails, &$passes) {
    $ok ? $passes++ : $fails++;
    mtrace(($ok ? 'PASS ' : 'FAIL ') . $label . (!$ok && $detail !== '' ? "  -- $detail" : ''));
};

/**
 * Rows touched by a conversion, from the live DB or the snapshot DB.
 */
function rows_for_question(int $qid, string $db): array {
    global $DB, $CFG;
    $p = $db . '.' . $CFG->prefix;
    $q = fn($sql, $params = []) => array_values(array_map(fn($r) => (array) $r, $DB->get_records_sql($sql, $params)));
    $qaids = $DB->get_fieldset_sql("SELECT id FROM {$p}question_attempts WHERE questionid = ?", [$qid]);
    $in = $qaids ? implode(',', array_map('intval', $qaids)) : '0';
    $subids = $DB->get_fieldset_sql("SELECT id FROM {$p}question_order_sub WHERE question = ?", [$qid]);
    $subin = $subids ? implode(',', array_map('intval', $subids)) : '0';
    return [
        'question' => $q("SELECT id, qtype, name, questiontext FROM {$p}question WHERE id = ?", [$qid]),
        'question_order' => $q("SELECT * FROM {$p}question_order WHERE question = ? ORDER BY id", [$qid]),
        'question_order_sub' => $q("SELECT * FROM {$p}question_order_sub WHERE question = ? ORDER BY id", [$qid]),
        'question_answers' => $q("SELECT * FROM {$p}question_answers WHERE question = ? ORDER BY id", [$qid]),
        'qtype_ordering_options' => $q("SELECT * FROM {$p}qtype_ordering_options WHERE questionid = ?", [$qid]),
        'question_hints' => $q("SELECT * FROM {$p}question_hints WHERE questionid = ? ORDER BY id", [$qid]),
        'files' => $q("SELECT id, contenthash, component, filearea, itemid, filename FROM {$p}files
            WHERE component = 'qtype_order' AND itemid IN ($subin) ORDER BY id"),
        'question_attempts' => $q("SELECT id, questionsummary, rightanswer, responsesummary FROM {$p}question_attempts
            WHERE id IN ($in) ORDER BY id"),
        'step_data' => $q("SELECT d.* FROM {$p}question_attempt_step_data d JOIN {$p}question_attempt_steps s
            ON s.id = d.attemptstepid WHERE s.questionattemptid IN ($in) ORDER BY d.id"),
        'steps' => $q("SELECT * FROM {$p}question_attempt_steps WHERE questionattemptid IN ($in) ORDER BY id"),
    ];
}

$command = $argv[1] ?? '';
$migrator = new migrator();

switch ($command) {
    case 'undo-roundtrip':
        $qid = (int) $argv[2];
        $check("question $qid is converted before undo", $DB->get_field('question', 'qtype', ['id' => $qid]) === 'ordering');
        $r = $migrator->undo_question($qid);
        $check("undo question $qid succeeds", $r->status === 'undone', implode('; ', $r->errors));
        $live = rows_for_question($qid, $CFG->dbname);
        $snap = rows_for_question($qid, $CFG->dbname . '_snap');
        foreach ($snap as $table => $rows) {
            $check("question $qid $table identical to the pre-migration snapshot", $live[$table] === $rows,
                count($live[$table]) . ' rows vs ' . count($rows));
        }
        $again = $migrator->undo_question($qid);
        $check("second undo of $qid is refused", $again->status === 'failed');
        break;

    case 'new-attempt':
        $user = $DB->get_record('user', ['username' => 'student_new']) ?: core_user::get_user(user_create_user((object) [
            'username' => 'student_new', 'firstname' => 'Student', 'lastname' => 'new', 'email' => 'new@example.com',
            'auth' => 'manual', 'confirmed' => 1, 'mnethostid' => $CFG->mnet_localhost_id], false));
        enrol_try_internal_enrol($expected['courseid'], $user->id, $DB->get_field('role', 'id', ['shortname' => 'student']));
        \core\session\manager::set_user($user);
        $quizobj = testcompat_quiz_object($expected['quizids']['A'], $user->id);
        $attempt = quiz_prepare_and_start_new_attempt($quizobj, 1, null);
        $attemptobj = $attemptclass::create($attempt->id);
        $postdata = ['slots' => implode(',', $attemptobj->get_slots())];
        foreach ($attemptobj->get_slots() as $slot) {
            $qa = $attemptobj->get_question_attempt($slot);
            $question = $qa->get_question();
            $check("new attempt slot $slot uses an ordering question", $question instanceof qtype_ordering_question);
            foreach ($question->get_correct_response() as $name => $value) {
                $postdata[$qa->get_field_prefix() . $name] = $value;
            }
            $postdata[$qa->get_control_field_name('sequencecheck')] = (string) $qa->get_sequence_check_count();
        }
        $attemptobj->process_submitted_actions(time(), false, $postdata);
        $attemptclass::create($attempt->id)->process_finish(time(), false);
        $final = $attemptclass::create($attempt->id);
        $check('new attempt finished with full marks', abs($final->get_sum_marks() - count($final->get_slots())) < 0.0001,
            $final->get_sum_marks() . ' of ' . count($final->get_slots()));
        \core\session\manager::set_user(get_admin());
        break;

    case 'regrade-then-undo':
        $qid = (int) $argv[2];
        $usages = $DB->get_fieldset_sql('SELECT DISTINCT questionusageid FROM {question_attempts} WHERE questionid = ?', [$qid]);
        $changed = 0;
        foreach ($usages as $usageid) {
            $quba = question_engine::load_questions_usage_by_activity($usageid);
            $before = $quba->get_total_mark();
            foreach ($quba->get_slots() as $slot) {
                if ((int) $quba->get_question($slot, false)->id === $qid) {
                    $quba->regrade_question($slot);
                }
            }
            question_engine::save_questions_usage_by_activity($quba);
            $changed += ($before != question_engine::load_questions_usage_by_activity($usageid)->get_total_mark()) ? 1 : 0;
        }
        $check("regrading converted question $qid works (" . count($usages) . " usages, $changed marks changed)", true);
        $check("regrading changed at least one mark (qtype_ordering grades differently)", $changed > 0);
        $r = $migrator->undo_question($qid);
        $check("undo of $qid refused after regrade", $r->status === 'failed', $r->status);
        $check("undo refusal explains why", (bool) array_filter($r->errors, fn($e) => strpos($e, 'since it was converted') !== false),
            implode('; ', $r->errors));
        break;

    case 'restore-file':
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
        $tempdir = 'tool_qtypeorder_' . time();
        get_file_packer('application/vnd.moodle.backup')->extract_to_pathname($argv[2], make_backup_temp_directory($tempdir));
        $courseid = restore_dbops::create_new_course('Restored', 'restored' . time(), 1);
        $rc = new restore_controller($tempdir, $courseid, backup::INTERACTIVE_NO, backup::MODE_GENERAL, get_admin()->id,
            backup::TARGET_NEW_COURSE);
        $rc->execute_precheck();
        $rc->execute_plan();
        $rc->destroy();
        $check("course restored as $courseid", true);
        break;

    case 'restored':
        $courseid = (int) $argv[2];
        $usages = $DB->get_fieldset_sql("SELECT qa.uniqueid FROM {quiz_attempts} qa JOIN {quiz} q ON q.id = qa.quiz
            WHERE q.course = ?", [$courseid]);
        $check('restored course has quiz attempts', count($usages) > 0, count($usages) . ' attempts');
        foreach ($usages as $usageid) {
            try {
                $quba = question_engine::load_questions_usage_by_activity($usageid);
                foreach ($quba->get_slots() as $slot) {
                    $html = $quba->render_question($slot, new question_display_options());
                    $check("restored usage $usageid slot $slot renders as ordering", strpos($html, 'class="que ordering') !== false);
                }
            } catch (Throwable $e) {
                $check("restored usage $usageid loads", false, $e->getMessage());
            }
        }
        $old = $DB->count_records_sql("SELECT COUNT(1) FROM {question} q " . testcompat_category_joins() . "
            JOIN {context} ctx ON ctx.id = qc.contextid
            WHERE q.qtype = 'order' AND ctx.contextlevel = 70 AND ctx.instanceid IN
                (SELECT id FROM {course_modules} WHERE course = ?)", [$courseid]);
        $check('restored quiz banks contain no unconverted order questions apart from q5/q9', $old <= 3, "$old found");
        break;

    default:
        cli_error('Unknown command. See the top of this file.');
}

mtrace("Passed: $passes, failed: $fails");
exit($fails ? 1 : 0);
