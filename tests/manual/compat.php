<?php
// Small helpers so the test scripts run on Moodle 3.9, 4.1 and 4.5.

/**
 * True from Moodle 4.0, where questions reach their category through question_versions / question_bank_entries.
 */
function testcompat_versioned_bank(): bool {
    global $DB;
    return $DB->get_manager()->table_exists('question_versions');
}

/**
 * SQL joins from question "q" to its category "qc".
 */
function testcompat_category_joins(): string {
    if (testcompat_versioned_bank()) {
        return 'JOIN {question_versions} qv ON qv.questionid = q.id
                JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid';
    }
    return 'JOIN {question_categories} qc ON qc.id = q.category';
}

/**
 * The quiz attempt class: \mod_quiz\quiz_attempt from Moodle 4.2, the global quiz_attempt before.
 */
function testcompat_attempt_class(): string {
    return class_exists('\mod_quiz\quiz_attempt') ? '\mod_quiz\quiz_attempt' : 'quiz_attempt';
}

/**
 * The quiz settings object: \mod_quiz\quiz_settings from Moodle 4.2, the global quiz class before.
 */
function testcompat_quiz_object(int $quizid, ?int $userid = null) {
    if (class_exists('\mod_quiz\quiz_settings')) {
        return \mod_quiz\quiz_settings::create($quizid, $userid);
    }
    return quiz::create($quizid, $userid);
}

/**
 * Recalculate a quiz's total of question marks.
 */
function testcompat_recompute_sumgrades(stdClass $quiz): void {
    global $DB;
    if (class_exists('\mod_quiz\quiz_settings')) {
        \mod_quiz\quiz_settings::create($quiz->id)->get_grade_calculator()->recompute_quiz_sumgrades();
    } else {
        quiz_update_sumgrades($DB->get_record('quiz', ['id' => $quiz->id], '*', MUST_EXIST));
    }
}

/**
 * Add one random question from a category (no subcategories) to the end of a quiz.
 */
function testcompat_add_random_question(stdClass $quiz, int $categoryid): void {
    if (class_exists('\mod_quiz\quiz_settings')) {
        $structure = \mod_quiz\quiz_settings::create($quiz->id)->get_structure();
        $structure->add_random_questions(0, 1, ['filter' => ['category' => ['jointype' => 1, 'values' => [$categoryid],
            'filteroptions' => ['includesubcategories' => false]]]]);
    } else {
        quiz_add_random_questions($quiz, 0, $categoryid, 1, false);
    }
}
