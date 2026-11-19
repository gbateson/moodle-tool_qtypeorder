#!/bin/sh
# Runs the whole non-intensive test suite against the local test site.
# Starts from the pre-migration snapshot each time. Run from anywhere:  testenv/scripts/run_tests.sh
cd "$(dirname "$0")/.."
# The site's database name (moodle45, moodle41, ...) is read from its config.php.
DBNAME=$(sed -n "s/^\$CFG->dbname *= *'\(.*\)';/\1/p" moodle/config.php)
# The MariaDB client: this folder's, or the 4.5 test environment's next to it.
MARIADB=./mariadb/bin/mariadb.exe; [ -x $MARIADB ] || MARIADB=../testenv/mariadb/bin/mariadb.exe
# Local throwaway test database. Override with TESTDB_ARGS="-h... -P... -u... -p...".
DB="$MARIADB ${TESTDB_ARGS:--h127.0.0.1 -P3307 -uroot -pmoodletest-root}"
CLI="./php.sh moodle/admin/tool/qtypeorder/cli/migrate.php"
PASS=0
FAIL=0
ok()   { PASS=$((PASS+1)); echo "PASS $1"; }
bad()  { FAIL=$((FAIL+1)); echo "FAIL $1"; }
expect() { # expect "label" "needle" "haystack"
    case "$3" in *"$2"*) ok "$1" ;; *) bad "$1 (wanted: $2)"; echo "$3" | tail -5 ;; esac
}
checksum() {
    $DB -N $DBNAME -e "CHECKSUM TABLE mdl_question, mdl_question_answers, mdl_question_order, mdl_question_order_sub,
        mdl_qtype_ordering_options, mdl_question_attempts, mdl_question_attempt_steps, mdl_question_attempt_step_data,
        mdl_question_hints, mdl_files" | awk '{print $2}' | tr '\n' ' '
}
ids() { $DB -N $DBNAME -e "SELECT GROUP_CONCAT(id ORDER BY id) FROM mdl_question WHERE name IN ($1)"; }

echo "== Reset to snapshot =="
./sync.sh > /dev/null
./reset.sh | tail -1
$DB -e "DROP DATABASE IF EXISTS ${DBNAME}_snap; CREATE DATABASE ${DBNAME}_snap DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
$DB ${DBNAME}_snap < snapshot/$DBNAME.sql
rm -rf moodledata/tool_qtypeorder
Q1=$(ids "'q1_basic_hints'" | cut -d, -f1); Q2=$(ids "'q2_eleven'"); Q7=$(ids "'q7_system_bank'")
Q9=$(ids "'q9_broken_usage'")
# Fault injection: make the database reject q9's options row, i.e. fail midway through converting q9
# (after its items have been written), to prove the whole question is rolled back.
$DB $DBNAME <<SQL
DELIMITER //
CREATE TRIGGER inject_q9_failure BEFORE INSERT ON mdl_qtype_ordering_options FOR EACH ROW
BEGIN
    IF NEW.questionid = $Q9 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Injected test failure';
    END IF;
END//
DELIMITER ;
SQL

echo "== T1 preview =="
OUT=$($CLI 2>&1)
expect "preview finds 11 questions" "11 question(s)" "$OUT"
expect "preview: 10 ready" "ready: 10" "$OUT"
expect "preview: 1 skipped (one item)" "skipped: 1" "$OUT"
expect "preview warns about duplicate text" "identical text" "$OUT"
expect "preview warns about blank item" "blank item" "$OUT"
if ! $DB -N $DBNAME -e "SHOW COLUMNS FROM mdl_qtype_ordering_options LIKE 'shownumcorrect'" | grep -q .; then
    # qtype_ordering releases for Moodle 3.x have no "show number correct" setting.
    expect "preview warns that 'show number correct' will be dropped" "number of correct items" "$OUT"
fi

echo "== T2 targeting =="
OUT=$($CLI -q=$Q1,$Q2 -v 2>&1); expect "--questionid list picks 2" "2 question(s)" "$OUT"
OUT=$($CLI --courseid=2 -v 2>&1); expect "--courseid picks the 10 course/quiz questions (not the system bank one)" "10 question(s)" "$OUT"
case "$OUT" in *q7_system_bank*) bad "--courseid excluded the system-bank question" ;; *) ok "--courseid excluded the system-bank question" ;; esac
SYSCAT=$($DB -N $DBNAME -e "SELECT id FROM mdl_question_categories WHERE name = 'System bank'")
OUT=$($CLI --categoryid=$SYSCAT -v 2>&1); expect "--categoryid picks only the system-bank question" "q7_system_bank" "$OUT"
expect "--categoryid count is 1" "1 question(s)" "$OUT"
OUT=$($CLI -l=3 2>&1); expect "--limit=3" "3 question(s)" "$OUT"

