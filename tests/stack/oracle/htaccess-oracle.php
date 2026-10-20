<?php

/**
 * .htaccess oracle: lets PrestaShop regenerate its own .htaccess (Tools::generateHtaccess) from the current
 * database state and prints the result. The migration must produce the same PrestaShop-managed block.
 *
 * Run inside the PrestaShop container as www-data, script on stdin:
 *   docker compose exec -T -u www-data prestashop php < tests/stack/oracle/htaccess-oracle.php
 *
 * License: GPL-3.0-or-later
 */
$root = '/var/www/html';
require $root . '/config/config.inc.php';

$copy = tempnam(sys_get_temp_dir(), 'htaccess');
// PrestaShop keeps everything outside its markers, so start from the real file.
copy($root . '/.htaccess', $copy);

Tools::generateHtaccess($copy);

fwrite(STDOUT, (string) file_get_contents($copy));
unlink($copy);
