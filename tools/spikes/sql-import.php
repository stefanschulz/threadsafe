<?php

/**
 * SPIKE (phase 3, WP 1b and 1d) - throw-away script, not part of the program.
 *
 * Questions:
 *  - Can SQL dumps be imported in plain PHP with a streaming statement splitter, how fast compared with the `mariadb` client,
 *    with how much memory?
 *  - Can the table prefix be changed by rewriting the table name in the statement kinds ThreadSafe generates?
 *
 *   php tools/spikes/sql-import.php dump   <dir> [database]            dump all tables of the database (default: prestashop) into <dir>
 *   php tools/spikes/sql-import.php php    <dir> <database> [pdo|mysqli] [from-prefix to-prefix]
 *   php tools/spikes/sql-import.php client <dir> <database>
 *   php tools/spikes/sql-import.php split  <dir>                       only split (no database), speed and memory of the splitter
 *   php tools/spikes/sql-import.php compare <database-a> <database-b> [from-prefix to-prefix]
 *
 * Runs in the tools container against the `db` service (root password from DB_ROOT_PASSWORD).
 */
declare(strict_types=1);

use ThreadSafe\Backup\Dumper;
use ThreadSafe\Database\Connection;
use ThreadSafe\Database\DatabaseSettings;
use ThreadSafe\Engine\Budget;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$password = getenv('DB_ROOT_PASSWORD') ?: 'prestashop';
$mode = $argv[1] ?? '';
// A string literal with many escapes is one long match: the default limit of 1,000,000 steps is far too low for it.
ini_set('pcre.backtrack_limit', '1000000000');
echo 'pcre.jit=', ini_get('pcre.jit'), ' pcre.backtrack_limit=', ini_get('pcre.backtrack_limit'), "\n";

/**
 * The regular expression for a run of ordinary characters, whole string literals, quoted names and comments - up to the first
 * character of the delimiter, or the end of the data.
 */
function ordinaryRun(string $delimiterFirst): string
{
    $d = preg_quote($delimiterFirst, '~');

    return '~\G(?:[^\'"`#/\-' . $d . ']++|\'(?:[^\'\\\\]++|\\\\.|\'\')*+\'|"(?:[^"\\\\]++|\\\\.|"")*+"|`[^`]*+`|--(?:[ \t\r][^\n]*+)?\n|#[^\n]*+\n|/\*.*?\*/|--(?=[^ \t\r\n])|-(?=[^-])|/(?=[^*]))*+~s';
}

/**
 * Yields the statements of a file one by one: [statement text, byte offset after it].
 *
 * Understands '…' and "…" with backslash escapes and doubled quotes, `…`, -- # and slash-star comments, and the mysql client's
 * DELIMITER command. The file is read in blocks; a token cut by the end of the buffer is completed from the next block.
 *
 * @return Generator<int, array{string, int}>
 */
