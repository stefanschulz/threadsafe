#!/usr/bin/env php
<?php
/**
 * ThreadSafe – phase 0: domain inventory for PrestaShop 9.
 *
 * Counts where a (old) domain occurs in the database and files of a PrestaShop, and flags occurrences that need
 * a serialisation-safe or JSON-safe replacement. Read-only, changes nothing.
 *
 * Usage (inside the PrestaShop container, which has PHP 8.4+ and pdo_mysql, the PDO driver that also serves MariaDB):
 *   php domain-inventory.php --root=/var/www/html --domain=localhost:8190 [--domain=other.tld]
 *        [--json=report.json] [--no-files] [--skip-tables=a,b,c]
 *
 * License: GPL-3.0-or-later
 */

declare(strict_types=1);

const DEFAULT_SKIP_TABLES = [
    'connections', 'connections_source', 'connections_page', 'guest', 'log', 'pagenotfound', 'page_viewed',
    'statssearch', 'psgdpr_log', 'employee_session', 'customer_session', 'mail',
    'smarty_cache', 'smarty_lazy_cache', 'smarty_last_flush',
];

const SKIP_DIRS = ['var/cache', 'var/logs', 'cache', 'vendor', 'node_modules', '.git', 'img', 'translations', 'download', 'upload'];
const TEXT_EXT = ['php', 'html', 'htm', 'tpl', 'txt', 'xml', 'json', 'yml', 'yaml', 'ini', 'js', 'css', 'htaccess', 'md', 'twig'];
const MAX_FILE_BYTES = 5 * 1024 * 1024;

function fail(string $message): never
{
    fwrite(STDERR, "Error: $message\n");
    exit(1);
}

/** @return array{root:string,domains:list<string>,json:?string,files:bool,skip:list<string>} */
function parseArgs(array $argv): array
{
    $opt = ['root' => '', 'domains' => [], 'json' => null, 'files' => true, 'skip' => DEFAULT_SKIP_TABLES];
    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, '--root=')) {
            $opt['root'] = rtrim(substr($arg, 7), '/\\');
        } elseif (str_starts_with($arg, '--domain=')) {
            $opt['domains'][] = strtolower(substr($arg, 9));
        } elseif (str_starts_with($arg, '--json=')) {
            $opt['json'] = substr($arg, 7);
        } elseif (str_starts_with($arg, '--skip-tables=')) {
            $opt['skip'] = array_filter(explode(',', substr($arg, 14)));
        } elseif ($arg === '--no-files') {
            $opt['files'] = false;
        } else {
            fail("Unknown option: $arg");
        }
    }
    if ($opt['root'] === '' || $opt['domains'] === []) {
        fail('--root and at least one --domain are required.');
    }
    return $opt;
}

/** Reads the DB connection from app/config/parameters.php (PrestaShop >= 1.7). */
function readParameters(string $root): array
{
    $file = "$root/app/config/parameters.php";
    if (!is_file($file)) {
        fail("$file not found.");
    }
    // Phase 0 / own system: a plain include. The product itself will parse the file without executing code.
    $config = (static fn() => include $file)();
    return $config['parameters'] ?? fail('parameters.php has no "parameters" key.');
}

/** Escapes LIKE wildcards and returns a quoted SQL literal '%…%'. */
function likeLiteral(PDO $db, string $needle): string
{
    return $db->quote('%' . addcslashes($needle, '%_\\') . '%');
}

