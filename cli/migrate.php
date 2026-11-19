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
 * CLI: preview, simulate, migrate, check or undo qtype_order -> qtype_ordering conversions.
 *
 * @package    tool_qtypeorder
 * @copyright  2026 updated for Moodle 3.9 to 4.5
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use tool_qtypeorder\migrator;

[$options, $unrecognised] = cli_get_params(
    ['simulate' => false, 'execute' => false, 'check' => false, 'undo' => false,
     'questionid' => '', 'courseid' => 0, 'categoryid' => 0, 'limit' => 0,
     'details' => false, 'verbose' => false, 'help' => false],
    ['s' => 'simulate', 'e' => 'execute', 'c' => 'check', 'u' => 'undo', 'q' => 'questionid',
     'l' => 'limit', 'd' => 'details', 'v' => 'verbose', 'h' => 'help']
);

if ($unrecognised) {
    cli_error(get_string('cliunknowoption', 'admin', implode("\n  ", $unrecognised)));
}

$help = "Convert legacy qtype_order questions (and their attempts) to core qtype_ordering.

Modes (pick at most one; the default is a preview):
  (none)               Preview: look at each question and report problems. Changes nothing.
  -s, --simulate       Do the full conversion and self-check in a transaction, then roll it back.
                       Changes nothing, but finds every problem a real run would hit.
  -e, --execute        Convert for real. An undo backup of each question is saved in
                       moodledata/tool_qtypeorder/backups first, and each question is self-checked
                       (and rolled back if the check fails).
  -c, --check          Self-check questions this tool has already converted.
  -u, --undo           Put converted questions back as they were (needs --questionid).
                       Refused if the question has new attempts or grading since it was converted.

Choosing questions (combine as needed):
  -q, --questionid=IDS One or more question ids, e.g. --questionid=75737 or --questionid=75737,75740
      --courseid=ID    Questions in this course's question banks, or used by its quizzes.
      --categoryid=ID  Questions in this question category (not its subcategories).
  -l, --limit=N        Process at most N questions.

Output:
  -d, --details        With --simulate or --execute: show how every saved response was converted.
  -v, --verbose        List every question, not only those with problems.
  -h, --help           Print this help.

Suggested order:
  php admin/tool/qtypeorder/cli/migrate.php                          # preview everything
  php admin/tool/qtypeorder/cli/migrate.php -s -q=75737 -d           # rehearse one question, see details
  php admin/tool/qtypeorder/cli/migrate.php -e -q=75737              # convert it
  php admin/tool/qtypeorder/cli/migrate.php -c -q=75737              # check it again later
  php admin/tool/qtypeorder/cli/migrate.php -u -q=75737              # put it back, if needed
  php admin/tool/qtypeorder/cli/migrate.php -s                       # rehearse everything
  php admin/tool/qtypeorder/cli/migrate.php -e --limit=200           # convert in batches
";

if ($options['help']) {
    echo $help;
    exit(0);
}

$modes = array_keys(array_filter(['simulate' => $options['simulate'], 'migrate' => $options['execute'],
    'check' => $options['check'], 'undo' => $options['undo']]));
if (count($modes) > 1) {
    cli_error('Choose only one of --simulate, --execute, --check, --undo.');
}
$mode = $modes[0] ?? 'preview';

$filters = [
    'questionids' => migrator::parse_ids($options['questionid']),
    'courseid' => (int) $options['courseid'],
    'categoryid' => (int) $options['categoryid'],
    'limit' => (int) $options['limit'],
];
if ($mode === 'undo' && !$filters['questionids']) {
    cli_error('--undo needs --questionid.');
}

if ($problems = migrator::check_environment()) {
    foreach ($problems as $problem) {
        cli_problem(get_string($problem, 'tool_qtypeorder'));
    }
    exit(1);
}

\core\session\manager::set_user(get_admin());
$PAGE->set_context(context_system::instance());
$PAGE->set_url('/admin/tool/qtypeorder/index.php');
core_php_time_limit::raise();
raise_memory_limit(MEMORY_HUGE);

$titles = ['preview' => 'Preview (nothing will be changed)', 'simulate' => 'Simulation (nothing will be changed)',
    'migrate' => 'Migrating', 'check' => 'Checking converted questions', 'undo' => 'Undoing conversions'];
cli_heading($titles[$mode]);

$migrator = new migrator();
$details = $options['details'] && in_array($mode, ['simulate', 'migrate']);
$results = $migrator->run($mode, $filters, $details, function($index, $total, $r) use ($options) {
    if ($options['verbose'] || $r->errors || $r->warnings || $r->details) {
        mtrace(sprintf('[%d/%d] question %d "%s": %s, %d item(s), %d attempt(s)',
            $index, $total, $r->questionid, $r->name, $r->status, $r->items, $r->attempts));
        foreach ($r->errors as $e) {
            mtrace("    ERROR: $e");
        }
        foreach ($r->warnings as $w) {
            mtrace("    warning: $w");
        }
        foreach ($r->details as $d) {
            mtrace("    attempt {$d->questionattemptid} step {$d->sequencenumber} (user {$d->userid})");
            mtrace("        old: {$d->old}");
            mtrace("        new: {$d->new}");
        }
    } else if ($index % 100 === 0) {
        mtrace("[$index/$total] ...");
    }
});

mtrace('');
if (!$results) {
    mtrace('No questions matched.');
}
$tally = migrator::tally($results);
$line = [];
foreach ($tally as $status => $count) {
    $line[] = "$status: $count";
}
mtrace(count($results) . ' question(s). ' . implode(', ', $line));
if ($mode === 'preview' || $mode === 'simulate') {
    mtrace('Nothing was changed.');
}
if ($mode === 'migrate' && !empty($tally['converted'])) {
    mtrace('Undo backups: ' . migrator::backup_dir());
}
$problems = ($tally['failed'] ?? 0) + ($tally['checkfailed'] ?? 0);
foreach ($results as $r) {
    $problems += ($r->status === 'simulated' && $r->errors) ? 1 : 0;
}
exit($problems ? 1 : 0);
