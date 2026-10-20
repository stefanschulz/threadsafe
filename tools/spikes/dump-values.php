<?php

/**
 * SPIKE (phase 2, WP 1b-d) - throw-away script, not part of the program.
 *
 * Questions:
 *  types   Can the values of every column type be dumped as SQL text and imported again without loss, with both PHP
 *          interfaces, on MariaDB and MySQL? Which way of fetching floats keeps them exact?
 *  memory  What does a 40 MB cell cost in memory when it is fetched whole, and when it is streamed in pieces?
 *  nopk    Which tables of the test shop have no primary key, and what could identify their rows?
 *
 *   php tools/spikes/dump-values.php types  [pdo|mysqli]
 *   php tools/spikes/dump-values.php memory [pdo|mysqli] [megabytes]
 *   php tools/spikes/dump-values.php nopk
 *
 * Runs in the tools container against the `db` service (root password from DB_ROOT_PASSWORD).
 */
declare(strict_types=1);

use ThreadSafe\Database\Connection;
use ThreadSafe\Database\DatabaseSettings;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$mode = $argv[1] ?? '';
$driver = $argv[2] ?? 'pdo';
$password = getenv('DB_ROOT_PASSWORD') ?: 'prestashop';

function admin(string $password, ?string $database = null): PDO
{
    return new PDO('mysql:host=db;charset=utf8mb4' . ($database !== null ? ";dbname=$database" : ''), 'root', $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

function open(string $password, string $database, string $driver): Connection
{
    return Connection::open(new DatabaseSettings('db', 3306, $database, 'root', $password, '', $driver));
}

if ($mode === 'nopk') {
    $db = open($password, 'prestashop', 'pdo');
    $rows = $db->select("SELECT t.TABLE_NAME AS t, t.TABLE_ROWS AS r FROM information_schema.TABLES t WHERE t.TABLE_SCHEMA = DATABASE() AND t.TABLE_TYPE = 'BASE TABLE'
        AND NOT EXISTS (SELECT 1 FROM information_schema.TABLE_CONSTRAINTS c WHERE c.TABLE_SCHEMA = t.TABLE_SCHEMA AND c.TABLE_NAME = t.TABLE_NAME AND c.CONSTRAINT_TYPE = 'PRIMARY KEY') ORDER BY t.TABLE_NAME");
    foreach ($rows as $row) {
        $unique = $db->select("SELECT INDEX_NAME AS i, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS c FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND NON_UNIQUE = 0 GROUP BY INDEX_NAME", [$row['t']]);
        $duplicates = (int) $db->scalar('SELECT COUNT(*) FROM (SELECT 1 FROM `' . $row['t'] . '` GROUP BY ' . implode(',', array_map(static fn (array $c): string => '`' . $c['COLUMN_NAME'] . '`', $db->select('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$row['t']]))) . ' HAVING COUNT(*) > 1) d');
        printf("%-34s rows~%-6s unique index: %-28s duplicate rows: %d\n", $row['t'], $row['r'], $unique === [] ? 'none' : $unique[0]['c'], $duplicates);
    }
    exit(0);
}

$database = 'spike_' . bin2hex(random_bytes(3));
$admin = admin($password);
$admin->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4");
register_shutdown_function(static function () use ($password, $database): void {
    admin($password)->exec("DROP DATABASE IF EXISTS `$database`");
});

if ($mode === 'memory') {
    $megabytes = (int) ($argv[3] ?? 40);
    $a = admin($password, $database);
    $a->exec('CREATE TABLE big (id INT PRIMARY KEY, data LONGBLOB) ENGINE=InnoDB');
    $max = (int) $a->query('SELECT @@max_allowed_packet')->fetchColumn();
    $raised = ($megabytes * 2 + 8) * 1048576;
    $a->exec('SET GLOBAL max_allowed_packet = ' . $raised);
    register_shutdown_function(static function () use ($password, $max): void {
        admin($password)->exec('SET GLOBAL max_allowed_packet = ' . $max);
    });
    $a = admin($password, $database);      // a new session picks up the global value
    $piece = random_bytes(1048576);
    $statement = $a->prepare('INSERT INTO big VALUES (1, ?)');
    $statement->bindValue(1, str_repeat($piece, $megabytes), PDO::PARAM_LOB);
    $statement->execute();
    unset($piece, $statement);
    $mode2 = $argv[4] ?? 'whole';
    if (($argv[5] ?? 'raised') === 'default') {
        $a->exec('SET GLOBAL max_allowed_packet = ' . $max);
    }
    echo 'driver=', $driver, ' cell=', $megabytes, ' MB memory_limit=', ini_get('memory_limit'), ' max_allowed_packet=', ($argv[5] ?? 'raised') === 'default' ? $max / 1048576 : $raised / 1048576, ' MB', PHP_EOL;

    $db = open($password, $database, $driver);
    unset($a);
    gc_collect_cycles();
    memory_reset_peak_usage();
    $base = memory_get_usage();
    $mode2 = $argv[4] ?? 'whole';
    try {
        if ($mode2 === 'whole') {
            $row = $db->select('SELECT id, HEX(data) AS data FROM big')[0];
            if ($row['data'] === null) {
                throw new RuntimeException('HEX(data) came back NULL without an error (result larger than max_allowed_packet)');
            }
            $sql = 'INSERT INTO big VALUES (' . $row['id'] . ',0x' . $row['data'] . ');';
            $length = strlen($sql);
        } else {
            $length = (int) $db->scalar('SELECT LENGTH(data) FROM big');
            $out = fopen('php://temp/maxmemory:1048576', 'w+b');
            fwrite($out, 'INSERT INTO big VALUES (1,0x');
            for ($offset = 0; $offset < $length; $offset += 2097152) {
                fwrite($out, (string) $db->scalar('SELECT HEX(SUBSTRING(data, ?, 2097152)) FROM big', [$offset + 1]));
            }
            fwrite($out, ');');
            $length = ftell($out);
        }
        printf("  %-9s statement %d MB, peak memory %.1f MB\n", $mode2, $length / 1048576, (memory_get_peak_usage() - $base) / 1048576);
    } catch (Throwable $e) {
        printf("  %-9s FAILED: %s\n", $mode2, substr($e->getMessage(), 0, 110));
    }
    exit(0);
}

// ================================================================ types =======================================
$a = admin($password, $database);
$a->exec("SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION', time_zone = '+00:00'");
$ddl = <<<'SQL'
CREATE TABLE %s (
 id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 c_tiny TINYINT NULL, c_utiny TINYINT UNSIGNED NULL, c_big BIGINT NULL, c_ubig BIGINT UNSIGNED NULL,
 c_dec DECIMAL(65,30) NULL, c_dec2 DECIMAL(10,2) NULL,
 c_float FLOAT NULL, c_double DOUBLE NULL,
 c_bit BIT(64) NULL, c_bit1 BIT(1) NULL,
 c_char CHAR(10) NULL, c_varchar VARCHAR(100) NULL, c_text LONGTEXT NULL,
 c_binary BINARY(16) NULL, c_varbinary VARBINARY(64) NULL, c_blob LONGBLOB NULL,
 c_enum ENUM('a','b','ü') NULL, c_set SET('x','y','z') NULL,
 c_date DATE NULL, c_datetime DATETIME(6) NULL, c_ts TIMESTAMP(6) NULL, c_time TIME(3) NULL, c_year YEAR NULL,
 c_json JSON NULL, c_point POINT NULL,
 c_gen INT AS (c_tiny * 2) VIRTUAL, c_gen2 INT AS (c_tiny * 3) STORED
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL;
$a->exec(sprintf($ddl, 'src'));
$all256 = '0x' . implode('', array_map(static fn (int $i): string => sprintf('%02X', $i), range(0, 255)));
$a->exec(<<<SQL
INSERT INTO src (c_tiny, c_utiny, c_big, c_ubig, c_dec, c_dec2, c_float, c_double, c_bit, c_bit1, c_char, c_varchar, c_text, c_binary, c_varbinary, c_blob, c_enum, c_set, c_date, c_datetime, c_ts, c_time, c_year, c_json, c_point) VALUES
 (-128, 255, -9223372036854775808, 18446744073709551615, '12345678901234567890123456789012345.123456789012345678901234567890', '-0.01', 3.4028235e38, 1.7976931348623157e308,
  b'1010101010101010101010101010101010101010101010101010101010101011', b'1', 'ab', '😀 Größe ü ∑', CONCAT('a', CHAR(0), 'b', CHAR(92), CHAR(39), CHAR(34), CHAR(10), CHAR(13), CHAR(9), CHAR(26), '%_'),
  UNHEX('00112233445566778899AABBCCDDEE00'), '', $all256, 'ü', 'x,z', '0000-00-00', '2026-10-20 12:34:56.123456', '2026-10-20 12:34:56.654321', '838:59:59.000', 2026,
  '{"a":[1,2,{"b":null}],"ü":"😀","n":1.50}', ST_GeomFromText('POINT(1.5 -2.25)')),
 (NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
 (7, 0, 0, 0, '0.000000000000000000000000000001', '3.14', 0.1, 0.1, b'0', b'0', 'x', '', '', UNHEX('00000000000000000000000000000000'), UNHEX('00'), UNHEX('0000'), 'a', '', '1000-01-01', '1000-01-01 00:00:00.000000', '1970-01-01 00:00:01.000000', '-838:59:59.000', 1901, '[]', ST_GeomFromText('POINT(0 0)')),
 (1, 1, 1, 1, '1', '1', 1.1754944e-38, 4.9e-324, b'1', b'1', 'z', 'last', 'end', UNHEX('FF'), UNHEX('FF'), UNHEX('FF'), 'b', 'y', '9999-12-31', '9999-12-31 23:59:59.999999', '2038-01-19 03:14:07.999999', '00:00:00.001', 2155, '{}', ST_GeomFromText('POINT(-180 90)')),
 (2, 2, 2, 2, '2', '2', 0.30000001192092896, 0.3333333333333333, b'11', b'1', 'dbl', 'third', '1/3', UNHEX('0A'), UNHEX('0B'), UNHEX('0C'), 'a', 'x,y,z', '2000-02-29', '2000-02-29 00:00:00.000001', '2000-02-29 12:00:00.000001', '12:34:56.789', 2000, '{"k":"v"}', ST_GeomFromText('POINT(10 20)'))
SQL);

/** @return array{list<array{name: string, binary: bool, float: bool}>, string} columns to dump (generated ones left out) and the CREATE statement */
function describe(Connection $db, string $table): array
{
    $create = (string) $db->select('SHOW CREATE TABLE `' . $table . '`')[0]['Create Table'];
    $columns = [];
    foreach ($db->select('SELECT COLUMN_NAME AS n, DATA_TYPE AS t, EXTRA AS e FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$table]) as $c) {
        $extra = strtolower((string) $c['e']);
        if (str_contains($extra, 'generated')) {
            continue;
        }
        $type = strtolower((string) $c['t']);
        $columns[] = [
            'name' => (string) $c['n'],
            'binary' => in_array($type, ['binary', 'varbinary', 'tinyblob', 'blob', 'mediumblob', 'longblob', 'bit', 'geometry', 'point', 'linestring', 'polygon', 'multipoint', 'multilinestring', 'multipolygon', 'geometrycollection'], true),
            'float' => in_array($type, ['float', 'double'], true),
        ];
    }

    return [$columns, $create];
}

/** @param 'raw'|'export'|'cast'|'widen' $floats how floating point values are fetched and written */
function dump(Connection $db, string $table, string $floats): string
{
    [$columns, $create] = describe($db, $table);
    $select = implode(', ', array_map(static function (array $c) use ($floats): string {
        $q = '`' . $c['name'] . '`';
        if ($c['binary']) {
            return "HEX($q) AS $q";
        }
        if ($c['float'] && $floats === 'cast') {
            return "CAST($q AS CHAR) AS $q";
        }
        if ($c['float'] && $floats === 'widen') {
            return "($q + 0e0) AS $q";
        }

        return $q;
    }, $columns));
    $names = implode(', ', array_map(static fn (array $c): string => '`' . $c['name'] . '`', $columns));
    $sql = $create . ";\n";
    foreach ($db->select("SELECT $select FROM `$table` ORDER BY id") as $row) {
        $values = [];
        foreach ($columns as $c) {
            $v = $row[$c['name']];
            if ($v === null) {
                $values[] = 'NULL';
            } elseif ($c['binary']) {
                $values[] = $v === '' ? "''" : '0x' . $v;
            } elseif (is_int($v)) {
                $values[] = (string) $v;
            } elseif (is_float($v)) {
                $values[] = in_array($floats, ['export', 'widen'], true) ? var_export($v, true) : (string) $v;
            } else {
                $values[] = $db->quotedString((string) $v);
            }
        }
        $sql .= "INSERT INTO `$table` ($names) VALUES (" . implode(',', $values) . ");\n";
    }

    return $sql;
}

function comparable(Connection $db, string $table): array
{
    [$columns] = describe($db, $table);
    $select = implode(', ', array_map(static fn (array $c): string => $c['binary'] ? 'HEX(`' . $c['name'] . '`)' : 'CAST(`' . $c['name'] . '` AS CHAR)', $columns));

    return $db->select("SELECT $select FROM `$table` ORDER BY id");
}

echo "driver=$driver server=", $a->query('SELECT VERSION()')->fetchColumn(), "\n";
$native = open($password, $database, $driver);
$native->execute("SET SESSION time_zone = '+00:00'");
$row = $native->select('SELECT c_tiny, c_big, c_ubig, c_dec, c_float, c_double, c_bit, c_ts, c_json FROM src WHERE id = 1')[0];
echo '  fetched PHP types: ', implode(', ', array_map(static fn (string $k, mixed $v): string => $k . '=' . get_debug_type($v), array_keys($row), $row)), "\n";

foreach (['raw' => 'float via (string)', 'export' => 'float via var_export', 'cast' => 'float via SQL CAST AS CHAR', 'widen' => 'float widened (c + 0e0)'] as $variant => $label) {
    $script = dump($native, 'src', $variant);
    $a->exec('DROP TABLE IF EXISTS copy');
    $parts = explode(";\n", rtrim($script, "\n"));
    $create = array_shift($parts);
    $a->exec('SET SESSION sql_mode = \'NO_ENGINE_SUBSTITUTION\', time_zone = \'+00:00\'');
    $a->exec(str_replace('CREATE TABLE `src`', 'CREATE TABLE `copy`', $create));
    $errors = [];
    foreach ($parts as $statement) {
        try {
            $a->exec(str_replace('INSERT INTO `src`', 'INSERT INTO `copy`', $statement));
        } catch (PDOException $e) {
            $errors[] = substr($e->getMessage(), 0, 100);
        }
    }
    $diff = [];
    $left = comparable($native, 'src');
    $right = comparable($native, 'copy');
    foreach ($left as $i => $l) {
        foreach ($l as $k => $v) {
            if (!array_key_exists($k, $right[$i] ?? []) || $right[$i][$k] !== $v) {
                $diff[] = 'row ' . ($i + 1) . ' ' . $k . ': ' . var_export($v, true) . ' -> ' . var_export($right[$i][$k] ?? '<missing>', true);
            }
        }
    }
    $checks = [$a->query('CHECKSUM TABLE src')->fetch(PDO::FETCH_NUM)[1], $a->query('CHECKSUM TABLE `copy`')->fetch(PDO::FETCH_NUM)[1]];
    foreach ($native->select("SELECT COLUMN_NAME AS n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'src' ORDER BY ORDINAL_POSITION") as $col) {
        $q = '`' . $col['n'] . '`';
        $bad = $native->select("SELECT s.id FROM src s JOIN `copy` c USING (id) WHERE NOT (s.$q <=> c.$q)");
        if ($bad !== []) {
            $diff[] = 'exact comparison (<=>) differs: ' . $col['n'] . ' in rows ' . implode(',', array_column($bad, 'id'));
        }
    }
    printf("  %-28s import errors: %d, value differences: %d, CHECKSUM TABLE %s\n", $label, count($errors), count($diff), $checks[0] === $checks[1] ? 'equal' : 'DIFFERENT');
    foreach (array_slice($errors, 0, 3) as $e) {
        echo "      error: $e\n";
    }
    foreach (array_slice($diff, 0, 8) as $d) {
        echo "      diff:  $d\n";
    }
}

// is CHECKSUM TABLE a fair oracle for copies? A copy made by the server itself must equal the source
[$columns] = describe($native, 'src');
$list = implode(', ', array_map(static fn (array $c): string => '`' . $c['name'] . '`', $columns));
$a->exec('DROP TABLE IF EXISTS copy2');
$a->exec(sprintf($ddl, 'copy2'));
$a->exec("INSERT INTO copy2 ($list) SELECT $list FROM src");
$ck = static fn (string $t): string => (string) $a->query("CHECKSUM TABLE `$t`")->fetch(PDO::FETCH_NUM)[1];
echo '  CHECKSUM TABLE src=', $ck('src'), ' server-side copy=', $ck('copy2'), ' dump-import copy=', $ck('copy'), "
";
foreach ($columns as $c) {
    $q = '`' . $c['name'] . '`';
    $h = static fn (string $t): string => (string) $a->query("SELECT MD5(GROUP_CONCAT(IFNULL(HEX($q), '~') ORDER BY id)) FROM `$t`")->fetchColumn();
    if ($h('src') !== $h('copy2') || $h('src') !== $h('copy')) {
        echo '    column ', $c['name'], ' differs by HEX(): src ', substr($h('src'), 0, 8), ' copy2 ', substr($h('copy2'), 0, 8), ' copy ', substr($h('copy'), 0, 8), "
";
    }
}

foreach ($native->select("SELECT COLUMN_NAME AS n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'src' ORDER BY ORDINAL_POSITION") as $c) {
    $q = '`' . $c['n'] . '`';
    $bad = $native->select("SELECT s.id FROM src s JOIN `copy` c USING (id) WHERE NOT (s.$q <=> c.$q)");
    if ($bad !== []) {
        echo '    <=> differs: ', $c['n'], ' in rows ', implode(',', array_column($bad, 'id')), "
";
    }
}
// the same dump read under another session time zone
$native->execute("SET SESSION time_zone = '+05:00'");
$shifted = $native->select('SELECT CAST(c_ts AS CHAR) AS t FROM src WHERE id = 1')[0]['t'];
echo "  TIMESTAMP read under +05:00: $shifted (stored as 12:34:56.654321 UTC) -> the dump connection must pin the time zone\n";
