<?php
// Creates legacy qtype_order (2012070400 format) questions and attempts on a Moodle 3.9, 4.1 or 4.5 TEST site.
// Usage: ./php.sh scripts/create_fixtures.php   (run from testenv)
// Writes scripts/expected.json for verify.php and post_tests.php.
//
// How it works: real shortanswer questions are created and really attempted through the quiz /
// question engine (so every table, behaviour step and autosave is genuine), then the question
// definitions and the attempts' qtype variables are rewritten into the exact qtype_order format.

define('CLI_SCRIPT', true);
require(__DIR__ . '/../moodle/config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/testing/classes/util.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');
require_once(__DIR__ . '/compat.php');

$attemptclass = testcompat_attempt_class();

if (empty($CFG->wwwroot) || strpos($CFG->wwwroot, 'localhost') === false) {
    cli_error('Refusing to run: this script is for the local test site only.');
}

\core\session\manager::set_user(get_admin());
$dbman = $DB->get_manager();

// 1. Old qtype_order tables, as defined by qtype_order 2012070400.
if (!$dbman->table_exists('question_order')) {
    $t = new xmldb_table('question_order');
    $t->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
    $t->add_field('question', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
    $t->add_field('subquestions', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL);
    $t->add_field('horizontal', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0');
    $t->add_field('gradingmethod', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, '0');
    foreach (['correct', 'partiallycorrect', 'incorrect'] as $f) {
        $t->add_field($f . 'feedback', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL);
        $t->add_field($f . 'feedbackformat', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0');
    }
    $t->add_field('shownumcorrect', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0');
    $t->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
    $t->add_index('question', XMLDB_INDEX_NOTUNIQUE, ['question']);
    $dbman->create_table($t);
}
if (!$dbman->table_exists('question_order_sub')) {
    $t = new xmldb_table('question_order_sub');
    $t->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
    $t->add_field('code', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
    $t->add_field('question', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
    $t->add_field('questiontext', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL);
    $t->add_field('questiontextformat', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0');
    $t->add_field('answertext', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL);
    $t->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
    $t->add_index('question', XMLDB_INDEX_NOTUNIQUE, ['question']);
    $dbman->create_table($t);
}

$gen = testing_util::get_data_generator();
$quizgen = $gen->get_plugin_generator('mod_quiz');
$fs = get_file_storage();

$course = $gen->create_course(['fullname' => 'Ordering migration test', 'shortname' => 'omt']);
$quizzes = [];
foreach (['A' => 'deferredfeedback', 'B' => 'interactive', 'C' => 'adaptive'] as $key => $behaviour) {
    $quizzes[$key] = $quizgen->create_instance(['course' => $course->id, 'name' => "Quiz $key ($behaviour)",
        'questionsperpage' => 0, 'grade' => 100, 'preferredbehaviour' => $behaviour]);
}

/**
 * Make a question category.
 */
function make_category(int $contextid, string $name): stdClass {
    global $DB;
    $top = question_get_top_category($contextid, true);
    $cat = (object) ['name' => $name, 'contextid' => $contextid, 'info' => '', 'infoformat' => FORMAT_HTML,
        'parent' => $top->id, 'sortorder' => 999, 'stamp' => make_unique_id_code()];
    $cat->id = $DB->insert_record('question_categories', $cat);
    return $cat;
}
$cats = [
    'quizA' => make_category(context_module::instance($quizzes['A']->cmid)->id, 'Quiz A bank'),
    'course' => make_category(context_course::instance($course->id)->id, 'Course bank'),
    'system' => make_category(context_system::instance()->id, 'System bank'),
    'random' => make_category(context_course::instance($course->id)->id, 'Random source'),
];

/**
 * Save a shortanswer question (or a new version of one) the way the question editor does.
 */
function save_shortanswer(stdClass $cat, string $name, ?int $existingid = null, bool $hints = false): stdClass {
    $form = (object) [
        'category' => $cat->id . ',' . $cat->contextid, 'name' => $name,
        'questiontext' => ['text' => "<p>Put these in order ($name)</p>", 'format' => FORMAT_HTML],
        'generalfeedback' => ['text' => '', 'format' => FORMAT_HTML],
        'defaultmark' => 1, 'penalty' => 0.3333333, 'usecase' => 0,
        'answer' => ['frog', '*'], 'fraction' => ['1.0', '0.0'],
        'feedback' => [['text' => '', 'format' => FORMAT_HTML], ['text' => '', 'format' => FORMAT_HTML]],
    ];
    if (class_exists('\core_question\local\bank\question_version_status')) {
        $form->status = \core_question\local\bank\question_version_status::QUESTION_STATUS_READY;
    }
    if ($hints) {
        $form->hint = [['text' => '<p>Hint one</p>', 'format' => FORMAT_HTML], ['text' => '<p>Hint two</p>', 'format' => FORMAT_HTML]];
    }
    $question = (object) ['category' => $cat->id, 'qtype' => 'shortanswer', 'createdby' => get_admin()->id];
    if ($existingid) {
        $question->id = $existingid;
    }
    return question_bank::get_qtype('shortanswer')->save_question($question, $form);
}

/**
 * Add the old qtype_order items (question_order_sub rows) for a question.
 *
 * @param array $items list of [position (string, '' allowed), html text]
 */
function add_subs(stdClass $q, stdClass $cat, array $items, bool $image = false): array {
    global $DB, $fs;
    $subs = [];
    foreach ($items as [$pos, $text]) {
        $sub = (object) ['code' => rand(1, 999999999), 'question' => $q->id, 'questiontext' => $text,
            'questiontextformat' => FORMAT_HTML, 'answertext' => (string) $pos];
        $sub->id = $DB->insert_record('question_order_sub', $sub);
        if ($image && strpos($text, '@@PLUGINFILE@@') !== false) {
            $fs->create_file_from_string(['contextid' => $cat->contextid, 'component' => 'qtype_order',
                'filearea' => 'subquestion', 'itemid' => $sub->id, 'filepath' => '/', 'filename' => 'pic.png'],
                base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
        }
        $subs[$sub->id] = $sub;
    }
    return $subs;
}

// Item lists: [correct position, text]. Inserted in a different order from the correct order.
$simple = function(string $name, array $positions) {
    return array_map(fn($p) => [$p, "<p>Item $p of $name</p>"], $positions);
};
$specs = [
    'q1_basic_hints' => ['cat' => 'quizA', 'items' => $simple('q1', [3, 1, 4, 2]), 'hints' => true],
    'q2_eleven' => ['cat' => 'quizA', 'items' => $simple('q2', [11, 2, 10, 1, 5, 3, 9, 4, 8, 6, 7])],
    'q3_files_horizontal' => ['cat' => 'quizA', 'horizontal' => 1, 'image' => true, 'items' => [
        [2, '<p><img src="@@PLUGINFILE@@/pic.png" alt="pic"> Picture item 2</p>'], [3, '<p>Item 3 of q3</p>'],
        [1, '<p>Item 1 of q3</p>']]],
    'q4_duplicate_text' => ['cat' => 'quizA', 'expectwarning' => true, 'items' => [
        [1, '<p>Same text</p>'], [2, '<p>Same text</p>'], [3, '<p>Item 3 of q4</p>']]],
    'q5_one_item' => ['cat' => 'quizA', 'expectskip' => true, 'items' => [[1, '<p>Lonely</p>']]],
    'q6_course_bank' => ['cat' => 'course', 'items' => $simple('q6', [2, 1, 3])],
    'q7_system_bank' => ['cat' => 'system', 'items' => $simple('q7', [1, 3, 2])],
    'q8_rich_text' => ['cat' => 'quizA', 'expectwarning' => true, 'items' => [
        [2, '<p><strong>Bold</strong> item &amp; more</p>'], [1, '<p>Ünïcødé “quotes” € 日本語</p>'],
        ['', ''], [3, '<p>Line one</p><p>Line two</p>']]],
    'q9_broken_usage' => ['cat' => 'quizA', 'expectfail' => true, 'items' => $simple('q9', [2, 3, 1])],
    'q10_random' => ['cat' => 'random', 'items' => $simple('q10', [3, 2, 1])],
];
$questions = [];
foreach ($specs as $name => $spec) {
    $cat = $cats[$spec['cat']];
    $q = save_shortanswer($cat, $name, null, !empty($spec['hints']));
    $questions[$name] = (object) ['q' => $q, 'cat' => $cat, 'spec' => $spec,
        'subs' => add_subs($q, $cat, $spec['items'], !empty($spec['image']))];
}

// Quiz layouts. Quiz A also gets a random question drawn from the "Random source" category.
foreach (['q1_basic_hints', 'q2_eleven', 'q3_files_horizontal', 'q6_course_bank', 'q8_rich_text'] as $name) {
    quiz_add_quiz_question($questions[$name]->q->id, $quizzes['A']);
}
testcompat_add_random_question($quizzes['A'], $cats['random']->id);
$randomslot = 6;
quiz_add_quiz_question($questions['q1_basic_hints']->q->id, $quizzes['B']);
quiz_add_quiz_question($questions['q3_files_horizontal']->q->id, $quizzes['B']);
quiz_add_quiz_question($questions['q2_eleven']->q->id, $quizzes['C']);
foreach ($quizzes as $quiz) {
    testcompat_recompute_sumgrades($quiz);
}

// Attempt helpers.
$plan = [];        // Question attempts to rewrite: qaid => [question name, scenarios per response step, flags].
$marks = [];       // Usage id => total mark before migration.
$students = [];

/**
 * Simulate the browser POST for some slots and process it.
 */
function submit_slots($attemptobj, array $answers, array $behaviourvars = []): void {
    $postdata = ['slots' => implode(',', array_keys($answers))];
    foreach ($answers as $slot => $answer) {
        $qa = $attemptobj->get_question_attempt($slot);
        if ($answer !== null) {
            $postdata[$qa->get_field_prefix() . 'answer'] = $answer;
        }
        foreach ($behaviourvars as $var => $value) {
            $postdata[$qa->get_behaviour_field_name($var)] = $value;
        }
        $postdata[$qa->get_control_field_name('sequencecheck')] = (string) $qa->get_sequence_check_count();
    }
    $attemptobj->process_submitted_actions(time(), false, $postdata);
}
$student = function(string $name) use ($gen, $course) {
    $user = $gen->create_user(['username' => "student_$name", 'firstname' => 'Student', 'lastname' => $name]);
    $gen->enrol_user($user->id, $course->id, 'student');
    return $user;
};
$qaid = function(int $usageid, int $slot) {
    global $DB;
    return (int) $DB->get_field('question_attempts', 'id', ['questionusageid' => $usageid, 'slot' => $slot], MUST_EXIST);
};
$slotquestion = ['A' => [1 => 'q1_basic_hints', 2 => 'q2_eleven', 3 => 'q3_files_horizontal', 4 => 'q6_course_bank',
    5 => 'q8_rich_text', 6 => 'q10_random'], 'B' => [1 => 'q1_basic_hints', 2 => 'q3_files_horizontal'],
    'C' => [1 => 'q2_eleven']];

// Quiz A (deferred feedback): one student per scenario, plus one still in progress with an autosave.
foreach (['correct', 'reversed', 'partialdup', 'dontknow', 'unanswered', 'inprogress'] as $scenario) {
    $user = $student($scenario);
    \core\session\manager::set_user($user);
    $attempt = $quizgen->create_attempt($quizzes['A']->id, $user->id, [$randomslot => $questions['q10_random']->q->id]);
    $attemptobj = $attemptclass::create($attempt->id);
    if ($scenario !== 'unanswered') {
        submit_slots($attemptobj, array_fill_keys($attemptobj->get_slots(), 'frog'));
    }
    if ($scenario === 'inprogress') {
        $quba = question_engine::load_questions_usage_by_activity($attempt->uniqueid);
        $qa = $quba->get_question_attempt(1);
        $quba->process_all_autosaves(time(), ['slots' => '1', $qa->get_field_prefix() . 'answer' => 'toad',
            $qa->get_control_field_name('sequencecheck') => (string) $qa->get_sequence_check_count()]);
        question_engine::save_questions_usage_by_activity($quba);
    } else {
        $attemptclass::create($attempt->id)->process_finish(time(), false);
    }
    \core\session\manager::set_user(get_admin());
    if ($scenario === 'correct') {
        $quba = question_engine::load_questions_usage_by_activity($attempt->uniqueid);
        $quba->manual_grade(1, 'Teacher comment KEEP ME', 0.5, FORMAT_HTML);
        question_engine::save_questions_usage_by_activity($quba);
    }
    foreach ($slotquestion['A'] as $slot => $name) {
        $scenarios = $scenario === 'unanswered' ? [] : [$scenario === 'inprogress' ? 'correct' : $scenario];
        if ($scenario === 'inprogress' && $slot === 1) {
            $scenarios = ['reversed', 'correct']; // The autosave step (negative sequence number) sorts first.
        }
        $plan[$qaid($attempt->uniqueid, $slot)] = ['question' => $name, 'scenarios' => $scenarios,
            'comment' => $scenario === 'correct' && $slot === 1, 'finished' => $scenario !== 'inprogress',
            'label' => "quizA/$scenario"];
    }
    $marks[$attempt->uniqueid] = null;
}

// Quiz B (interactive with multiple tries): wrong first try, try again, right second try.
// (Interactive discards the finish step for questions already graded, so no -finish is expected.)
$user = $student('interactive');
\core\session\manager::set_user($user);
$attempt = $quizgen->create_attempt($quizzes['B']->id, $user->id);
submit_slots($attemptclass::create($attempt->id), [1 => 'toad'], ['submit' => 1]);
submit_slots($attemptclass::create($attempt->id), [1 => null], ['tryagain' => 1]);
submit_slots($attemptclass::create($attempt->id), [1 => 'frog'], ['submit' => 1]);
submit_slots($attemptclass::create($attempt->id), [2 => 'frog'], ['submit' => 1]);
$attemptclass::create($attempt->id)->process_finish(time(), false);
\core\session\manager::set_user(get_admin());
$plan[$qaid($attempt->uniqueid, 1)] = ['question' => 'q1_basic_hints', 'scenarios' => ['reversed', 'correct'],
    'finished' => false, 'label' => 'quizB/interactive'];
$plan[$qaid($attempt->uniqueid, 2)] = ['question' => 'q3_files_horizontal', 'scenarios' => ['correct'],
    'finished' => false, 'label' => 'quizB/interactive'];
$marks[$attempt->uniqueid] = null;

// Quiz C (adaptive): two checks.
$user = $student('adaptive');
\core\session\manager::set_user($user);
$attempt = $quizgen->create_attempt($quizzes['C']->id, $user->id);
submit_slots($attemptclass::create($attempt->id), [1 => 'toad'], ['submit' => 1]);
submit_slots($attemptclass::create($attempt->id), [1 => 'frog'], ['submit' => 1]);
$attemptclass::create($attempt->id)->process_finish(time(), false);
\core\session\manager::set_user(get_admin());
$plan[$qaid($attempt->uniqueid, 1)] = ['question' => 'q2_eleven', 'scenarios' => ['partialdup', 'correct'],
    'finished' => true, 'label' => 'quizC/adaptive'];
$marks[$attempt->uniqueid] = null;

// Question bank previews.
$preview = function(string $name, string $scenario, ?int $questionid = null) use (&$plan, &$marks, $questions, $qaid) {
    $id = $questionid ?? $questions[$name]->q->id;
    $quba = question_engine::make_questions_usage_by_activity('core_question_preview', context_system::instance());
    $quba->set_preferred_behaviour('deferredfeedback');
    $slot = $quba->add_question(question_bank::load_question($id), 1);
    $quba->start_all_questions();
    $quba->process_action($slot, ['answer' => 'frog']);
    $quba->finish_all_questions();
    question_engine::save_questions_usage_by_activity($quba);
    $plan[$qaid($quba->get_id(), $slot)] = ['question' => $name, 'scenarios' => [$scenario], 'finished' => true,
        'label' => "preview/$scenario"];
    $marks[$quba->get_id()] = null;
    return $quba->get_id();
};
$preview('q1_basic_hints', 'reversed');
$preview('q7_system_bank', 'correct');
$brokenusage = $preview('q9_broken_usage', 'correct');

// A second version of q1 (as if a teacher edited it), with its own items and a preview attempt.
// Moodle 3.9 has no question versions, so there it is a separate question with the same name.
$v2 = save_shortanswer($cats['quizA'], 'q1_basic_hints',
    testcompat_versioned_bank() ? $questions['q1_basic_hints']->q->id : null, true);
$questions['q1v2'] = (object) ['q' => $v2, 'cat' => $cats['quizA'], 'spec' => ['cat' => 'quizA'],
    'subs' => add_subs($v2, $cats['quizA'], $simple('q1v2', [2, 4, 1, 3]))];
$preview('q1v2', 'partialdup', $v2->id);

// Marks before migration (stored fractions; the conversion must never change them).
foreach (array_keys($marks) as $usageid) {
    $marks[$usageid] = question_engine::load_questions_usage_by_activity($usageid)->get_total_mark();
}

// 2. Rewrite every planned question attempt into the old qtype_order format.
$correctorder = function($subs) {
    $subs = array_filter($subs, fn($s) => $s->questiontext !== '');
    uasort($subs, fn($a, $b) => (int) $a->answertext <=> (int) $b->answertext);
    return array_keys($subs);
};
$choiceorder = function($subs) {            // qtype_order 2012: first sub id per answertext, id order.
    $c = [];
    foreach ($subs as $s) {
        if (!in_array($s->answertext, $c, true)) {
            $c[$s->id] = $s->answertext;
        }
    }
    return array_keys($c);
};
$choiceindex = function($subs, $choices, $position) {
    foreach ($choices as $j => $sid) {
        if ($subs[$sid]->answertext === (string) $position) {
            return $j + 1;
        }
    }
    throw new coding_exception("no choice for position $position");
};
$scenariofns = [
    'correct' => fn($subs, $display) => [array_map(fn($sid) => (int) $subs[$sid]->answertext, $display), false],
    'reversed' => fn($subs, $display) => [array_map(fn($sid) => count($display) + 1 - (int) $subs[$sid]->answertext,
        $display), false],
    'partialdup' => fn($subs, $display) => [array_merge([1, 1], array_fill(0, count($display) - 2, 0)), false],
    'dontknow' => fn($subs, $display) => [array_fill(0, count($display), 0), true],
];
$expectedorder = function($scenario, $subs, $display) use ($correctorder) {
    $correct = $correctorder($subs);
    return $scenario === 'correct' ? $correct : ($scenario === 'reversed' ? array_reverse($correct) : $display);
};
$texts = fn($subs, $ids) => array_values(array_map(fn($id) => $subs[$id]->questiontext, $ids));

$expected = ['courseid' => (int) $course->id, 'quizids' => array_map(fn($q) => (int) $q->id, $quizzes),
    'categories' => array_map(fn($c) => (int) $c->id, $cats), 'brokenusage' => $brokenusage,
    'marks' => $marks, 'questions' => [], 'attempts' => []];
$n = 0;
foreach ($plan as $id => $p) {
    $n++;
    $info = $questions[$p['question']];
    $subs = $info->subs;
    $qa = $DB->get_record('question_attempts', ['id' => $id], '*', MUST_EXIST);
    $first = $DB->get_record('question_attempt_steps', ['questionattemptid' => $id, 'sequencenumber' => 0], '*', MUST_EXIST);
    $display = $correctorder($subs);
    usort($display, fn($a, $b) => (($a * 7919 + $n * 31) % 101) <=> (($b * 7919 + $n * 31) % 101));
    $choices = $choiceorder($subs);
    $DB->insert_record('question_attempt_step_data', ['attemptstepid' => $first->id, 'name' => '_stemorder',
        'value' => implode(',', $display)]);
    $DB->insert_record('question_attempt_step_data', ['attemptstepid' => $first->id, 'name' => '_choiceorder',
        'value' => implode(',', $choices)]);

    $answerrows = array_values($DB->get_records_sql("SELECT d.*
        FROM {question_attempt_step_data} d JOIN {question_attempt_steps} s ON s.id = d.attemptstepid
        WHERE s.questionattemptid = ? AND d.name = 'answer' ORDER BY s.sequencenumber, d.id", [$id]));
    if (count($answerrows) !== count($p['scenarios'])) {
        throw new coding_exception("qa $id ({$p['label']}): " . count($answerrows) . ' response steps, ' .
            count($p['scenarios']) . ' scenarios');
    }
    foreach ($answerrows as $k => $row) {
        [$chosen, $dontknow] = $scenariofns[$p['scenarios'][$k]]($subs, $display);
        $DB->delete_records('question_attempt_step_data', ['id' => $row->id]);
        foreach ($display as $i => $sid) {
            $value = $chosen[$i] ? $choiceindex($subs, $choices, $chosen[$i]) : 0;
            $DB->insert_record('question_attempt_step_data', ['attemptstepid' => $row->attemptstepid,
                'name' => 'sub' . $i, 'value' => (string) $value]);
        }
        if ($dontknow) {
            $DB->insert_record('question_attempt_step_data', ['attemptstepid' => $row->attemptstepid,
                'name' => 'dontknow' . $info->q->id, 'value' => 'yes']);
        }
    }
    $last = end($p['scenarios']);
    $expected['attempts'][] = [
        'questionattemptid' => $id,
        'question' => $p['question'],
        'label' => $p['label'],
        'scenarios' => $p['scenarios'],
        'displaytext' => $texts($subs, $display),
        'expecttext' => $last ? $texts($subs, $expectedorder($last, $subs, $display)) : null,
        'expectcomment' => !empty($p['comment']),
        'expectfinish' => !empty($p['finished']),
        'skipcheck' => !empty($info->spec['expectfail']),
    ];
}

// 3. Turn the shortanswer questions into old "order" questions.
foreach ($questions as $name => $info) {
    $qid = $info->q->id;
    $DB->delete_records('question_answers', ['question' => $qid]);
    $DB->delete_records('qtype_shortanswer_options', ['questionid' => $qid]);
    $DB->insert_record('question_order', [
        'question' => $qid, 'subquestions' => implode(',', array_keys($info->subs)),
        'horizontal' => (int) !empty($info->spec['horizontal']), 'gradingmethod' => '0',
        'correctfeedback' => '<p>Well done</p>', 'correctfeedbackformat' => FORMAT_HTML,
        'partiallycorrectfeedback' => '<p>Partly</p>', 'partiallycorrectfeedbackformat' => FORMAT_HTML,
        'incorrectfeedback' => '<p>Wrong</p>', 'incorrectfeedbackformat' => FORMAT_HTML,
        'shownumcorrect' => 1,
    ]);
    $DB->set_field('question', 'qtype', 'order', ['id' => $qid]);
    $expected['questions'][$name] = [
        'id' => (int) $qid,
        'correcttext' => $texts($info->subs, $correctorder($info->subs)),
        'expectskip' => !empty($info->spec['expectskip']),
        'expectwarning' => !empty($info->spec['expectwarning']),
        'expectfail' => !empty($info->spec['expectfail']),
        'image' => !empty($info->spec['image']),
        'horizontal' => (int) !empty($info->spec['horizontal']),
        'hints' => !empty($info->spec['hints']),
        'category' => $info->spec['cat'],
    ];
}

// 4. q9 stays unconverted (run_tests.sh adds a DB trigger that makes its conversion fail), so its usage
// cannot be loaded as an ordering question afterwards.
unset($expected['marks'][$brokenusage]);

purge_all_caches();
file_put_contents(__DIR__ . '/expected.json', json_encode($expected, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
mtrace("Created course {$course->id}, " . count($quizzes) . ' quizzes, ' . count($questions) . ' order questions, ' .
    count($expected['attempts']) . ' question attempts.');
foreach ($expected['questions'] as $name => $q) {
    mtrace(sprintf('  %-22s question id %d', $name, $q['id']));
}