function statements(string $file, int $block = 1048576): Generator
{
    $handle = fopen($file, 'rb');
    $buffer = '';
    $position = 0;      // scan position in $buffer
    $start = 0;         // start of the statement in $buffer
    $consumed = 0;      // bytes of the file before $buffer
    $delimiter = ';';
    $pattern = ordinaryRun(';');
    $eof = false;

    while (true) {
        // a DELIMITER command at the start of a statement; the line it is on must be in the buffer completely
        $head = $position === $start ? ltrim(substr($buffer, $start, 300)) : "x";
        if ($position === $start && !$eof && !str_contains($head, "\n") && ($head === "" || stripos("DELIMITER", $head) === 0 || stripos($head, "DELIMITER") === 0)) {
            $chunk = (string) fread($handle, $block);
            $eof = $chunk === "" || feof($handle);
            $buffer = substr($buffer, $start) . $chunk;
            $consumed += $start;
            $position = $start = 0;
            continue;
        }
        if ($position === $start && preg_match('~\G\s*DELIMITER[ \t]+(\S+)[^\n]*\n~i', $buffer, $m, 0, $start) === 1) {
            $delimiter = $m[1];
            $pattern = ordinaryRun($delimiter[0]);
            $start = $position = $start + strlen($m[0]);
            continue;
        }

        if (preg_match($pattern, $buffer, $m, 0, $position) === false) {
            throw new RuntimeException('Regular expression failed: ' . preg_last_error_msg());
        }
        $position += strlen($m[0]);
        $length = strlen($delimiter);

        if ($position + $length <= strlen($buffer) && substr($buffer, $position, $length) === $delimiter) {
            $statement = trim(substr($buffer, $start, $position - $start));
            $position += $length;
            $start = $position;
            if ($statement !== '' && preg_match('~^(?:--[^\n]*\n?|#[^\n]*\n?|\s+)*$~', $statement) !== 1) {
                yield [$statement, $consumed + $position];
            }
            continue;
        }
        if ($position < strlen($buffer) && $position + $length <= strlen($buffer) && $buffer[$position] === $delimiter[0]) {
            ++$position;    // the first character of the delimiter, but not the delimiter
            continue;
        }

        // the buffer is used up, or a token (string, comment, delimiter) is cut by its end
        if ($eof) {
            if ($position < strlen($buffer) && !str_ends_with($buffer, "\n") && preg_match('~(?:--[ \t\r]?|#)[^\n]*\z~', substr($buffer, $position)) === 1) {
                $buffer .= "\n";    // a comment on the last line, without a line end
                continue;
            }
            if ($position < strlen($buffer) && in_array($buffer[$position], ['-', '/'], true)) {
                ++$position;    // a lone "-" or "/" at the very end is ordinary text
                continue;
            }
            $rest = trim(substr($buffer, $start));
            if ($position < strlen($buffer)) {
                throw new RuntimeException('Unterminated token near byte ' . ($consumed + $position) . ': ' . substr($buffer, $position, 20));
            }
            if ($rest !== '' && preg_match('~^(?:--[^\n]*\n?|#[^\n]*\n?|\s+)*$~', $rest) !== 1) {
                yield [$rest, $consumed + strlen($buffer)];
            }
            fclose($handle);

            return;
        }
        $chunk = (string) fread($handle, $block);
        $eof = $chunk === '' || feof($handle);
        $buffer = substr($buffer, $start) . $chunk;
        $consumed += $start;
        $position -= $start;
        $start = 0;
    }
}

function rewritePrefix(string $statement, string $from, string $to): string
{
    // only the statement kinds ThreadSafe generates, only the table name right after the keywords
    $name = '`' . preg_quote($from, '~');
    return (string) preg_replace(
        [
            '~^(DROP TABLE IF EXISTS )' . $name . '~',
            '~^(CREATE TABLE )' . $name . '~',
            '~^(INSERT INTO )' . $name . '~',
            '~^(UPDATE )' . $name . '~',
        ],
        ['$1`' . $to, '$1`' . $to, '$1`' . $to, '$1`' . $to],
        $statement,
        1,
    );
}

function dumpFiles(string $directory): array
{
    $files = glob($directory . '/*.sql') ?: [];
    sort($files);

    return $files;
}

function peak(): string
{
    return sprintf('%.0f MB', memory_get_peak_usage(true) / 1048576);
}

if ($mode === 'dump') {
    $directory = $argv[2] ?? '';
    $database = $argv[3] ?? 'prestashop';
    @mkdir($directory, 0777, true);
    $db = Connection::open(new DatabaseSettings('db', 3306, $database, 'root', $password, '', 'pdo'));
    $dumper = new Dumper($db);
    $state = $dumper->initialState(['directory' => $directory, 'snapshot' => true]);
    $t = microtime(true);
    $outcome = $dumper->tick($state, Budget::unlimited());
    $bytes = array_sum(array_map('filesize', dumpFiles($directory)));
    printf("dumped %d tables, %d rows, %.0f MB in %.1fs, peak %s\n", count($outcome->state['done']), $outcome->state['rows'], $bytes / 1048576, microtime(true) - $t, peak());
    exit(0);
}

