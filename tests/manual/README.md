# Manual test harness

These scripts test the tool against realistic legacy data. They **create courses, users,
questions and attempts**, so only ever run them on a throwaway local site. `create_fixtures.php`
refuses to run unless `wwwroot` contains `localhost`.

They aren't PHPUnit tests. They need PHP 7.4 or later. They were run, with every test passing, on three
local sites sharing one MariaDB 11.4 server:

| Site | Moodle | PHP | qtype_ordering | Database |
|---|---|---|---|---|
| `testenv39/` | 3.9.25 (MOODLE_39_STABLE) | 7.4 | add-on 2022070806 | `moodle39` |
| `testenv41/` | 4.1.22+ (MOODLE_401_STABLE) | 8.1 | add-on 2023092910 | `moodle41` |
| `testenv/` | 4.5.16+ (MOODLE_405_STABLE) | 8.3 | core | `moodle45` |

Each site is laid out like this:

```
testenv/
  moodle/             Moodle checkout, with this plugin in admin/tool/qtypeorder
  moodledata/
  php.sh              runs PHP with the right php.ini
  reset.sh            restores the DB + moodledata snapshot taken after create_fixtures.php
  sync.sh             copies the plugin source into moodle/admin/tool/qtypeorder
  snapshot/           <database>.sql + moodledata copy
  scripts/            the files in this folder
```

The database is MariaDB on 127.0.0.1:3307. `run_tests.sh` reads the database name from the site's
`config.php`. It also uses a second database, the same name plus `_snap`, holding the snapshot, so that
undo can be compared row by row.

## Files

- **create_fixtures.php** creates real shortanswer questions, attempts them through the real quiz and
  question engine, then rewrites them into the exact qtype_order 2012070400 format:
  - 11 questions, 3 quizzes (deferred feedback, interactive, adaptive), and previews
  - autosaves, versions (on 3.9, which has none, a second question with the same name), a random slot, course and system banks, an image, and Unicode text
  - each response is one of: correct, reversed, partly answered with a duplicate position, "I don't know", or unanswered

  It writes `expected.json`.
- **compat.php** has small helpers so the scripts work on Moodle 3.9, 4.1 and 4.5.
- **verify.php** runs after a full migration and checks the result in about 450 ways against `expected.json`.
- **post_tests.php** handles the undo round trip compared with the snapshot, a new attempt on converted
  questions, regrade-then-undo refused, and checking a restored course.
- **run_tests.sh** runs the whole suite, about 40 tests, starting from the snapshot. It includes:
  - fault injection: a database trigger that fails one question partway through conversion
  - checksums of every relevant table, to prove simulate and migrate-then-undo change nothing
  - a course backup and restore

```bash
./reset.sh --rebuild     # once: install Moodle, create fixtures, take the snapshot
scripts/run_tests.sh     # any time after that
```
