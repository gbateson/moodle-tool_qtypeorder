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
 * Strings for component 'tool_qtypeorder', language 'en', branch 'MOODLE_22_STABLE'
 *
 * @package    tool
 * @subpackage qtypeorder
 * @copyright  2015 Gordon Bateson {@link http://quizport.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// essential strings
$string['pluginname'] = 'Migrate Ordering questions';

// more strings
$string['pluginname_help'] = 'This Moodle administrator tool will migrate OLD Ordering questions,  qtype_order, to NEW Ordering questions, qtype_ordering.

Please be aware that this tool will make numerous irrevocable changes to your Moodle database, so please be sure to **take a backup of your entire database** before you begin.

To run this tool ...

* set "Confirm" to "Yes"
* click the "Go" button.

There are currently {$a} OLD Ordering questions on this Moodle site.';
$string['migratingquestions'] = 'Migrating questions';
$string['cannotcontinue'] = 'Sorry, at this time the migration of question types on this Moodle site cannot continue.';
$string['noorderingtable'] = 'The database tables for the NEW Ordering question type, qtype_ordering, are missing. Perhaps the "qtype_ordering" plugin is not installed?';
$string['noordertable'] = 'The database tables for the OLD Ordering question type, qtype_order, are missing. Perhaps the "qtype_order" plugin is not installed?';
$string['noquestions'] = 'There are no OLD ordering questions.';

