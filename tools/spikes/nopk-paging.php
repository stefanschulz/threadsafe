<?php

/**
 * SPIKE (phase 2, WP 1c) - throw-away script. How do tables without a primary key behave when they are read in pages?
 *
 *   php tools/spikes/nopk-paging.php [rows]
 */
declare(strict_types=1);

use ThreadSafe\Database\Connection;
use ThreadSafe\Database\DatabaseSettings;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$rows = (int) ($argv[1] ?? 300000);
$password = getenv('DB_ROOT_PASSWORD') ?: 'prestashop';
$database = 'spike_' . bin2hex(random_bytes(3));
$admin = new PDO('mysql:host=db;charset=utf8mb4', 'root', $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$admin->exec("CREATE DATABASE `$database`");
register_shutdown_function(static fn () => $admin->exec("DROP DATABASE IF EXISTS `$database`"));
$admin->exec("USE `$database`");
$admin->exec('CREATE TABLE nopk (a INT, b VARCHAR(40), c DECIMAL(10,2), d DATETIME, e TEXT) ENGINE=InnoDB');
$digits = '(SELECT 0 n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9)';
$admin->exec("INSERT INTO nopk SELECT MOD(n, 1000), CONCAT('row ', MOD(n, 777)), MOD(n, 97) / 3, '2026-01-01 00:00:00' + INTERVAL MOD(n, 5000) SECOND, REPEAT('x', 30) FROM (SELECT a.n + b.n*10 + c.n*100 + d.n*1000 + e.n*10000 + f.n*100000 AS n FROM $digits a, $digits b, $digits c, $digits d, $digits e, $digits f) s WHERE n < $rows");
$admin->exec('INSERT INTO nopk SELECT * FROM nopk LIMIT 1000');   // exact duplicates, as a table without a key may have
$db = Connection::open(new DatabaseSettings('db', 3306, $database, 'root', $password, '', 'pdo'));
$total = (int) $db->scalar('SELECT COUNT(*) FROM nopk');
$duplicates = (int) $db->scalar('SELECT COUNT(*) FROM (SELECT 1 FROM nopk GROUP BY a, b, c, d, e HAVING COUNT(*) > 1) x');
echo "rows=$total groups of identical rows=$duplicates\n";

$t = microtime(true);
$count = 0;
$pass = $db->select('SELECT * FROM nopk');            // one pass (buffered here; unbuffered would use less memory)
$count = count($pass);
unset($pass);
printf("one pass, no ORDER BY:                 %6.2fs for %d rows\n", microtime(true) - $t, $count);

$pageSize = 5000;
foreach ([0, $total / 2, $total - $pageSize] as $offset) {
    $t = microtime(true);
    $db->select('SELECT * FROM nopk ORDER BY a, b, c, d, e LIMIT ' . $pageSize . ' OFFSET ' . (int) $offset);
    printf("page at offset %-7d ORDER BY all columns: %.2fs  (x%d pages = %.0fs)\n", $offset, microtime(true) - $t, $total / $pageSize, (microtime(true) - $t) * $total / $pageSize);
}

// is OFFSET paging with ORDER BY all columns stable and complete, also with identical rows?
$seen = [];
for ($offset = 0; $offset < $total; $offset += 50000) {
    foreach ($db->select('SELECT * FROM nopk ORDER BY a, b, c, d, e LIMIT 50000 OFFSET ' . $offset) as $r) {
        $key = md5(json_encode($r));
        $seen[$key] = ($seen[$key] ?? 0) + 1;
    }
}
$direct = [];
foreach ($db->select('SELECT * FROM nopk') as $r) {
    $key = md5(json_encode($r));
    $direct[$key] = ($direct[$key] ?? 0) + 1;
}
ksort($seen);
ksort($direct);
$missing = array_sum(array_map(static fn (string $k): int => max(0, $direct[$k] - ($seen[$k] ?? 0)), array_keys($direct)));
$extra = array_sum(array_map(static fn (string $k): int => max(0, $seen[$k] - ($direct[$k] ?? 0)), array_keys($seen)));
echo 'OFFSET paging returns the same multiset of rows as one pass: ', $seen === $direct ? 'yes' : "NO (rows missing: $missing, too many: $extra, paged " . array_sum($seen) . ' vs ' . array_sum($direct) . ' in one pass)', PHP_EOL;
