<?php

/**
 * SPIKE (phase 5, WP 1d) - throw-away script, not part of the program.
 *
 * Question: a trigger that is called every minute mostly finds nothing to do. What does such a call cost - the start of PHP, the
 * autoloader, the profiles, the listing of the backups of every profile (the scheduler needs the newest backup of each) and the
 * state files? If it is cheap enough, the scheduler can stay a plain look at the file system, with no index.
 *
 *   php tools/spikes/idle-trigger.php [profiles] [backups per profile]
 */
declare(strict_types=1);

use ThreadSafe\App\BackupService;
use ThreadSafe\App\Overview;
use ThreadSafe\Archive\ZipWriter;
use ThreadSafe\Backup\BackupName;
use ThreadSafe\Engine\RunStore;
use ThreadSafe\Plugin\PluginRegistry;
use ThreadSafe\Profile\ProfileStore;
use ThreadSafe\Profile\SiteProfile;
use ThreadSafe\Storage\BackupCatalog;
use ThreadSafe\Storage\LocalStorage;
use ThreadSafe\Tests\Support\BackupFixture;

$started = microtime(true);
require dirname(__DIR__, 2) . '/vendor/autoload.php';
$autoload = microtime(true) - $started;

$profiles = (int) ($argv[1] ?? 10);
$backups = (int) ($argv[2] ?? 50);
$base = sys_get_temp_dir() . '/spike-idle-' . bin2hex(random_bytes(3));
mkdir($base);

// one real backup, copied under many names (the listing reads the sidecar and looks at the parts)
require_once dirname(__DIR__, 2) . '/tests/Support/TempDirectory.php';
require_once dirname(__DIR__, 2) . '/tests/Support/BackupFixture.php';
$fixture = new BackupFixture($base . '/fixture');
$name = $fixture->backup();
$sidecar = (string) file_get_contents($fixture->storage()->sidecarPath($name));
$parts = glob($fixture->storageDirectory . '/' . $name->base() . '*.zip') ?: [];

$store = new ProfileStore($base . '/data/profiles');
$t = microtime(true);
for ($p = 0; $p < $profiles; ++$p) {
    $id = 'site' . $p;
    $storage = $base . '/storage/' . $id;
    mkdir($storage, 0777, true);
    $store->save(SiteProfile::fromArray(['id' => $id, 'type' => 'generic', 'path' => $fixture->site, 'storage' => $storage]));
    for ($b = 0; $b < $backups; ++$b) {
        $new = BackupName::create($id, new DateTimeImmutable('2026-01-01 00:00:00 UTC +' . ($b * 3) . ' hours'), sprintf('%08x', $p * 1000 + $b));
        $text = str_replace([$name->base(), $name->profile], [$new->base(), $id], $sidecar);
        file_put_contents($storage . '/' . $new->base() . '.json', $text);
        foreach ($parts as $part) {
            // sparse copies: the listing looks at the size only
            $target = $storage . '/' . str_replace($name->base(), $new->base(), basename($part));
            $handle = fopen($target, 'wb');
            ftruncate($handle, (int) filesize($part));
            fclose($handle);
        }
    }
}
printf("made %d profiles with %d backups each (%d files) in %.1f s\n", $profiles, $backups, $profiles * $backups * (1 + count($parts)), microtime(true) - $t);

// what one idle call does, in the process
$t0 = microtime(true);
$store = new ProfileStore($base . '/data/profiles');
$all = $store->all();
$read = microtime(true) - $t0;

$t0 = microtime(true);
$newest = [];
foreach ($all as $profile) {
    $entries = (new BackupCatalog(new LocalStorage($profile->storage)))->entries($profile->id, false);
    $newest[$profile->id] = $entries[0] ?? null;
}
$listing = microtime(true) - $t0;

// the names only (the scheduler needs the time of the newest backup: it is in the file name)
$t0 = microtime(true);
foreach ($all as $profile) {
    $names = (new LocalStorage($profile->storage))->names($profile->id);
    $newestName = $names[0] ?? null;
}
$namesOnly = microtime(true) - $t0;

$t0 = microtime(true);
$runs = new RunStore($base . '/data/runs');
$overview = (new Overview($store, $runs, new BackupService($store, PluginRegistry::load(), $runs, $base . '/data')))->build();
$overviewTime = microtime(true) - $t0;

printf("autoload %.1f ms; profiles read %.1f ms; full listing of every profile (sidecars + parts) %.1f ms; names only %.1f ms; the dashboard's Overview %.1f ms\n", $autoload * 1000, $read * 1000, $listing * 1000, $namesOnly * 1000, $overviewTime * 1000);
printf("memory peak %.1f MB\n", memory_get_peak_usage(true) / 1048576);

exec('rm -rf ' . escapeshellarg($base));
