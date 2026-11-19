# Migrate Ordering questions (tool_qtypeorder) for Moodle 3.9 to 4.5

An admin tool that converts legacy **qtype_order** questions, and every existing attempt
at them, to the **qtype_ordering** question type. It is tested against data in the
format of qtype_order `2012070400`.

This is a fork of [gbateson/moodle-tool_qtypeorder](https://github.com/gbateson/moodle-tool_qtypeorder)
by Gordon Bateson. The original targeted Moodle 2.x, and this fork brings it up to date for Moodle 3.9 to 4.5.
The original fails on Moodle 4.x with `Unknown column 'q.category'`.

## Supported versions

| Moodle | qtype_ordering | Tested |
|---|---|---|
| 3.9 LTS | add-on plugin, install it first | yes: Moodle 3.9.25, PHP 7.4, qtype_ordering 2022070806 |
| 3.10, 3.11, 4.0 | add-on plugin, install it first | no |
| 4.1 LTS | add-on plugin, install it first | yes: Moodle 4.1.22+, PHP 8.1, qtype_ordering 2023092910 |
| 4.2 | add-on plugin, install it first | no |
| 4.3, 4.4 | part of core | no |
| 4.5 LTS | part of core | yes: Moodle 4.5.16+, PHP 8.3 |

The untested versions run the same code as the tested version next to them. The tool's code
runs on PHP 7.2 and later, so any PHP version the Moodle release supports is fine.

The tool checks which question bank layout the site has: from Moodle 4.0, questions reach their
category through `question_versions` and `question_bank_entries`, and before that through
`question.category`.

## Features

- **Preview** reports problems with each question and changes nothing.
- **Simulate** is a full dress rehearsal. Each question and all its attempts are converted and
  self-checked inside a database transaction, and then everything is rolled back. It finds every
  problem a real run would hit, and leaves the database untouched.
- **Migrate** converts for real:
  - Each question is converted in its own transaction.
  - An **undo backup** of everything that will change is saved first.
  - The result is **self-checked** before commit. If the check fails, that question is rolled back and left unconverted.
- **Check converted** re-runs the self-check on questions already converted.
- **Undo** puts a converted question back exactly as it was, from its backup. It's refused if
  the question has new attempts or grading since it was converted, because those couldn't be kept.
- **Targeting:** one or more question ids, a course (its question banks and the questions its
  quizzes use), a question category, or a maximum number per run.
- **Details:** for each saved student response, it shows the order the student saw, the position they
  picked for each item, and the order it becomes, so you can spot-check against real attempts.
- The self-check confirms:
  - the question type, options and items (positions 1..n)
  - no qtype_order rows or variables are left
  - every attempt's stored orders are complete
  - every saved response contains exactly the question's items
  - the question engine can load and **display every attempt**
- Everything is available in the web UI (*Site administration → Plugins → Admin tools →
  Migrate Ordering questions*) and in a CLI script for large sites.

## Fixes compared with the original tool

| Problem in the original | Fix |
|---|---|
| Used `question.category`, which Moodle 4.0 removed. | Uses `question_versions` → `question_bank_entries` → `question_categories`. |
| Deleted attempt variables it didn't recognise, including `-mark`, `-comment`, `-maxmark` and `-submit`, so manual grades and teacher comments were lost. | Only `sub*`, `dontknow*`, `_stemorder` and `_choiceorder` are touched. |
| Sorted items by `answertext` as text, so "10" came before "2". | Sorts by numeric position, then by id. |
| Read a student's answer value as the position itself. | Decodes it as qtype_order did, through `_choiceorder` and the chosen item's position. |
| A typo wrote the format number into the feedback text. | Fixed. |
| No transactions. | One transaction per question. |
| Blanked attempt summaries shown in reports. | Regenerates them with qtype_ordering itself. |

## Install

On Moodle 3.9 to 4.2, first install the
[qtype_ordering plugin](https://moodle.org/plugins/qtype_ordering) (the tool refuses to run without it).

Copy or clone this repository into `admin/tool/qtypeorder`, then visit
*Site administration → Notifications*.

```bash
git clone https://github.com/Darwin5k/moodle-tool_qtypeorder.git admin/tool/qtypeorder
```

The old qtype_order plugin code is **not** needed. The tool only needs its tables
(`question_order`, `question_order_sub`) to still exist.

## Recommended procedure

Take a full backup of the database and moodledata before migrating.

```bash
cd /path/to/moodle
php admin/tool/qtypeorder/cli/migrate.php                       # 1. preview everything
php admin/tool/qtypeorder/cli/migrate.php -s                    # 2. simulate everything
php admin/tool/qtypeorder/cli/migrate.php -s -q=1234 -d         # 3. rehearse one question, with details
php admin/tool/qtypeorder/cli/migrate.php -e -q=1234            # 4. convert just that question
#    ... look at it in the question bank, a quiz attempt review and the quiz reports ...
php admin/tool/qtypeorder/cli/migrate.php -u -q=1234            # 5. (optional) undo it, to try undo
php admin/tool/qtypeorder/cli/migrate.php -e --courseid=56      # 6. convert one course
php admin/tool/qtypeorder/cli/migrate.php -e --limit=200        # 7. convert the rest, in batches
php admin/tool/qtypeorder/cli/migrate.php -c                    # 8. check everything converted
```

`php admin/tool/qtypeorder/cli/migrate.php --help` lists every option. The CLI exits with a
non-zero status if any question fails, fails its check, or has problems in a simulation.

Undo backups are JSON files in `moodledata/tool_qtypeorder/backups/`. They contain copies of
attempt data, so delete the folder once you're happy with the migration.

## Things to know

- **Marks aren't changed.** Existing attempts keep the marks they were given. **Don't regrade**
  quizzes with converted questions unless you want new marks: qtype_ordering grades differently
  from qtype_order. The tool uses *absolute position*, which is the closest match. Regrading also stops a question from being undone.
- Students who left items unplaced, or gave two items the same position, get those items placed
  after the placed items, in the order they were shown. An "I don't know" response keeps the order shown.
- **Identical item text** can't be told apart by qtype_ordering. These questions are flagged.
- qtype_ordering releases for Moodle 3.x have no **"show number correct"** setting, so on those sites
  the setting is dropped, with a warning. Everything else carries over.
- **Course backups made before the migration** still contain qtype_order data. Moodle 4.x can't
  restore it. Restore such a backup on a site that still has qtype_order, migrate there, and back up again.
- Random questions that filter by question type "order" (rare) need editing by hand.

## Testing

`tests/manual/` has the test harness used during development. It's excluded from release zips.
It needs a local, throwaway Moodle site, because it creates courses, users and attempts. See
[tests/manual/README.md](tests/manual/README.md).

The suite passes in full on Moodle 3.9, 4.1 and 4.5, with MariaDB 11.4. It builds 11 legacy questions
with 43 attempts and covers the following:
- **Quiz modes:** deferred feedback, interactive with hints and a second try, adaptive.
- **Attempt states:** in-progress attempts with autosaves, question bank previews.
- **Questions:** multiple versions, random slots, course and system banks, images, horizontal layout.
- **Item text:** 11 items, Unicode and rich text, a blank item, duplicate text.
- **Failure and rollback:** an injected database failure partway through a conversion, simulate leaving
  every table byte-identical, and migrate followed by undo leaving every table byte-identical.
- **After migration:**
  - re-running is harmless
  - new attempts score correctly
  - regrading works, and undo is then refused
  - course backup and restore keep attempts displaying
  - the Responses and Statistics reports work
  - editing a converted question creates a correct new version

## Credits and licence

- The original tool is © 2015 Gordon Bateson. Commissioned by Prof. Don Hinkelman and sponsored
  by Sapporo Gakuin University. File migration was contributed by Vadimonus.
- The update for Moodle 3.9 to 4.5 dates from 2026.

Licensed under the GNU GPL v3 or later, like Moodle.
