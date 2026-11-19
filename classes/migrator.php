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

namespace tool_qtypeorder;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/question/engine/lib.php');

/**
 * Converts legacy qtype_order questions (and their attempts) to core qtype_ordering.
 *
 * Works on Moodle 3.9 to 4.5. From Moodle 4.0, questions reach their category through
 * question_versions -> question_bank_entries; before that, question.category points straight at it.
 * qtype_ordering is part of core from Moodle 4.3. Older sites need it installed as an add-on.
 *
 * Old format (qtype_order 2012070400, built on qtype_match):
 *  - question_order          one row per question (horizontal, combined feedback, shownumcorrect).
 *  - question_order_sub      one row per item; "answertext" holds the item's correct position (1..n).
 *  - attempt step data:
 *      _stemorder            comma list of sub ids, in the (shuffled) order shown to the student.
 *      _choiceorder          comma list of "choice keys" (the first sub id having each answertext).
 *      sub{i}                for the item shown at index i of _stemorder, the 1-based index into
 *                            _choiceorder of the position the student picked (0 = not answered).
 *      dontknow{questionid}  "I don't know" checkbox.
 *
 * New format (qtype_ordering):
 *  - qtype_ordering_options  one row per question.
 *  - question_answers        one row per item, "fraction" = correct position.
 *  - attempt step data:
 *      _currentresponse      comma list of answer ids in the order first shown.
 *      _correctresponse      comma list of answer ids in the correct order.
 *      response_{questionid} comma list of item md5 keys in the order the student arranged them.
 *
 * Modes:
 *  - analyse   look at the question only, change nothing.
 *  - simulate  do the whole conversion and the self-check inside a transaction, then roll it back.
 *  - execute   convert for real (after saving an undo backup), then self-check.
 *  - check     self-check questions that were already converted.
 *  - undo      put a converted question back, from its undo backup.
 *
 * @package    tool_qtypeorder
 * @copyright  2015 Gordon Bateson, 2026 updated for Moodle 3.9 to 4.5
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class migrator {

    /** @var string the legacy question type name. */
    const OLD_QTYPE = 'order';

    /** @var string the core question type name. */
    const NEW_QTYPE = 'ordering';

    /** @var string regex that strips a single wrapping paragraph from simple item text. */
    const SIMPLE_PARAGRAPH = '/^\s*<p>\s*([^<>]*?)\s*<\/p>\s*$/s';

    /** @var int stop listing problems for one question after this many. */
    const MAX_MESSAGES = 20;

    /** @var int the version number written into undo backup files. */
    const BACKUP_VERSION = 1;

    /** @var string salt that qtype_ordering uses when building item md5 keys. */
    protected $salt;

    /** @var bool true from Moodle 4.0 (question_versions / question_bank_entries), false before. */
    protected $versionedbank;

    /** @var bool whether to collect per-attempt details while converting. */
    protected $collectdetails = false;

    /** @var array per-attempt details collected during the current conversion. */
    protected $details = [];

    /**
     * Constructor.
     */
    public function __construct() {
        global $CFG, $DB;
        $this->salt = $CFG->passwordsaltmain ?? '';
        $this->versionedbank = $DB->get_manager()->table_exists('question_versions');
    }

    /**
     * SQL joins from question "q" to its category "qc" (and, from Moodle 4.0, its bank entry "qbe").
     *
     * @param bool $outer use LEFT JOINs, so questions without a category are kept.
     * @return string
     */
    protected function category_joins(bool $outer = true): string {
        $join = $outer ? 'LEFT JOIN' : 'JOIN';
        if ($this->versionedbank) {
            return "$join {question_versions} qv ON qv.questionid = q.id
                    $join {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                    $join {question_categories} qc ON qc.id = qbe.questioncategoryid";
        }
        // Before Moodle 4.0, each question points straight at its category.
        return "$join {question_categories} qc ON qc.id = q.category";
    }

    /**
     * Whether the installed qtype_ordering has a column. Releases for Moodle 3.x have no "shownumcorrect".
     *
     * @param string $column
     * @return bool
     */
    protected function ordering_has_column(string $column): bool {
        global $DB;
        return array_key_exists($column, $DB->get_columns('qtype_ordering_options'));
    }

    /**
     * Check that the tables we need exist.
     *
     * @return string[] list of language string ids describing blocking problems (empty = OK).
     */
    public static function check_environment(): array {
        global $DB;
        $dbman = $DB->get_manager();
        $problems = [];
        if (!$dbman->table_exists('qtype_ordering_options')) {
            $problems[] = 'noorderingtable';
        }
        if (!$dbman->table_exists('question_order') || !$dbman->table_exists('question_order_sub')) {
            $problems[] = 'noordertable';
        }
        return $problems;
    }

    // Choosing questions.

    /**
     * Parse a list of question ids typed by a person ("12, 15 18").
     *
     * @param string|null $text
     * @return int[]
     */
    public static function parse_ids(?string $text): array {
        $ids = preg_split('/[\s,;]+/', (string) $text, -1, PREG_SPLIT_NO_EMPTY);
        return array_values(array_unique(array_filter(array_map('intval', $ids))));
    }

    /**
     * Ids of questions, filtered.
     *
     * @param array $filters questionids (int[]), courseid, categoryid, limit, qtype (default 'order').
     * @return int[]
     */
    public function get_question_ids(array $filters = []): array {
        global $DB;

        $params = ['qtype' => $filters['qtype'] ?? self::OLD_QTYPE];
        $where = ['q.qtype = :qtype'];
        $joins = '';
        if (($filters['qtype'] ?? self::OLD_QTYPE) === self::OLD_QTYPE) {
            $joins .= ' JOIN {question_order} qo ON qo.question = q.id';
        }

        if (!empty($filters['questionids'])) {
            [$insql, $inparams] = $DB->get_in_or_equal($filters['questionids'], SQL_PARAMS_NAMED, 'qid');
            $where[] = "q.id $insql";
            $params += $inparams;
        }
        if (!empty($filters['categoryid'])) {
            $where[] = 'qc.id = :categoryid';
            $params['categoryid'] = $filters['categoryid'];
        }
        if (!empty($filters['courseid'])) {
            // Stored in the course's question banks, or in a module's bank in the course,
            // or used by a quiz (or other module) in the course.
            if ($this->versionedbank) {
                $used = "qbe.id IN (SELECT qr.questionbankentryid
                                      FROM {question_references} qr
                                      JOIN {context} uctx ON uctx.id = qr.usingcontextid AND uctx.contextlevel = :lvlmod2
                                      JOIN {course_modules} cm2 ON cm2.id = uctx.instanceid
                                     WHERE cm2.course = :course3)";
                $params['lvlmod2'] = CONTEXT_MODULE;
            } else {
                // Before Moodle 4.0, quiz slots point straight at questions.
                $used = "q.id IN (SELECT qs.questionid
                                    FROM {quiz_slots} qs
                                    JOIN {quiz} qz ON qz.id = qs.quizid
                                   WHERE qz.course = :course3)";
            }
            $where[] = "((ctx.contextlevel = :lvlcourse AND ctx.instanceid = :course1)
                      OR (ctx.contextlevel = :lvlmod1 AND ctx.instanceid IN
                            (SELECT cm1.id FROM {course_modules} cm1 WHERE cm1.course = :course2))
                      OR $used)";
            $params += ['lvlcourse' => CONTEXT_COURSE, 'lvlmod1' => CONTEXT_MODULE,
                'course1' => $filters['courseid'], 'course2' => $filters['courseid'], 'course3' => $filters['courseid']];
        }

        $sql = "SELECT DISTINCT q.id
                  FROM {question} q
                       $joins
                       {$this->category_joins()}
             LEFT JOIN {context} ctx ON ctx.id = qc.contextid
                 WHERE " . implode(' AND ', $where) . "
              ORDER BY q.id";
        return array_map('intval', array_keys($DB->get_records_sql($sql, $params, 0, (int) ($filters['limit'] ?? 0))));
    }

    /**
     * Ids of questions this tool has converted (they have an undo backup), filtered.
     *
     * @param array $filters as for get_question_ids()
     * @return int[]
     */
    public function get_converted_ids(array $filters = []): array {
        $withbackup = array_keys($this->list_backups());
        if (!$withbackup) {
            return [];
        }
        if (!empty($filters['questionids'])) {
            $withbackup = array_values(array_intersect($withbackup, $filters['questionids']));
        }
        if (!$withbackup) {
            return [];
        }
        $filters['questionids'] = $withbackup;
        $filters['qtype'] = self::NEW_QTYPE;
        return $this->get_question_ids($filters);
    }

    /**
     * Site-wide counts shown on the tool's front page.
     *
     * @return \stdClass with questions, attempts, nooptions, noquestion, converted.
     */
    public function get_counts(): \stdClass {
        global $DB;
        $params = ['qtype' => self::OLD_QTYPE];
        $counts = new \stdClass();
        $counts->questions = $DB->count_records_sql(
            "SELECT COUNT(DISTINCT qo.question)
               FROM {question_order} qo
               JOIN {question} q ON q.id = qo.question
              WHERE q.qtype = :qtype", $params);
        $counts->attempts = $DB->count_records_sql(
            "SELECT COUNT(qa.id)
               FROM {question_attempts} qa
               JOIN {question} q ON q.id = qa.questionid
              WHERE q.qtype = :qtype", $params);
        $counts->nooptions = $DB->count_records_sql(
            "SELECT COUNT(q.id)
               FROM {question} q
          LEFT JOIN {question_order} qo ON qo.question = q.id
              WHERE q.qtype = :qtype AND qo.id IS NULL", $params);
        $counts->noquestion = $DB->count_records_sql(
            "SELECT COUNT(qo.id)
               FROM {question_order} qo
          LEFT JOIN {question} q ON q.id = qo.question
              WHERE q.id IS NULL");
        $counts->converted = count($this->list_backups());
        return $counts;
    }

    // Running a batch.

    /** @var string[] the modes run() accepts. */
    const MODES = ['preview', 'simulate', 'migrate', 'check', 'undo'];

    /**
     * Run one mode over the questions matching the filters.
     *
     * @param string $mode one of self::MODES
     * @param array $filters questionids, courseid, categoryid, limit
     * @param bool $details collect per-attempt details (simulate / migrate only)
     * @param callable|null $progress called as $progress($index, $total, $result) after each question
     * @return \stdClass[] results
     */
    public function run(string $mode, array $filters, bool $details = false, ?callable $progress = null): array {
        if (!in_array($mode, self::MODES, true)) {
            throw new \coding_exception("Unknown mode $mode");
        }
        if ($mode === 'check') {
            $ids = $this->get_converted_ids($filters);
        } else if ($mode === 'undo') {
            $ids = array_slice($filters['questionids'] ?? [], 0, ($filters['limit'] ?? 0) ?: null);
        } else {
            $ids = $this->get_question_ids($filters);
        }

        $results = [];
        $total = count($ids);
        foreach ($ids as $index => $id) {
            switch ($mode) {
                case 'preview':
                    $result = $this->analyse_question($id);
                    if ($result->errors) {
                        $result->status = 'skipped';
                    }
                    break;
                case 'simulate':
                    $result = $this->migrate_question($id, true, $details);
                    break;
                case 'migrate':
                    $result = $this->migrate_question($id, false, $details);
                    break;
                case 'check':
                    $result = $this->check_question($id);
                    break;
                case 'undo':
                    $result = $this->undo_question($id);
                    break;
            }
            $results[] = $result;
            if ($progress) {
                $progress($index + 1, $total, $result);
            }
        }
        return $results;
    }

    /**
     * Count results by status and add up attempts.
     *
     * @param \stdClass[] $results
     * @return array status => count, plus 'attempts' and 'warnings'
     */
    public static function tally(array $results): array {
        $tally = ['attempts' => 0, 'warnings' => 0];
        foreach ($results as $r) {
            $tally[$r->status] = ($tally[$r->status] ?? 0) + 1;
            $tally['attempts'] += $r->attempts;
            $tally['warnings'] += $r->warnings ? 1 : 0;
        }
        return $tally;
    }

    // Public operations on one question.

    /**
     * Inspect one question without changing anything.
     *
     * @param int $questionid
     * @return \stdClass result
     */
    public function analyse_question(int $questionid): \stdClass {
        return $this->analyse($this->load($questionid));
    }

    /**
     * Convert one question and all of its attempts, inside a single transaction.
     *
     * @param int $questionid
     * @param bool $simulate if true, roll everything back at the end (a full dress rehearsal).
     * @param bool $details if true, collect a per-attempt report in $result->details.
     * @return \stdClass result with status 'converted', 'simulated', 'skipped' or 'failed'.
     */
    public function migrate_question(int $questionid, bool $simulate = false, bool $details = false): \stdClass {
        global $DB;

        $data = $this->load($questionid);
        $result = $this->analyse($data);
        $result->details = [];
        if ($result->errors) {
            $result->status = 'skipped';
            return $result;
        }

        $this->collectdetails = $details;
        $this->details = [];
        $backup = $simulate ? null : $this->build_backup($data);

        $transaction = $DB->start_delegated_transaction();
        try {
            $answerids = $this->convert_definition($data);

            // Load the converted question through the real question engine,
            // so attempt summaries are produced exactly as qtype_ordering would.
            \question_bank::notify_question_edited($questionid);
            $question = \question_bank::load_question($questionid, false);
            $result->attemptsconverted = $this->convert_attempts($data, $answerids, $question, $result);

            // Self-check the converted data while it is still inside the transaction.
            $check = $this->run_checks($questionid);
            $result->checkerrors = $check->errors;
            $result->warnings = array_merge($result->warnings, $check->warnings);

            if ($simulate) {
                throw new simulation_complete();
            }
            if ($check->errors) {
                throw new \moodle_exception('checkfailedrolledback', 'tool_qtypeorder');
            }
            $result->backupfile = $this->save_backup($backup);
            $transaction->allow_commit();
            $result->status = 'converted';
        } catch (\Throwable $e) {
            try {
                $transaction->rollback($e);
            } catch (\Throwable $rethrown) {
                // Expected: rollback() always re-throws.
                unset($rethrown);
            }
            if ($e instanceof simulation_complete) {
                $result->status = 'simulated';
                $result->errors = $result->checkerrors;
            } else {
                $result->status = 'failed';
                $result->errors = array_merge($result->checkerrors ?? [], [$e->getMessage()]);
            }
        }

        $result->details = $this->details;
        $this->collectdetails = false;
        $this->details = [];

        // Make sure nobody gets a cached copy of the old (or rolled back) definition.
        \question_bank::notify_question_edited($questionid);
        return $result;
    }

    /**
     * Self-check a question that has already been converted.
     *
     * @param int $questionid
     * @return \stdClass result with status 'checked' (no errors) or 'checkfailed'.
     */
    public function check_question(int $questionid): \stdClass {
        global $DB;
        $question = $DB->get_record('question', ['id' => $questionid], 'id, name, qtype');
        $check = $this->run_checks($questionid);
        return (object) [
            'questionid' => $questionid,
            'name' => $question->name ?? '?',
            'items' => $DB->count_records('question_answers', ['question' => $questionid]),
            'attempts' => $check->attempts,
            'attemptsconverted' => 0,
            'status' => $check->errors ? 'checkfailed' : 'checked',
            'warnings' => $check->warnings,
            'errors' => $check->errors,
            'details' => [],
        ];
    }

    // Loading and analysing.

    /**
     * Load everything we need about an unconverted question.
     *
     * @param int $questionid
     * @return \stdClass
     */
    protected function load(int $questionid): \stdClass {
        global $DB;

        $sql = "SELECT q.id, q.name, q.qtype, qc.contextid
                  FROM {question} q
                       {$this->category_joins()}
                 WHERE q.id = :id";
        $question = $DB->get_record_sql($sql, ['id' => $questionid], MUST_EXIST);

        $data = new \stdClass();
        $data->question = $question;
        $data->options = $DB->get_record('question_order', ['question' => $questionid], '*', IGNORE_MULTIPLE);

        // All subs (including blank ones) are needed to decode "_choiceorder" in old attempts.
        $data->allsubs = $DB->get_records('question_order_sub', ['question' => $questionid], 'id ASC');

        // The items: subs with text, in correct order (position, then id as the tie-break).
        $items = [];
        foreach ($data->allsubs as $sub) {
            if ($sub->questiontext === '') {
                continue; // qtype_order ignores these too.
            }
            $sub->position = (int) $sub->answertext;
            $sub->newtext = preg_replace(self::SIMPLE_PARAGRAPH, '$1', $sub->questiontext);
            $sub->md5key = 'ordering_item_' . md5($this->salt . $sub->newtext);
            $items[$sub->id] = $sub;
        }
        uasort($items, function($a, $b) {
            return [$a->position, $a->id] <=> [$b->position, $b->id];
        });
        $data->items = $items;

        return $data;
    }

    /**
     * Look for problems, without changing anything.
     *
     * @param \stdClass $data from load()
     * @return \stdClass
     */
    protected function analyse(\stdClass $data): \stdClass {
        global $DB;
        $questionid = (int) $data->question->id;

        $result = (object) [
            'questionid' => $questionid,
            'name' => $data->question->name,
            'items' => count($data->items),
            'attempts' => $DB->count_records('question_attempts', ['questionid' => $questionid]),
            'attemptsconverted' => 0,
            'status' => 'ready',
            'warnings' => [],
            'errors' => [],
            'checkerrors' => [],
            'details' => [],
        ];

        // Blocking problems.
        if ($data->question->qtype !== self::OLD_QTYPE) {
            $result->errors[] = get_string('errorwrongqtype', 'tool_qtypeorder', $data->question->qtype);
        }
        if (!$data->options) {
            $result->errors[] = get_string('errornooptions', 'tool_qtypeorder');
        }
        if (empty($data->question->contextid)) {
            $result->errors[] = get_string('errornocategory', 'tool_qtypeorder');
        }
        if (count($data->items) < 2) {
            $result->errors[] = get_string('errortoofewitems', 'tool_qtypeorder', count($data->items));
        }
        if ($DB->record_exists('qtype_ordering_options', ['questionid' => $questionid])) {
            $result->errors[] = get_string('errorexistingoptions', 'tool_qtypeorder');
        }
        if ($DB->record_exists('question_answers', ['question' => $questionid])) {
            $result->errors[] = get_string('errorexistinganswers', 'tool_qtypeorder');
        }

        // Non-blocking problems.
        $md5keys = array_count_values(array_column($data->items, 'md5key'));
        if (max($md5keys ?: [0]) > 1) {
            $result->warnings[] = get_string('warningduplicatetext', 'tool_qtypeorder');
        }
        $positions = array_count_values(array_column($data->items, 'position'));
        if (max($positions ?: [0]) > 1) {
            $result->warnings[] = get_string('warningduplicateposition', 'tool_qtypeorder');
        }
        if (!empty($data->options->shownumcorrect) && !$this->ordering_has_column('shownumcorrect')) {
            $result->warnings[] = get_string('warningnoshownumcorrect', 'tool_qtypeorder');
        }
        foreach ($data->items as $item) {
            if ((string) $item->position !== trim($item->answertext)) {
                $result->warnings[] = get_string('warningbadposition', 'tool_qtypeorder', s($item->answertext));
                break;
            }
        }
        $blank = count($data->allsubs) - count($data->items);
        if ($blank) {
            $result->warnings[] = get_string('warningblankitems', 'tool_qtypeorder', $blank);
        }

        return $result;
    }

    // Converting.

    /**
     * Convert the question definition: options, items (answers), files, qtype.
     *
     * @param \stdClass $data from load()
     * @return int[] map of old sub id => new answer id, in correct order.
     */
    protected function convert_definition(\stdClass $data): array {
        global $DB;

        $questionid = (int) $data->question->id;
        $contextid = (int) $data->question->contextid;
        $fs = get_file_storage();

        // Items become answers; "fraction" holds the correct position.
        $answerids = [];
        $position = 0;
        foreach ($data->items as $subid => $item) {
            $position++;
            $answer = (object) [
                'question' => $questionid,
                'answer' => $item->newtext,
                'answerformat' => $item->questiontextformat,
                'fraction' => $position,
                'feedback' => '',
                'feedbackformat' => FORMAT_HTML,
            ];
            $answer->id = $DB->insert_record('question_answers', $answer);
            $answerids[$subid] = (int) $answer->id;

            // Images etc. embedded in the item text.
            foreach ($fs->get_area_files($contextid, 'qtype_order', 'subquestion', $subid, 'id', false) as $file) {
                $fs->create_file_from_storedfile([
                    'contextid' => $contextid,
                    'component' => 'question',
                    'filearea' => 'answer',
                    'itemid' => $answer->id,
                ], $file);
            }
        }
        foreach ($data->allsubs as $sub) {
            $fs->delete_area_files($contextid, 'qtype_order', 'subquestion', $sub->id);
        }

        // Options. Combined feedback files already live in component "question", so they need no change.
        $old = $data->options;
        $DB->insert_record('qtype_ordering_options', (object) [
            'questionid' => $questionid,
            'layouttype' => empty($old->horizontal) ? 0 : 1, // LAYOUT_VERTICAL / LAYOUT_HORIZONTAL.
            'selecttype' => 0, // SELECT_ALL.
            'selectcount' => count($answerids),
            'gradingtype' => 0, // GRADING_ABSOLUTE_POSITION: closest to how qtype_order graded.
            'showgrading' => 0,
            'numberingstyle' => 'none',
            'correctfeedback' => $old->correctfeedback ?? '',
            'correctfeedbackformat' => $old->correctfeedbackformat ?? FORMAT_HTML,
            'partiallycorrectfeedback' => $old->partiallycorrectfeedback ?? '',
            'partiallycorrectfeedbackformat' => $old->partiallycorrectfeedbackformat ?? FORMAT_HTML,
            'incorrectfeedback' => $old->incorrectfeedback ?? '',
            'incorrectfeedbackformat' => $old->incorrectfeedbackformat ?? FORMAT_HTML,
            'shownumcorrect' => empty($old->shownumcorrect) ? 0 : 1,
        ]);

        $DB->set_field('question', 'qtype', self::NEW_QTYPE, ['id' => $questionid]);
        $DB->delete_records('question_order', ['question' => $questionid]);
        $DB->delete_records('question_order_sub', ['question' => $questionid]);

        return $answerids;
    }

    /**
     * Convert every attempt at this question.
     *
     * @param \stdClass $data from load()
     * @param int[] $answerids map of old sub id => new answer id, in correct order.
     * @param \question_definition $question the converted question, loaded by the question engine.
     * @param \stdClass $result warnings are added here.
     * @return int number of attempts converted.
     */
    protected function convert_attempts(\stdClass $data, array $answerids, \question_definition $question,
            \stdClass $result): int {
        global $DB;

        $questionsummary = $question->get_question_summary();
        $count = 0;
        $nostate = 0;

        $attempts = $DB->get_recordset('question_attempts', ['questionid' => $data->question->id], 'id', 'id');
        foreach ($attempts as $attempt) {
            if (!$this->convert_attempt($data, $answerids, $question, $questionsummary, (int) $attempt->id)) {
                $nostate++;
            }
            $count++;
        }
        $attempts->close();

        if ($nostate) {
            $result->warnings[] = get_string('warningnostate', 'tool_qtypeorder', $nostate);
        }
        return $count;
    }

    /**
     * Convert the step data of one question attempt and refresh its summaries.
     *
     * @param \stdClass $data from load()
     * @param int[] $answerids map of old sub id => new answer id, in correct order.
     * @param \question_definition $question the converted question.
     * @param string|null $questionsummary
     * @param int $questionattemptid
     * @return bool false if the attempt had no stored item order (a default was used).
     */
    protected function convert_attempt(\stdClass $data, array $answerids, \question_definition $question,
            ?string $questionsummary, int $questionattemptid): bool {
        global $DB;

        $steps = $DB->get_records('question_attempt_steps', ['questionattemptid' => $questionattemptid],
            'sequencenumber, id', 'id, sequencenumber, userid');
        if (!$steps) {
            return true; // Nothing stored, nothing to convert.
        }

        // Group the step data by step, keyed by variable name.
        [$insql, $params] = $DB->get_in_or_equal(array_keys($steps));
        $bystep = array_fill_keys(array_keys($steps), []);
        foreach ($DB->get_records_select('question_attempt_step_data', "attemptstepid $insql", $params, 'id') as $row) {
            $bystep[$row->attemptstepid][$row->name] = $row;
        }

        // Find the item order the student was shown, and the order of the position choices.
        $haveorder = false;
        $stemorder = array_map('strval', array_keys($data->items));
        $choiceorder = $this->default_choice_order($data);
        $statestepid = null;
        foreach ($bystep as $stepid => $vars) {
            if (isset($vars['_stemorder'])) {
                $stemorder = explode(',', $vars['_stemorder']->value);
                if (isset($vars['_choiceorder'])) {
                    $choiceorder = explode(',', $vars['_choiceorder']->value);
                }
                $statestepid = $stepid;
                $haveorder = true;
                break;
            }
        }
        if ($statestepid === null) {
            $statestepid = array_key_first($steps);
        }

        // The order first shown, as answer ids (any items the attempt never saw go at the end).
        $currentresponse = [];
        foreach ($stemorder as $subid) {
            if (isset($answerids[$subid]) && !in_array($answerids[$subid], $currentresponse)) {
                $currentresponse[] = $answerids[$subid];
            }
        }
        foreach ($answerids as $answerid) {
            if (!in_array($answerid, $currentresponse)) {
                $currentresponse[] = $answerid;
            }
        }
        $correctresponse = array_values($answerids);

        // Replace the attempt state variables.
        $statevars = $bystep[$statestepid];
        $this->write_var($statestepid, '_currentresponse', implode(',', $currentresponse), $statevars['_stemorder'] ?? null);
        $this->write_var($statestepid, '_correctresponse', implode(',', $correctresponse), $statevars['_choiceorder'] ?? null);

        // Convert each step's response: sub0..subN (+ dontknow) => one response_{questionid} variable.
        $md5bysub = [];
        foreach ($data->items as $subid => $item) {
            $md5bysub[$subid] = $item->md5key;
        }
        $fieldname = $question->get_response_fieldname();
        $lastresponse = null;
        foreach ($bystep as $stepid => $vars) {
            $responserows = [];
            $dontknow = false;
            foreach ($vars as $name => $row) {
                if (preg_match('/^sub\d+$/', $name)) {
                    $responserows[$name] = $row;
                } else if (preg_match('/^dontknow\d+$/', $name)) {
                    $responserows[$name] = $row;
                    $dontknow = $dontknow || !empty($row->value);
                }
            }
            if (!$responserows) {
                continue; // Behaviour-only step (submit, finish, manual grade, comment ...): leave it alone.
            }

            $order = $this->decode_response($data, $stemorder, $choiceorder, $responserows);
            $DB->delete_records_list('question_attempt_step_data', 'id', array_column($responserows, 'id'));

            $value = null;
            if ($order !== null || $dontknow) {
                if ($order === null) {
                    $order = $stemorder; // "I don't know": keep the order shown.
                }
                $keys = [];
                foreach ($order as $subid) {
                    if (isset($md5bysub[$subid]) && !in_array($md5bysub[$subid], $keys)) {
                        $keys[] = $md5bysub[$subid];
                    }
                }
                foreach ($md5bysub as $key) {
                    if (!in_array($key, $keys)) {
                        $keys[] = $key;
                    }
                }
                $value = implode(',', $keys);
                $this->write_var($stepid, $fieldname, $value);
                if ($steps[$stepid]->sequencenumber >= 0) {
                    $lastresponse = $value; // Autosaved steps (negative sequence numbers) don't count.
                }
            }

            if ($this->collectdetails) {
                $this->details[] = $this->describe_step($data, $questionattemptid, $steps[$stepid], $stemorder,
                    $choiceorder, $responserows, $dontknow, $order);
            }
        }

        // Refresh the cached text summaries used by reports.
        $step = new \question_attempt_step([
            '_currentresponse' => implode(',', $currentresponse),
            '_correctresponse' => implode(',', $correctresponse),
        ]);
        $question->apply_attempt_state($step);
        $DB->update_record('question_attempts', (object) [
            'id' => $questionattemptid,
            'questionsummary' => $questionsummary,
            'rightanswer' => $question->get_right_answer_summary(),
            'responsesummary' => $lastresponse === null ? null : $question->summarise_response([$fieldname => $lastresponse]),
        ]);

        return $haveorder;
    }

    /**
     * Turn one step's sub{i} values into an item order.
     *
     * @param \stdClass $data from load()
     * @param string[] $stemorder sub ids in the order shown (index i matches sub{i}).
     * @param string[] $choiceorder choice keys (sub ids); a sub{i} value of n means $choiceorder[n - 1].
     * @param \stdClass[] $responserows step data rows keyed by name.
     * @return string[]|null sub ids in the student's order, or null if no item was placed.
     */
    protected function decode_response(\stdClass $data, array $stemorder, array $choiceorder, array $responserows): ?array {
        $placed = [];
        $unplaced = [];
        foreach ($stemorder as $i => $subid) {
            if (!isset($data->items[$subid])) {
                continue;
            }
            $position = $this->picked_position($data, $choiceorder, $responserows['sub' . $i] ?? null);
            if ($position !== null) {
                $placed[] = [$position, $i, $subid];
            } else {
                $unplaced[] = $subid;
            }
        }
        if (!$placed) {
            return null;
        }
        // Order by the position picked; ties (two items given the same position) keep the order shown.
        usort($placed, function($a, $b) {
            return [$a[0], $a[1]] <=> [$b[0], $b[1]];
        });
        return array_merge(array_column($placed, 2), $unplaced);
    }

    /**
     * The position a student picked for one item, or null if they picked none.
     *
     * @param \stdClass $data from load()
     * @param string[] $choiceorder
     * @param \stdClass|null $row the sub{i} step data row
     * @return int|null
     */
    protected function picked_position(\stdClass $data, array $choiceorder, ?\stdClass $row): ?int {
        $value = $row ? (int) $row->value : 0;
        $choicekey = $choiceorder[$value - 1] ?? null;
        if ($value > 0 && $choicekey !== null && isset($data->allsubs[$choicekey])) {
            return (int) $data->allsubs[$choicekey]->answertext;
        }
        return null;
    }

    /**
     * The choice order qtype_order 2012070400 always used: the first sub id for each distinct
     * answertext, in sub id order (it never shuffled the choices).
     *
     * @param \stdClass $data from load()
     * @return string[]
     */
    protected function default_choice_order(\stdClass $data): array {
        $choices = [];
        foreach ($data->allsubs as $sub) {
            if (!in_array($sub->answertext, $choices, true)) {
                $choices[$sub->id] = $sub->answertext;
            }
        }
        return array_map('strval', array_keys($choices));
    }

    /**
     * Describe how one step's old response was converted (for the detail report).
     *
     * @param \stdClass $data
     * @param int $questionattemptid
     * @param \stdClass $step
     * @param string[] $stemorder
     * @param string[] $choiceorder
     * @param \stdClass[] $responserows
     * @param bool $dontknow
     * @param string[]|null $order
     * @return \stdClass
     */
    protected function describe_step(\stdClass $data, int $questionattemptid, \stdClass $step, array $stemorder,
            array $choiceorder, array $responserows, bool $dontknow, ?array $order): \stdClass {
        $old = [];
        foreach ($stemorder as $i => $subid) {
            if (!isset($data->items[$subid])) {
                continue;
            }
            $position = $this->picked_position($data, $choiceorder, $responserows['sub' . $i] ?? null);
            $old[] = $this->item_label($data->items[$subid]) . ' → ' . ($position ?? '–');
        }
        return (object) [
            'questionattemptid' => $questionattemptid,
            'sequencenumber' => (int) $step->sequencenumber,
            'userid' => (int) $step->userid,
            'old' => implode('; ', $old) . ($dontknow ? ' [' . get_string('dontknow', 'tool_qtypeorder') . ']' : ''),
            'new' => $order === null ? get_string('noresponse', 'tool_qtypeorder') :
                implode('; ', array_map(function($subid) use ($data) {
                        return $this->item_label($data->items[$subid]);
                    }, array_values(array_intersect($order, array_keys($data->items))))),
        ];
    }

    /**
     * Short plain-text label for an item.
     *
     * @param \stdClass $item
     * @return string
     */
    protected function item_label(\stdClass $item): string {
        $text = trim(html_to_text($item->newtext, 0, false));
        if ($text === '') {
            $text = '#' . $item->position;
        }
        return shorten_text($text, 30, true);
    }

    /**
     * Insert or update one step data variable.
     *
     * @param int $stepid
     * @param string $name
     * @param string $value
     * @param \stdClass|null $existing an existing row to reuse (it is renamed).
     */
    protected function write_var(int $stepid, string $name, string $value, ?\stdClass $existing = null): void {
        global $DB;
        if ($existing) {
            $DB->update_record('question_attempt_step_data', (object) [
                'id' => $existing->id,
                'name' => $name,
                'value' => $value,
            ]);
        } else {
            $DB->insert_record('question_attempt_step_data', (object) [
                'attemptstepid' => $stepid,
                'name' => $name,
                'value' => $value,
            ]);
        }
    }

    // Self-check.

    /**
     * Check that a converted question and all its attempts look right and load in the question engine.
     *
     * @param int $questionid
     * @return \stdClass with errors[], warnings[], attempts (count).
     */
    protected function run_checks(int $questionid): \stdClass {
        global $DB;

        $errors = [];
        $warnings = [];
        $add = function(string $message) use (&$errors) {
            if (count($errors) < self::MAX_MESSAGES) {
                $errors[] = $message;
            } else if (count($errors) === self::MAX_MESSAGES) {
                $errors[] = get_string('checkmore', 'tool_qtypeorder');
            }
        };
        $str = function($id, $a = null) {
            return get_string('check' . $id, 'tool_qtypeorder', $a);
        };

        // The definition.
        $qtype = $DB->get_field('question', 'qtype', ['id' => $questionid]);
        if ($qtype !== self::NEW_QTYPE) {
            $add($str('qtype', $qtype));
        }
        if (!$DB->record_exists('qtype_ordering_options', ['questionid' => $questionid])) {
            $add($str('nooptions'));
        }
        if ($DB->record_exists('question_order', ['question' => $questionid]) ||
                $DB->record_exists('question_order_sub', ['question' => $questionid])) {
            $add($str('oldrows'));
        }
        $answers = $DB->get_records('question_answers', ['question' => $questionid], 'fraction, id');
        if (count($answers) < 2) {
            $add($str('fewanswers', count($answers)));
        }
        $fractions = array_map('floatval', array_column($answers, 'fraction'));
        if ($fractions !== array_map('floatval', range(1, max(1, count($answers))))) {
            $add($str('fractions'));
        }
        $md5 = [];
        foreach ($answers as $answer) {
            $md5[$answer->id] = 'ordering_item_' . md5($this->salt . $answer->answer);
        }
        if (count(array_unique($md5)) < count($md5)) {
            $warnings[] = get_string('warningduplicatetext', 'tool_qtypeorder');
        }
        $answerids = array_map('intval', array_keys($answers));
        $sortedids = $answerids;
        sort($sortedids);

        // The engine can load the question.
        try {
            \question_bank::notify_question_edited($questionid);
            \question_bank::load_question($questionid);
        } catch (\Throwable $e) {
            $add($str('loadquestion', $e->getMessage()));
        }

        // The attempts.
        $fieldname = 'response_' . $questionid;
        $attempts = $DB->get_records('question_attempts', ['questionid' => $questionid], 'id', 'id, questionusageid, slot');
        $byusage = [];
        foreach ($attempts as $qa) {
            $byusage[$qa->questionusageid][] = $qa;
            $rows = $DB->get_records_sql(
                "SELECT d.id, d.name, d.value, s.sequencenumber
                   FROM {question_attempt_step_data} d
                   JOIN {question_attempt_steps} s ON s.id = d.attemptstepid
                  WHERE s.questionattemptid = :qaid
               ORDER BY s.sequencenumber, d.id", ['qaid' => $qa->id]);
            $current = null;
            $correct = null;
            foreach ($rows as $row) {
                $a = (object) ['qaid' => $qa->id, 'name' => $row->name];
                if (preg_match('/^(sub\d+|dontknow\d+|_stemorder|_choiceorder)$/', $row->name)) {
                    $add($str('oldvar', $a));
                } else if ($row->name === '_currentresponse' && $current === null) {
                    $current = array_map('intval', explode(',', $row->value));
                } else if ($row->name === '_correctresponse' && $correct === null) {
                    $correct = array_map('intval', explode(',', $row->value));
                } else if ($row->name === $fieldname) {
                    $keys = explode(',', $row->value);
                    $sortedkeys = $keys;
                    sort($sortedkeys);
                    $wantkeys = array_values($md5);
                    sort($wantkeys);
                    if ($sortedkeys !== $wantkeys) {
                        $add($str('badresponse', $a));
                    }
                }
            }
            if ($current === null || $correct === null) {
                if ($rows) {
                    $add($str('nostate', $qa->id));
                }
                continue;
            }
            $sortedcurrent = $current;
            sort($sortedcurrent);
            if ($sortedcurrent !== $sortedids) {
                $add($str('badcurrent', $qa->id));
            }
            if ($correct !== $answerids) {
                $add($str('badcorrect', $qa->id));
            }
        }

        // Every attempt loads and renders, as on the review page.
        foreach ($byusage as $usageid => $qas) {
            try {
                $quba = \question_engine::load_questions_usage_by_activity($usageid);
                foreach ($qas as $qa) {
                    $quba->render_question($qa->slot, new \question_display_options());
                }
            } catch (\Throwable $e) {
                $add($str('render', (object) ['usageid' => $usageid, 'message' => $e->getMessage()]));
            }
        }

        return (object) ['errors' => $errors, 'warnings' => $warnings, 'attempts' => count($attempts)];
    }

    // Undo backups.

    /**
     * The folder holding undo backups.
     *
     * @return string
     */
    public static function backup_dir(): string {
        global $CFG;
        return $CFG->dataroot . '/tool_qtypeorder/backups';
    }

    /**
     * Undo backups that have not been used, newest per question.
     *
     * @return string[] question id => file path
     */
    public function list_backups(): array {
        $files = glob(self::backup_dir() . '/question-*.json') ?: [];
        sort($files);
        $list = [];
        foreach ($files as $file) {
            if (preg_match('/question-(\d+)-\d+\.json$/', $file, $m)) {
                $list[(int) $m[1]] = $file; // Later files (newer timestamps) win.
            }
        }
        return $list;
    }

    /**
     * Capture everything the conversion of this question will change.
     *
     * @param \stdClass $data from load()
     * @return array
     */
    protected function build_backup(\stdClass $data): array {
        global $DB;
        $questionid = (int) $data->question->id;

        $files = [];
        if ($data->allsubs) {
            [$insql, $params] = $DB->get_in_or_equal(array_keys($data->allsubs), SQL_PARAMS_NAMED);
            $params += ['contextid' => $data->question->contextid, 'component' => 'qtype_order', 'filearea' => 'subquestion'];
            $files = $DB->get_records_select('files', "contextid = :contextid AND component = :component
                AND filearea = :filearea AND itemid $insql", $params, 'id');
        }

        $attempts = $DB->get_records('question_attempts', ['questionid' => $questionid], 'id',
            'id, questionsummary, rightanswer, responsesummary');
        $stepids = [];
        $stepdata = [];
        if ($attempts) {
            [$insql, $params] = $DB->get_in_or_equal(array_keys($attempts));
            $stepids = array_map('intval', $DB->get_fieldset_select('question_attempt_steps', 'id',
                "questionattemptid $insql", $params));
            if ($stepids) {
                [$insql, $params] = $DB->get_in_or_equal($stepids);
                $stepdata = $DB->get_records_select('question_attempt_step_data', "attemptstepid $insql", $params, 'id');
            }
        }

        return [
            'version' => self::BACKUP_VERSION,
            'questionid' => $questionid,
            'time' => time(),
            'question_order' => array_values($DB->get_records('question_order', ['question' => $questionid], 'id')),
            'question_order_sub' => array_values($data->allsubs),
            'files' => array_values($files),
            'question_hints' => array_values($DB->get_records('question_hints', ['questionid' => $questionid], 'id')),
            'question_attempts' => array_values($attempts),
            'stepids' => $stepids,
            'question_attempt_step_data' => array_values($stepdata),
        ];
    }

    /**
     * Write an undo backup to moodledata.
     *
     * @param array $backup from build_backup()
     * @return string the file written
     */
    protected function save_backup(array $backup): string {
        $dir = self::backup_dir();
        make_writable_directory($dir);
        // Drop the derived fields added by load() before saving.
        $backup['question_order_sub'] = array_map(function($sub) {
            $sub = clone $sub;
            unset($sub->position, $sub->newtext, $sub->md5key);
            return $sub;
        }, $backup['question_order_sub']);
        $file = sprintf('%s/question-%d-%s.json', $dir, $backup['questionid'], date('YmdHis'));
        if (file_put_contents($file, json_encode($backup)) === false) {
            throw new \moodle_exception('errorbackupwrite', 'tool_qtypeorder', '', $file);
        }
        return $file;
    }

    /**
     * Put a converted question back as it was, from its newest undo backup.
     *
     * Refused if anything has happened to the question's attempts since it was converted
     * (new attempts, new steps such as a regrade or manual grading), because those could not be kept.
     *
     * @param int $questionid
     * @return \stdClass result with status 'undone' or 'failed'.
     */
    public function undo_question(int $questionid): \stdClass {
        global $DB;

        $question = $DB->get_record('question', ['id' => $questionid], 'id, name, qtype');
        $result = (object) [
            'questionid' => $questionid, 'name' => $question->name ?? '?', 'items' => 0, 'attempts' => 0,
            'attemptsconverted' => 0, 'status' => 'failed', 'warnings' => [], 'errors' => [], 'details' => [],
        ];

        $backups = $this->list_backups();
        if (empty($backups[$questionid])) {
            $result->errors[] = get_string('undonobackup', 'tool_qtypeorder');
            return $result;
        }
        $file = $backups[$questionid];
        $backup = json_decode(file_get_contents($file));
        if (!$backup || ($backup->version ?? 0) !== self::BACKUP_VERSION || (int) $backup->questionid !== $questionid) {
            $result->errors[] = get_string('undobadbackup', 'tool_qtypeorder', $file);
            return $result;
        }
        $result->items = count($backup->question_order_sub);
        $result->attempts = count($backup->question_attempts);

        // Has anything changed since the conversion?
        if (!$question || $question->qtype !== self::NEW_QTYPE) {
            $result->errors[] = get_string('undonotconverted', 'tool_qtypeorder', $question->qtype ?? '?');
            return $result;
        }
        $attemptids = array_map('intval', array_keys($DB->get_records('question_attempts', ['questionid' => $questionid],
            'id', 'id')));
        $backupattemptids = array_map('intval', array_column($backup->question_attempts, 'id'));
        sort($backupattemptids);
        if ($attemptids !== $backupattemptids) {
            $result->errors[] = get_string('undonewattempts', 'tool_qtypeorder');
            return $result;
        }
        $stepids = [];
        if ($attemptids) {
            [$insql, $params] = $DB->get_in_or_equal($attemptids);
            $stepids = array_map('intval', $DB->get_fieldset_select('question_attempt_steps', 'id',
                "questionattemptid $insql", $params));
        }
        $backupstepids = array_map('intval', $backup->stepids);
        sort($stepids);
        sort($backupstepids);
        if ($stepids !== $backupstepids) {
            $result->errors[] = get_string('undonewsteps', 'tool_qtypeorder');
            return $result;
        }
        $fs = get_file_storage();
        foreach ($backup->files as $file) {
            if (!$fs->get_file_system()->is_file_readable_locally_by_hash($file->contenthash) &&
                    !$fs->get_file_system()->is_file_readable_remotely_by_hash($file->contenthash)) {
                $result->errors[] = get_string('undomissingfile', 'tool_qtypeorder', $file->filename);
                return $result;
            }
        }

        $transaction = $DB->start_delegated_transaction();
        try {
            // Restore old rows first (with their original ids), so shared file content stays referenced.
            foreach ($backup->files as $row) {
                $DB->insert_record_raw('files', (array) $row, false, false, true);
            }
            foreach ($backup->question_order as $row) {
                $DB->insert_record_raw('question_order', (array) $row, false, false, true);
            }
            foreach ($backup->question_order_sub as $row) {
                $DB->insert_record_raw('question_order_sub', (array) $row, false, false, true);
            }

            // Remove the converted definition.
            $contextid = $DB->get_field_sql("SELECT qc.contextid
                  FROM {question} q
                       {$this->category_joins(false)}
                 WHERE q.id = ?", [$questionid]);
            foreach ($DB->get_records('question_answers', ['question' => $questionid], 'id', 'id') as $answer) {
                if ($contextid) {
                    $fs->delete_area_files($contextid, 'question', 'answer', $answer->id);
                }
            }
            $DB->delete_records('question_answers', ['question' => $questionid]);
            $DB->delete_records('qtype_ordering_options', ['questionid' => $questionid]);

            // Hints: the engine may have filled in nulls when it loaded the converted question.
            foreach ($backup->question_hints as $row) {
                $DB->update_record_raw('question_hints', (array) $row);
            }

            // Attempts: put every step variable back exactly as it was.
            if ($backupstepids) {
                [$insql, $params] = $DB->get_in_or_equal($backupstepids);
                $DB->delete_records_select('question_attempt_step_data', "attemptstepid $insql", $params);
            }
            foreach ($backup->question_attempt_step_data as $row) {
                $DB->insert_record_raw('question_attempt_step_data', (array) $row, false, false, true);
            }
            foreach ($backup->question_attempts as $row) {
                $DB->update_record_raw('question_attempts', (array) $row);
            }

            $DB->set_field('question', 'qtype', self::OLD_QTYPE, ['id' => $questionid]);
            $transaction->allow_commit();
            $result->status = 'undone';
        } catch (\Throwable $e) {
            try {
                $transaction->rollback($e);
            } catch (\Throwable $rethrown) {
                unset($rethrown);
            }
            $result->errors[] = $e->getMessage();
        }
        \question_bank::notify_question_edited($questionid);

        if ($result->status === 'undone') {
            // Keep the file for the record, but stop it being offered again.
            rename($file, $file . '.used');
        }
        return $result;
    }
}