if ($mode === 'split') {
    $t = microtime(true);
    $count = 0;
    $bytes = 0;
    $longest = 0;
    foreach (dumpFiles($argv[2] ?? '') as $file) {
        foreach (statements($file) as [$statement]) {
            ++$count;
            $longest = max($longest, strlen($statement));
        }
        $bytes += filesize($file);
    }
    printf("split %d statements from %.0f MB in %.2fs (%.0f MB/s), longest %.1f MB, peak %s\n", $count, $bytes / 1048576, microtime(true) - $t, $bytes / 1048576 / (microtime(true) - $t), $longest / 1048576, peak());
    exit(0);
}

if ($mode === 'php') {
    [$directory, $database] = [$argv[2] ?? '', $argv[3] ?? ''];
    $interface = $argv[4] ?? 'pdo';
    [$from, $to] = [$argv[5] ?? null, $argv[6] ?? null];
    $admin = new PDO('mysql:host=db;charset=utf8mb4', 'root', $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $admin->exec("DROP DATABASE IF EXISTS `$database`");
    $admin->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4");
    if ($interface === 'mysqli') {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $link = new mysqli('db', 'root', $password, $database);
        $link->set_charset('utf8mb4');
        $run = static function (string $sql) use ($link): void {
            $link->real_query($sql);
        };
    } else {
        $pdo = new PDO("mysql:host=db;dbname=$database;charset=utf8mb4", 'root', $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $run = static function (string $sql) use ($pdo): void {
            $pdo->exec($sql);
        };
    }
    $t = microtime(true);
    $count = 0;
    $bytes = 0;
    foreach (dumpFiles($directory) as $file) {
        foreach (statements($file) as [$statement]) {
            if ($from !== null && $to !== null) {
                $statement = rewritePrefix($statement, $from, $to);
            }
            $run($statement);
            ++$count;
        }
        $bytes += filesize($file);
    }
    printf("%s: executed %d statements (%.0f MB) in %.1fs (%.0f MB/s), peak %s\n", $interface, $count, $bytes / 1048576, microtime(true) - $t, $bytes / 1048576 / (microtime(true) - $t), peak());
    exit(0);
}

if ($mode === 'client') {
    [$directory, $database] = [$argv[2] ?? '', $argv[3] ?? ''];
    $admin = new PDO('mysql:host=db;charset=utf8mb4', 'root', $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $admin->exec("DROP DATABASE IF EXISTS `$database`");
    $admin->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4");
    $t = microtime(true);
    $bytes = 0;
    foreach (dumpFiles($directory) as $file) {
        $process = proc_open(['mariadb', '--protocol=tcp', '-h', 'db', '-u', 'root', '--default-character-set=utf8mb4', $database], [0 => ['file', $file, 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['MYSQL_PWD' => $password, 'PATH' => (string) getenv('PATH')]);
        stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new RuntimeException($error);
        }
        $bytes += filesize($file);
    }
    printf("mariadb client: %.0f MB in %.1fs (%.0f MB/s)\n", $bytes / 1048576, microtime(true) - $t, $bytes / 1048576 / (microtime(true) - $t));
    exit(0);
}

if ($mode === 'compare') {
    [$a, $b] = [$argv[2] ?? '', $argv[3] ?? ''];
    [$from, $to] = [$argv[4] ?? null, $argv[5] ?? null];
    $pdo = new PDO('mysql:host=db;charset=utf8mb4', 'root', $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $tables = static fn (string $database): array => $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = '$database' AND TABLE_TYPE = 'BASE TABLE' ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN);
    $left = $tables($a);
    $right = $tables($b);
    $expected = $from === null ? $left : array_map(static fn (string $t): string => str_starts_with($t, $from) ? $to . substr($t, strlen($from)) : $t, $left);
    printf("tables: %d / %d, same names%s: %s\n", count($left), count($right), $from === null ? '' : " after $from -> $to", $expected === $right ? 'yes' : 'NO');
    $different = [];
    foreach ($left as $i => $table) {
        $other = $right[array_search($expected[$i], $right, true)] ?? null;
        if ($other === null) {
            $different[] = "$table (missing)";
            continue;
        }
        $x = $pdo->query("CHECKSUM TABLE `$a`.`$table`")->fetch(PDO::FETCH_NUM)[1];
        $y = $pdo->query("CHECKSUM TABLE `$b`.`$other`")->fetch(PDO::FETCH_NUM)[1];
        if ($x !== $y) {
            $different[] = $table;
        }
    }
    echo 'tables with another checksum: ', $different === [] ? 'none' : implode(', ', $different), "\n";
    exit(0);
}

if ($mode === 'selftest') {
    $cases = [
        'two statements' => ["SELECT 1;\nSELECT 2;\n", ['SELECT 1', 'SELECT 2']],
        'semicolon in string' => ["INSERT INTO t VALUES ('a;b', \"c;d\");\nSELECT 1;", ["INSERT INTO t VALUES ('a;b', \"c;d\")", 'SELECT 1']],
        'escaped quote' => ["INSERT INTO t VALUES ('it\\'s; here');\nX;", ["INSERT INTO t VALUES ('it\\'s; here')", 'X']],
        'doubled quote' => ["INSERT INTO t VALUES ('it''s; here');\nX;", ["INSERT INTO t VALUES ('it''s; here')", 'X']],
        'backslash before end quote' => ["INSERT INTO t VALUES ('a\\\\');\nX;", ["INSERT INTO t VALUES ('a\\\\')", 'X']],
        'backtick name with semicolon' => ["CREATE TABLE `a;b` (x int);\nX;", ['CREATE TABLE `a;b` (x int)', 'X']],
        'comments' => ["-- header; with semicolon\n# another; one\n/* block; */ SELECT 1; -- trailing; comment\nSELECT 2;", ['-- header; with semicolon' . "\n" . '# another; one' . "\n" . '/* block; */ SELECT 1', '-- trailing; comment' . "\n" . 'SELECT 2']],
        'executable comment' => ["/*!40101 SET NAMES utf8 */;\nX;", ['/*!40101 SET NAMES utf8 */', 'X']],
        'no final delimiter' => ["SELECT 1;\nSELECT 2", ['SELECT 1', 'SELECT 2']],
        'delimiter command' => ["SELECT 1;\nDELIMITER ;;\nCREATE TRIGGER t BEFORE INSERT ON x FOR EACH ROW BEGIN SET a = 1; SET b = ';;'; END ;;\nDELIMITER ;\nSELECT 2;\n", ['SELECT 1', "CREATE TRIGGER t BEFORE INSERT ON x FOR EACH ROW BEGIN SET a = 1; SET b = ';;'; END", 'SELECT 2']],
        'dollar delimiter' => ["DELIMITER \$\$\nCREATE PROCEDURE p() BEGIN SELECT 1; END\$\$\nDELIMITER ;\n", ['CREATE PROCEDURE p() BEGIN SELECT 1; END']],
        'minus is not a comment' => ["SELECT 5-3, 4--1, 6 - -2;\nX;", ['SELECT 5-3, 4--1, 6 - -2', 'X']],
    ];
    $failed = 0;
    foreach ($cases as $name => [$text, $expected]) {
        foreach ([1048576, 7, 1] as $block) {   // also with blocks that cut every token
            file_put_contents('/tmp/case.sql', $text);
            $got = array_map(static fn (array $r): string => $r[0], iterator_to_array(statements('/tmp/case.sql', $block), false));
            if ($got !== $expected) {
                ++$failed;
                echo "FAIL $name (block $block)\n  expected: ", json_encode($expected), "\n  got:      ", json_encode($got), "\n";
            }
        }
    }
    // a statement with a 5 MB string
    $big = "INSERT INTO t VALUES ('" . str_repeat("abc'';\\'x", 600000) . "');\nSELECT 1;";
    file_put_contents('/tmp/case.sql', $big);
    $t = microtime(true);
    $got = iterator_to_array(statements('/tmp/case.sql', 65536), false);
    printf("%s big statement of %.1f MB: %d statements in %.2fs\n", count($got) === 2 && strlen($got[0][0]) === strlen($big) - strlen(";\nSELECT 1;") ? 'ok  ' : 'FAIL', strlen($big) / 1048576, count($got), microtime(true) - $t);
    echo $failed === 0 ? "all cases ok\n" : "$failed failures\n";
    exit($failed === 0 ? 0 : 1);
}

fwrite(STDERR, "unknown mode\n");
exit(1);
