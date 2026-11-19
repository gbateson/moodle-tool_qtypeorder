<?php
// Checks the result of a full migration against the fixtures from create_fixtures.php.
// Usage (after migrating everything): ./php.sh scripts/verify.php

define('CLI_SCRIPT', true);
require(__DIR__ . '/../moodle/config.php');
require_once($CFG->dirroot . '/question/engine/lib.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');
require_once(__DIR__ . '/compat.php');

$expected = json_decode(file_get_contents(__DIR__ . '/expected.json'), true);
$salt = $CFG->passwordsaltmain ?? '';
$fails = 0;
$passes = 0;
$check = function(string $label, bool $ok, string $detail = '') use (&$fails, &$passes) {
    $ok ? $passes++ : $fails++;
    if (!$ok) {
        mtrace("FAIL $label" . ($detail !== '' ? "  -- $detail" : ''));
    }
};
$tidy = fn($t) => preg_replace('/^\s*<p>\s*([^<>]*?)\s*<\/p>\s*$/s', '$1', $t);
$md5 = fn($t) => 'ordering_item_' . md5($salt . $tidy($t));

\core\session\manager::set_user(get_admin());
$PAGE->set_context(context_system::instance());
$PAGE->set_url('/');

// Questions.
foreach ($expected['questions'] as $name => $q) {
    $qid = $q['id'];
    $qtype = $DB->get_field('question', 'qtype', ['id' => $qid]);
    if ($q['expectskip'] || $q['expectfail']) {
        $check("$name stays 'order'", $qtype === 'order', "qtype=$qtype");
        $check("$name old rows untouched", $DB->record_exists('question_order', ['question' => $qid])
            && $DB->count_records('question_order_sub', ['question' => $qid]) === count($q['correcttext']));
        $check("$name no new rows", !$DB->record_exists('question_answers', ['question' => $qid])
            && !$DB->record_exists('qtype_ordering_options', ['questionid' => $qid]));
        continue;
    }
    $check("$name qtype is ordering", $qtype === 'ordering', "qtype=$qtype");
    $check("$name old rows removed", !$DB->record_exists('question_order', ['question' => $qid])
        && !$DB->record_exists('question_order_sub', ['question' => $qid]));
    $opts = $DB->get_record('qtype_ordering_options', ['questionid' => $qid]);
    $check("$name options row", (bool) $opts);
    if ($opts) {
        $check("$name layout", (int) $opts->layouttype === $q['horizontal'], "layouttype={$opts->layouttype}");
        $check("$name feedback copied", $opts->correctfeedback === '<p>Well done</p>' && (!property_exists($opts, 'shownumcorrect') || (int) $opts->shownumcorrect === 1));
    }
    $answers = $DB->get_records('question_answers', ['question' => $qid], 'fraction, id');
    $got = array_values(array_map(fn($a) => $a->answer, $answers));
    $check("$name answers in correct order", $got === array_map($tidy, $q['correcttext']), json_encode($got, JSON_UNESCAPED_UNICODE));
    if ($q['hints']) {
        $check("$name hints kept", $DB->count_records('question_hints', ['questionid' => $qid]) === 2);
    }
    if ($q['image']) {
        $fs = get_file_storage();
        $contextid = $DB->get_field_sql("SELECT qc.contextid FROM {question} q " . testcompat_category_joins() .
            " WHERE q.id = ?", [$qid]);
        $moved = 0;
        foreach ($answers as $a) {
            $moved += count($fs->get_area_files($contextid, 'question', 'answer', $a->id, 'id', false));
        }
        $check("$name image moved to question/answer", $moved === 1, "files=$moved");
    }
    try {
        question_bank::notify_question_edited($qid);
        $check("$name loads in engine", question_bank::load_question($qid) instanceof qtype_ordering_question);
    } catch (Throwable $e) {
        $check("$name loads in engine", false, $e->getMessage());
    }
}
$check('no files left in qtype_order areas (except the failed question)', $DB->count_records_select('files',
    "component = 'qtype_order' AND filename <> '.'") === 0);

