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
 * Admin page: preview, simulate, migrate, check and undo qtype_order -> qtype_ordering conversions.
 *
 * @package    tool_qtypeorder
 * @copyright  2015 Gordon Bateson, 2026 updated for Moodle 3.9 to 4.5
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('NO_OUTPUT_BUFFERING', true);

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use tool_qtypeorder\form\migrate_form;
use tool_qtypeorder\migrator;

admin_externalpage_setup('toolqtypeorder');

$plugin = 'tool_qtypeorder';
$form = new migrate_form();

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('pluginname', $plugin));

$problems = migrator::check_environment();
if ($problems) {
    $text = '**' . get_string('cannotcontinue', $plugin) . "**\n";
    foreach ($problems as $problem) {
        $text .= "\n* " . get_string($problem, $plugin);
    }
    echo $OUTPUT->notification(format_text($text, FORMAT_MARKDOWN), 'error');
    echo $OUTPUT->footer();
    die();
}

$migrator = new migrator();

if ($data = $form->get_data()) {
    core_php_time_limit::raise();
    raise_memory_limit(MEMORY_EXTRA);

    $mode = migrate_form::get_mode($data);
    $filters = [
        'questionids' => migrator::parse_ids($data->questionids),
        'courseid' => (int) $data->courseid,
        'categoryid' => (int) $data->categoryid,
        'limit' => (int) $data->limit,
    ];
    $details = !empty($data->details) && in_array($mode, ['simulate', 'migrate']);

    echo $OUTPUT->heading(get_string('results', $plugin, get_string('mode' . $mode, $plugin)), 3);
    $bar = new progress_bar('tool_qtypeorder_progress', 500, true);
    $results = $migrator->run($mode, $filters, $details, function($index, $total) use ($bar, $plugin) {
        $bar->update($index, $total, get_string('progress', $plugin, (object) ['index' => $index, 'total' => $total]));
    });
    if (!$results) {
        $bar->update_full(100, get_string('nomatch', $plugin));
    }

    // Summary line.
    $tally = migrator::tally($results);
    $parts = [];
    foreach ($tally as $status => $count) {
        if (!in_array($status, ['attempts', 'warnings'])) {
            $parts[] = get_string('status' . $status, $plugin) . ': ' . $count;
        }
    }
    $summary = get_string('summary', $plugin, (object) [
        'questions' => count($results), 'counts' => implode(', ', $parts) ?: '-',
        'attempts' => $tally['attempts'], 'warnings' => $tally['warnings'],
    ]);
    if ($mode === 'preview' || $mode === 'simulate') {
        $summary .= ' ' . get_string('nothingchanged', $plugin);
    }
    $bad = ($tally['failed'] ?? 0) + ($tally['checkfailed'] ?? 0) + ($tally['skipped'] ?? 0);
    foreach ($results as $r) {
        $bad += ($r->status === 'simulated' && $r->errors) ? 1 : 0;
    }
    echo $OUTPUT->notification($summary, $bad ? 'warning' : 'success');

    // Questions that need attention (or every question, when details were asked for).
    $table = new html_table();
    $table->head = [
        get_string('questionid', $plugin), get_string('name'), get_string('items', $plugin),
        get_string('attempts', $plugin), get_string('status'), get_string('messages', $plugin),
    ];
    foreach ($results as $r) {
        if (!$r->errors && !$r->warnings && !$r->details) {
            continue;
        }
        $messages = array_merge(
            array_map(function($e) {
                return html_writer::div(s($e), 'text-danger');
            }, $r->errors),
            array_map(function($w) {
                return html_writer::div(s($w), 'text-warning');
            }, $r->warnings)
        );
        $table->data[] = [
            $r->questionid, s($r->name), $r->items, $r->attempts,
            get_string('status' . $r->status, $plugin), implode('', $messages),
        ];
    }
    if ($table->data) {
        echo $OUTPUT->heading(get_string('needsattention', $plugin), 4);
        echo html_writer::table($table);
    }

    // Per-attempt details.
    $shown = 0;
    foreach ($results as $r) {
        if (!$r->details || $shown >= 500) {
            continue;
        }
        echo $OUTPUT->heading(get_string('detailsfor', $plugin, (object) ['id' => $r->questionid, 'name' => s($r->name)]), 5);
        $dtable = new html_table();
        $dtable->head = [
            get_string('questionattemptid', $plugin), get_string('step', $plugin), get_string('user'),
            get_string('oldresponse', $plugin), get_string('newresponse', $plugin),
        ];
        foreach ($r->details as $d) {
            if ($shown++ >= 500) {
                break;
            }
            $dtable->data[] = [$d->questionattemptid, $d->sequencenumber, $d->userid, s($d->old), s($d->new)];
        }
        echo html_writer::table($dtable);
    }

    if ($mode === 'migrate' && !empty($tally['converted'])) {
        echo $OUTPUT->notification(get_string('backupsaved', $plugin, migrator::backup_dir()), 'info');
    }
}

// Current state of the site.
$counts = $migrator->get_counts();
$text = get_string('intro', $plugin, $counts);
if ($counts->nooptions) {
    $text .= "\n\n* " . get_string('countnooptions', $plugin, $counts->nooptions);
}
if ($counts->noquestion) {
    $text .= "\n\n* " . get_string('countnoquestion', $plugin, $counts->noquestion);
}
echo $OUTPUT->box(format_text($text, FORMAT_MARKDOWN), 'generalbox');

$form->display();

echo $OUTPUT->footer();
