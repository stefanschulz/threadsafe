<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Support;

use PDO;

/**
 * A database with everything a dump has to survive: every column type, composite and binary keys, tables without a key, cells
 * above a small packet limit, JSON, invisible and generated columns, a view on a view, a trigger and a stored function.
 */
final class TortureDatabase
{
    /** Creates the database `$database` (it must not exist) and fills it. */
    public static function create(PDO $admin, string $database): void
    {
        $admin->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4");
        $admin->exec("USE `{$database}`");
        $admin->exec("SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION', time_zone = '+00:00'");
        $engine = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';

        $admin->exec(<<<SQL
CREATE TABLE types (
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
 c_def DATETIME NULL DEFAULT CURRENT_TIMESTAMP, c_upd TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
 c_inv INT NULL INVISIBLE,
 c_gen INT AS (c_tiny * 2) VIRTUAL, c_gen2 INT AS (c_tiny * 3) STORED
) $engine
SQL);
        $all256 = '0x' . implode('', array_map(static fn (int $i): string => sprintf('%02X', $i), range(0, 255)));
        $admin->exec(<<<SQL
INSERT INTO types (c_tiny, c_utiny, c_big, c_ubig, c_dec, c_dec2, c_float, c_double, c_bit, c_bit1, c_char, c_varchar, c_text, c_binary, c_varbinary, c_blob, c_enum, c_set, c_date, c_datetime, c_ts, c_time, c_year, c_json, c_point, c_def, c_upd, c_inv) VALUES
 (-128, 255, -9223372036854775808, 18446744073709551615, '12345678901234567890123456789012345.123456789012345678901234567890', '-0.01', 3.4028235e38, 1.7976931348623157e308,
  b'1010101010101010101010101010101010101010101010101010101010101011', b'1', 'ab', '😀 Größe ü ∑', CONCAT('a', CHAR(0), 'b', CHAR(92), CHAR(39), CHAR(34), CHAR(10), CHAR(13), CHAR(9), CHAR(26), '%_'),
  UNHEX('00112233445566778899AABBCCDDEE00'), '', $all256, 'ü', 'x,z', '0000-00-00', '2026-10-20 12:34:56.123456', '2026-10-20 12:34:56.654321', '838:59:59.000', 2026,
  '{"a":[1,2,{"b":null}],"ü":"😀","n":1.50}', ST_GeomFromText('POINT(1.5 -2.25)'), '2026-01-02 03:04:05', '2026-02-03 04:05:06', 77),
 (NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
 (7, 0, 0, 0, '0.000000000000000000000000000001', '3.14', 0.1, 0.1, b'0', b'0', 'x', '', '', UNHEX('00000000000000000000000000000000'), UNHEX('00'), UNHEX('0000'), 'a', '', '1000-01-01', '1000-01-01 00:00:00.000000', '1970-01-01 00:00:01.000000', '-838:59:59.000', 1901, '[]', ST_GeomFromText('POINT(0 0)'), '1999-12-31 23:59:59', NULL, 0),
 (1, 1, 1, 1, '1', '1', 1.1754944e-38, 4.9e-324, b'1', b'1', 'z', 'last', 'end', UNHEX('FF'), UNHEX('FF'), UNHEX('FF'), 'b', 'y', '9999-12-31', '9999-12-31 23:59:59.999999', '2038-01-19 03:14:07.999999', '00:00:00.001', 2155, '{}', ST_GeomFromText('POINT(-180 90)'), '2000-01-01 00:00:00', '2038-01-19 03:14:07', -1),
 (2, 2, 2, 2, '2', '2', 0.30000001192092896, 0.3333333333333333, b'11', b'1', 'dbl', 'third', '1/3', UNHEX('0A'), UNHEX('0B'), UNHEX('0C'), 'a', 'x,y,z', '2000-02-29', '2000-02-29 00:00:00.000001', '2000-02-29 12:00:00.000001', '12:34:56.789', 2000, '{"k":"v"}', ST_GeomFromText('POINT(10 20)'), '2000-02-29 12:00:00', '2000-02-29 12:00:01', 5)
SQL);

        // a composite key of a number and a string, in many pages
        $admin->exec("CREATE TABLE items (a INT NOT NULL, b VARCHAR(20) NOT NULL, note VARCHAR(100) NULL, PRIMARY KEY (a, b)) $engine");
        $insert = $admin->prepare('INSERT INTO items (a, b, note) VALUES (?, ?, ?)');
        for ($i = 0; $i < 237; ++$i) {
            $insert->execute([intdiv($i, 5), ['x', 'ä', "q'q", '\\', 'Z'][$i % 5] . $i, $i % 7 === 0 ? null : "row $i, with \"quotes\" and 'ticks' ü"]);
        }

        // no primary key, with identical rows
        $admin->exec("CREATE TABLE nopk (a INT NULL, b VARCHAR(40) NULL, c DECIMAL(10,2) NULL, d DATETIME NULL, e TEXT NULL) $engine");
        $insert = $admin->prepare('INSERT INTO nopk VALUES (?, ?, ?, ?, ?)');
        for ($i = 0; $i < 1500; ++$i) {
            $n = $i % 1000;
            $insert->execute([$n % 10 === 0 ? null : $n, "row " . ($n % 77), ($n % 97) / 3, '2026-01-01 00:00:00', str_repeat('x', 30)]);
        }

        // cells above the limit: bytes and text, an ON UPDATE column that must not move, empty and NULL cells
        $admin->exec("CREATE TABLE blobs (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, name VARCHAR(20) NULL, data LONGBLOB NULL, notes LONGTEXT NULL, stamp TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP) $engine");
        $insert = $admin->prepare('INSERT INTO blobs (name, data, notes, stamp) VALUES (?, ?, ?, ?)');
        $text = str_repeat("ü😀 text, 'quoted' \\ back\nline ", 300);
        foreach ([
            ['big', random_bytes(5000), $text, '2020-01-02 03:04:05'],
            ['null', null, null, null],
            ['small', "\x00small\xff", 'short', '2021-01-01 00:00:00'],
            ['at the limit', random_bytes(1000), str_repeat('a', 1000), '2022-02-02 02:02:02'],
            ['just above', random_bytes(1001), str_repeat('a', 1001), '2023-03-03 03:03:03'],
            ['empty', '', '', null],
            ['exact pieces', random_bytes(1400), str_repeat('é', 700), '2024-04-04 04:04:04'],
        ] as $row) {
            $insert->bindValue(1, $row[0]);
            $insert->bindValue(2, $row[1], $row[1] === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
            $insert->bindValue(3, $row[2]);
            $insert->bindValue(4, $row[3]);
            $insert->execute();
        }

        // a binary primary key and a huge cell: the key goes through the run state as hex
        $admin->exec("CREATE TABLE binkey (id BINARY(16) NOT NULL PRIMARY KEY, data LONGBLOB NULL) $engine");
        $insert = $admin->prepare('INSERT INTO binkey (id, data) VALUES (?, ?)');
        foreach ([["\x00\x01" . str_repeat("\xff", 14), random_bytes(3000)], [str_repeat("\x00", 16), 'small'], [str_repeat("\xff", 16), random_bytes(2500)], ["a'b\\c\n" . str_repeat('z', 10), null]] as $row) {
            $insert->bindValue(1, $row[0], PDO::PARAM_LOB);
            $insert->bindValue(2, $row[1], $row[1] === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
            $insert->execute();
        }

        // a JSON document above the limit: never cut, because the first piece alone would not be valid JSON
        $admin->exec("CREATE TABLE docs (id INT NOT NULL PRIMARY KEY, doc JSON NULL) $engine");
        $admin->exec("INSERT INTO docs VALUES (1, '" . json_encode(['k' => str_repeat('v', 4000), 'n' => [1, 2, 3]]) . "'), (2, NULL), (3, '{}')");

        // structure only
        $admin->exec("CREATE TABLE log (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, n INT NOT NULL DEFAULT 0, note VARCHAR(50) NOT NULL DEFAULT '') $engine");
        $admin->exec("INSERT INTO log (n, note) VALUES (1, 'one'), (2, 'two')");

        // objects that are not tables
        $admin->exec('CREATE VIEW b_base_view AS SELECT a, b FROM items WHERE a > 3');
        $admin->exec('CREATE VIEW a_dependent_view AS SELECT a FROM b_base_view WHERE a < 20');
        $admin->exec("CREATE TRIGGER trg_log_bi BEFORE INSERT ON log FOR EACH ROW BEGIN SET NEW.note = CONCAT('[', NEW.note, ']'); SET NEW.n = NEW.n + 1; END");
        $admin->exec('CREATE FUNCTION f_one() RETURNS INT DETERMINISTIC RETURN 1');
    }
}
