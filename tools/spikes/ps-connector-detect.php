<?php
require '/app/vendor/autoload.php';
use ThreadSafe\App\ProfileService; use ThreadSafe\Profile\ProfileStore; use ThreadSafe\Plugin\PluginRegistry;
$tmp = sys_get_temp_dir().'/spike1-'.getmypid(); mkdir($tmp.'/profiles', 0700, true);
$s = new ProfileService(new ProfileStore($tmp.'/profiles'), PluginRegistry::load(), $tmp);
[$type, $d] = $s->autodetect('/psconnector');
echo "type=$type\n"; var_dump($d->database !== null);
echo $d->database?->name ?? '-', ' host=', $d->database?->host ?? '-', ' prefix=', $d->database?->prefix ?? '-', "\n";