// Attempts.
foreach ($expected['attempts'] as $a) {
    if ($a['skipcheck']) {
        $names = $DB->get_fieldset_sql("SELECT d.name FROM {question_attempt_step_data} d
            JOIN {question_attempt_steps} s ON s.id = d.attemptstepid WHERE s.questionattemptid = ?", [$a['questionattemptid']]);
        $check("{$a['question']} attempt still in old format", in_array('_stemorder', $names) && in_array('sub0', $names));
        continue;
    }
    $label = "{$a['question']} {$a['label']} (qa {$a['questionattemptid']})";
    $qa = $DB->get_record('question_attempts', ['id' => $a['questionattemptid']]);
    $rows = $DB->get_records_sql("SELECT d.id, d.name, d.value, s.sequencenumber
        FROM {question_attempt_step_data} d JOIN {question_attempt_steps} s ON s.id = d.attemptstepid
        WHERE s.questionattemptid = ? ORDER BY s.sequencenumber, d.id", [$qa->id]);
    $names = array_map(fn($r) => $r->name, $rows);
    $old = preg_grep('/^(sub\d+|dontknow\d+|_stemorder|_choiceorder)$/', $names);
    $check("$label no old vars left", !$old, implode(',', $old));

    $answers = $DB->get_records('question_answers', ['question' => $qa->questionid], 'fraction, id');
    $textbyid = array_map(fn($x) => $x->answer, $answers);
    $byname = [];
    $responses = 0;
    foreach ($rows as $r) {
        $byname[$r->name] = $r->value; // Last one wins (latest step).
        $responses += $r->name === 'response_' . $qa->questionid ? 1 : 0;
    }
    $current = array_map(fn($id) => $textbyid[$id] ?? '?', explode(',', $byname['_currentresponse'] ?? ''));
    $check("$label _currentresponse = order shown", $current === array_map($tidy, $a['displaytext']),
        json_encode($current, JSON_UNESCAPED_UNICODE));
    $correct = array_map(fn($id) => $textbyid[$id] ?? '?', explode(',', $byname['_correctresponse'] ?? ''));
    $check("$label _correctresponse = correct order", $correct === array_values($textbyid));

    $field = 'response_' . $qa->questionid;
    $wantsteps = count(array_filter($a['scenarios'], fn($s) => $s !== null));
    $check("$label one response per response step", $responses === $wantsteps, "$responses vs $wantsteps");
    if ($a['expecttext'] === null) {
        $check("$label no response stored", !isset($byname[$field]));
    } else {
        $wantkeys = implode(',', array_map($md5, $a['expecttext']));
        $check("$label response decoded", ($byname[$field] ?? null) === $wantkeys, 'got ' . ($byname[$field] ?? 'nothing'));
        $check("$label responsesummary filled", trim((string) $qa->responsesummary) !== '');
    }
    if ($a['expectcomment']) {
        $check("$label teacher comment kept", ($byname['-comment'] ?? '') === 'Teacher comment KEEP ME'
            && isset($byname['-mark']) && isset($byname['-maxmark']));
    }
    if ($a['expectfinish']) {
        $check("$label -finish kept", in_array('-finish', $names));
    }
    $check("$label rightanswer summary filled", trim((string) $qa->rightanswer) !== '');
}

// Every usage loads and renders, and its total mark is what it was before.
foreach ($expected['marks'] as $usageid => $before) {
    try {
        $quba = question_engine::load_questions_usage_by_activity($usageid);
        foreach ($quba->get_slots() as $slot) {
            $html = $quba->render_question($slot, new question_display_options());
            $check("usage $usageid slot $slot renders as ordering", strpos($html, 'class="que ordering') !== false);
        }
        $after = $quba->get_total_mark();
        $check("usage $usageid mark unchanged", $before === null ? $after === null : abs($after - $before) < 0.0001,
            var_export($after, true) . ' vs ' . var_export($before, true) . ' before');
    } catch (Throwable $e) {
        $check("usage $usageid loads", false, get_class($e) . ': ' . $e->getMessage());
    }
}

mtrace('');
mtrace("Passed: $passes, failed: $fails");
exit($fails ? 1 : 0);
