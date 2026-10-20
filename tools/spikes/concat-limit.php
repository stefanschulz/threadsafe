<?php
/**
 * SPIKE (phase 2, WP 5) - throw-away script. Can a cell grow beyond max_allowed_packet by UPDATE ... SET c = CONCAT(c, piece)?
 *   php tools/spikes/concat-limit.php [pieceMegabytes] [pieces]
 */
declare(strict_types=1);

$password = getenv('DB_ROOT_PASSWORD') ?: 'prestashop';
$piece = (int) ($argv[1] ?? 4);
$pieces = (int) ($argv[2] ?? 40);
$database = 'spike_' . bin2hex(random_bytes(3));
$pdo = new PDO('mysql:host=db;charset=utf8mb4', 'root', $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE DATABASE `$database`");
register_shutdown_function(static fn () => $pdo->exec("DROP DATABASE IF EXISTS `$database`"));
$pdo->exec("USE `$database`");
$pdo->exec('CREATE TABLE t (id INT PRIMARY KEY, data LONGBLOB NULL) ENGINE=InnoDB');
echo $pdo->query('SELECT VERSION()')->fetchColumn(), ' max_allowed_packet=', (int) $pdo->query('SELECT @@max_allowed_packet')->fetchColumn() / 1048576, " MB\n";
$bytes = str_repeat('x', $piece * 1048576);
$pdo->exec('INSERT INTO t VALUES (1, NULL)');
$pdo->exec("UPDATE t SET data = ''");
for ($i = 1; $i <= $pieces; ++$i) {
    $statement = $pdo->prepare('UPDATE t SET data = CONCAT(data, ?) WHERE id = 1');
    $statement->bindValue(1, $bytes, PDO::PARAM_LOB);
    try {
        $statement->execute();
    } catch (PDOException $e) {
        echo "piece $i: ", substr($e->getMessage(), 0, 120), "\n";
        break;
    }
    $length = $pdo->query('SELECT LENGTH(data) FROM t')->fetchColumn();
    if ($length === null || (int) $length !== $i * $piece * 1048576) {
        echo "piece $i: cell is now ", var_export($length, true), " bytes (expected ", $i * $piece, " MB)\n";
        break;
    }
}
echo 'last good state: ', var_export($pdo->query('SELECT IFNULL(LENGTH(data), -1) / 1048576 FROM t')->fetchColumn(), true), " MB\n";