echo "== T3 simulate everything changes nothing =="
BEFORE=$(checksum)
OUT=$($CLI -s 2>&1)
AFTER=$(checksum)
[ "$BEFORE" = "$AFTER" ] && ok "simulate left every table byte-identical" || bad "simulate changed the database"
expect "simulate: 9 simulated" "simulated: 9" "$OUT"
expect "simulate: q9 fails (injected DB error)" "failed: 1" "$OUT"
expect "simulate shows the DB error message" "Injected test failure" "$OUT"
[ ! -d moodledata/tool_qtypeorder ] && ok "simulate wrote no undo backups" || bad "simulate wrote undo backups"

echo "== T4 simulate one question with details =="
OUT=$($CLI -s -q=$Q1 -d 2>&1)
expect "details list old responses" "old:" "$OUT"
expect "details list converted orders" "new:" "$OUT"
expect "details show I don't know" "I don't know" "$OUT"
expect "details show no response for partial-less step" "→" "$OUT"

echo "== T5 migrate one question, check it, undo it =="
OUT=$($CLI -e -q=$Q2 2>&1); expect "migrate q2 alone" "converted: 1" "$OUT"
OUT=$($CLI -c -q=$Q2 2>&1); expect "check q2 passes" "checked: 1" "$OUT"
OUT=$(./php.sh scripts/post_tests.php undo-roundtrip $Q2 2>&1); expect "undo q2 restores it exactly (all tables)" "failed: 0" "$OUT"
echo "$OUT" | grep FAIL
AFTER=$(checksum)
[ "$AFTER" = "$BEFORE" ] && ok "after migrate + undo, every table is byte-identical to the snapshot" || bad "migrate + undo left differences"

echo "== T6 migrate everything =="
OUT=$($CLI -e 2>&1)
expect "migrate: 9 converted" "converted: 9" "$OUT"
expect "migrate: q5 skipped" "skipped: 1" "$OUT"
expect "migrate: q9 failed and was rolled back" "failed: 1" "$OUT"
OUT=$(./php.sh scripts/verify.php 2>&1); expect "verify.php" "failed: 0" "$OUT"
echo "$OUT" | grep -E "FAIL|Passed"

echo "== T7 re-running is harmless =="
BEFORE=$(checksum)
OUT=$($CLI -e 2>&1)
AFTER=$(checksum)
expect "re-run only finds the skipped and failed questions" "2 question(s)" "$OUT"
[ "$BEFORE" = "$AFTER" ] && ok "re-run changed nothing" || bad "re-run changed the database"

echo "== T8 check all converted =="
OUT=$($CLI -c 2>&1); expect "check: 9 OK" "checked: 9" "$OUT"

echo "== T9 undo / redo again after a full run =="
OUT=$(./php.sh scripts/post_tests.php undo-roundtrip $Q7 2>&1); expect "undo q7 restores it exactly" "failed: 0" "$OUT"
OUT=$($CLI -e -q=$Q7 2>&1); expect "q7 converts again after undo" "converted: 1" "$OUT"

echo "== T10 using converted questions =="
OUT=$(./php.sh scripts/post_tests.php new-attempt 2>&1); expect "new attempt on converted quiz scores full marks" "failed: 0" "$OUT"
echo "$OUT" | grep FAIL
OUT=$(./php.sh scripts/post_tests.php regrade-then-undo $Q1 2>&1); expect "regrade works, undo then refused" "failed: 0" "$OUT"
echo "$OUT" | grep -E "FAIL|marks changed"

echo "== T11 backup and restore the course =="
rm -rf backups && mkdir backups
./php.sh moodle/admin/cli/backup.php --courseid=2 --destination="$(cygpath -m "$(pwd)/backups" 2>/dev/null || echo "$(pwd)/backups")" > backups/backup.log 2>&1
MBZ=$(ls backups/*.mbz 2>/dev/null | head -1)
[ -n "$MBZ" ] && ok "course backup created" || { bad "course backup failed"; tail -5 backups/backup.log; }
if [ -n "$MBZ" ]; then
    if [ -f moodle/admin/cli/restore_backup.php ]; then
        ./php.sh moodle/admin/cli/restore_backup.php --file="$(cygpath -w "$(pwd)/$MBZ")" --categoryid=1 > backups/restore.log 2>&1
    else
        ./php.sh scripts/post_tests.php restore-file "$(cygpath -w "$(pwd)/$MBZ")" > backups/restore.log 2>&1
    fi
    NEWCOURSE=$($DB -N $DBNAME -e "SELECT MAX(id) FROM mdl_course")
    [ "$NEWCOURSE" -gt 2 ] && ok "course restored (id $NEWCOURSE)" || { bad "restore failed"; tail -5 backups/restore.log; }
    OUT=$(./php.sh scripts/post_tests.php restored $NEWCOURSE 2>&1); expect "restored attempts render" "failed: 0" "$OUT"
    echo "$OUT" | grep FAIL
fi

echo
echo "TOTAL: passed $PASS, failed $FAIL"
[ $FAIL -eq 0 ]