/** @return array<string,mixed> */
function scanDatabase(array $p, array $domains, array $skip): array
{
    try {
        $db = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $p['database_host'], (int)$p['database_port'], $p['database_name']),
            $p['database_user'],
            $p['database_password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    } catch (PDOException $e) {
        fail('DB connection: ' . $e->getMessage());
    }
    $version = (string)$db->query('SELECT VERSION()')->fetchColumn();
    if (stripos($version, 'mariadb') === false) {
        fwrite(STDERR, "Warning: database server reports \"$version\" – ThreadSafe supports MariaDB only.\n");
    }
    $prefix = $p['database_prefix'];
    $dbName = $db->quote($p['database_name']);

    $cols = [];
    $res = $db->query("SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = $dbName
        AND DATA_TYPE IN ('char','varchar','tinytext','text','mediumtext','longtext','json') ORDER BY TABLE_NAME, ORDINAL_POSITION");
    while ($row = $res->fetch(PDO::FETCH_NUM)) {
        $cols[$row[0]][] = $row[1];
    }
    $tables = [];
    $res = $db->query("SELECT TABLE_NAME, TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA = $dbName AND TABLE_TYPE = 'BASE TABLE'");
    while ($row = $res->fetch(PDO::FETCH_NUM)) {
        $tables[$row[0]] = (int)$row[1];
    }

    $skipFull = array_map(static fn(string $t): string => $prefix . $t, $skip);
    $result = ['tables' => count($tables), 'skipped' => [], 'hits' => []];

    foreach ($tables as $table => $approxRows) {
        if (in_array($table, $skipFull, true)) {
            $result['skipped'][$table] = $approxRows;
            continue;
        }
        if (empty($cols[$table])) {
            continue;
        }
        foreach ($domains as $domain) {
            $like = likeLiteral($db, $domain);
            $select = [];
            foreach ($cols[$table] as $i => $col) {
                $q = '`' . str_replace('`', '``', $col) . '`';
                $select[] = "SUM($q LIKE $like) AS h$i";
                $select[] = "SUM($q LIKE $like AND $q REGEXP 's:[0-9]+:\"') AS s$i";
                $select[] = "SUM($q LIKE $like AND ($q LIKE '{%' OR $q LIKE '[%')) AS j$i";
            }
            $query = 'SELECT ' . implode(', ', $select) . ' FROM `' . str_replace('`', '``', $table) . '`';
            try {
                $row = $db->query($query)->fetch(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                $result['errors'][] = "$table: " . $e->getMessage();
                continue;
            }
            foreach ($cols[$table] as $i => $col) {
                if ((int)$row["h$i"] > 0) {
                    $result['hits'][] = [
                        'table' => $table, 'column' => $col, 'domain' => $domain,
                        'rows' => (int)$row["h$i"], 'serialized' => (int)$row["s$i"], 'json' => (int)$row["j$i"],
                    ];
                }
            }
        }
    }
    return $result;
}

/** @return array{files:list<array{path:string,domain:string,hits:int}>,hostBound:list<string>} */
function scanFiles(string $root, array $domains): array
{
    $found = [];
    $hostBound = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        static function (SplFileInfo $f) use ($root): bool {
            $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
            foreach (SKIP_DIRS as $dir) {
                if ($rel === $dir || str_starts_with($rel, $dir . '/')) {
                    return false;
                }
            }
            // vendor/node_modules folders anywhere; autoupgrade backups inside the back office folder (arbitrary name)
            return !preg_match('#(^|/)(vendor|node_modules)(/|$)|^[^/]+/autoupgrade/(backup|download|tmp|logs)(/|$)#', $rel);
        },
    ));
    foreach ($iterator as $file) {
        /** @var SplFileInfo $file */
        if (!$file->isFile() || $file->getSize() > MAX_FILE_BYTES) {
            continue;
        }
        $name = $file->getFilename();
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, TEXT_EXT, true) && $name !== '.htaccess') {
            continue;
        }
        $content = (string)file_get_contents($file->getPathname());
        $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        foreach ($domains as $domain) {
            $n = substr_count(strtolower($content), $domain);
            if ($n > 0) {
                $found[] = ['path' => $rel, 'domain' => $domain, 'hits' => $n];
            }
        }
        if ($name === 'php.ini' && preg_match('/^\s*error_log\s*=\s*(\S+)/mi', $content, $m)) {
            $hostBound[] = "$rel: error_log = {$m[1]}";
        }
        if ($name === '.htaccess') {
            $c = preg_match_all('/^RewriteCond %\{HTTP_HOST\}.*$/mi', $content);
            if ($c) {
                $hostBound[] = "$rel: {$c}× RewriteCond %{HTTP_HOST}";
            }
        }
    }
    usort($found, static fn(array $a, array $b): int => $b['hits'] <=> $a['hits']);
    return ['files' => $found, 'hostBound' => $hostBound];
}

function report(array $db, ?array $files): void
{
    echo "== Database ({$db['tables']} tables)\n";
    if ($db['hits'] === []) {
        echo "  no hits\n";
    }
    foreach ($db['hits'] as $h) {
        $flags = ($h['serialized'] ? " SERIALIZED:{$h['serialized']}" : '') . ($h['json'] ? " JSON:{$h['json']}" : '');
        printf("  %-40s %-22s %-24s %6d row(s)%s\n", $h['table'], $h['column'], $h['domain'], $h['rows'], $flags);
    }
    if ($db['skipped']) {
        echo "-- skipped (log/cache tables, approx. rows): ";
        echo implode(', ', array_map(static fn($t, $n) => "$t ($n)", array_keys($db['skipped']), $db['skipped'])) . "\n";
    }
    foreach ($db['errors'] ?? [] as $e) {
        echo "  Note: $e\n";
    }
    if ($files !== null) {
        echo "\n== Files\n";
        foreach (array_slice($files['files'], 0, 40) as $f) {
            printf("  %5d  %-24s %s\n", $f['hits'], $f['domain'], $f['path']);
        }
        if (count($files['files']) > 40) {
            echo '  … and ' . (count($files['files']) - 40) . " more files\n";
        }
        echo "-- host-bound files:\n";
        foreach ($files['hostBound'] ?: ['  none'] as $line) {
            echo "  $line\n";
        }
    }
}

$opt = parseArgs($argv);
$params = readParameters($opt['root']);
$dbResult = scanDatabase($params, $opt['domains'], $opt['skip']);
$fileResult = $opt['files'] ? scanFiles($opt['root'], $opt['domains']) : null;
report($dbResult, $fileResult);
if ($opt['json'] !== null) {
    file_put_contents($opt['json'], json_encode(['db' => $dbResult, 'files' => $fileResult], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}
