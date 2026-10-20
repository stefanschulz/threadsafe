<?php

/**
 * ThreadSafe test fixture module.
 *
 * Stores the shop domain the way real modules do – plain, inside a serialized option, inside a JSON option, in
 * its own tables, in a language table, in a generated file and in a webhook URL – so that the migration tests
 * have something realistic to rewrite. It also keeps a few look-alikes that must NOT be rewritten (a foreign
 * domain, an e-mail address, a bare host name).
 *
 * Only used in the Docker test stacks; never shipped.
 *
 * License: GPL-3.0-or-later
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class Threadsafe_Fixture extends Module
{
    public function __construct()
    {
        $this->name = 'threadsafe_fixture';
        $this->tab = 'administration';
        $this->version = '1.0.0';
        $this->author = 'ThreadSafe';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '9.0.0', 'max' => _PS_VERSION_];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = 'ThreadSafe fixture';
        $this->description = 'Stores the shop domain in many forms for migration tests.';
    }

    public function install(): bool
    {
        return parent::install()
            && $this->createTables()
            && $this->storeConfiguration()
            && $this->storeRows()
            && $this->writeGeneratedFile();
    }

    public function uninstall(): bool
    {
        $db = Db::getInstance();
        $db->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'threadsafe_fixture_item_lang`');
        $db->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'threadsafe_fixture_item`');
        foreach (['SERIALIZED', 'JSON', 'URL', 'BARE', 'WEBHOOK'] as $name) {
            Configuration::deleteByName('THREADSAFE_FIXTURE_' . $name);
        }

        return parent::uninstall();
    }

    /** "localhost:8190" – the shop domain as stored at install time. */
    private function domain(): string
    {
        return (string) Configuration::get('PS_SHOP_DOMAIN');
    }

    private function base(): string
    {
        return 'http://' . $this->domain();
    }

    private function createTables(): bool
    {
        $db = Db::getInstance();

        return $db->execute('CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'threadsafe_fixture_item` (
                `id_item` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `name` VARCHAR(64) NOT NULL,
                `url` VARCHAR(255) DEFAULT NULL,
                `payload` LONGTEXT DEFAULT NULL,
                PRIMARY KEY (`id_item`)
            ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4')
            && $db->execute('CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'threadsafe_fixture_item_lang` (
                `id_item` INT UNSIGNED NOT NULL,
                `id_lang` INT UNSIGNED NOT NULL,
                `text` LONGTEXT DEFAULT NULL,
                PRIMARY KEY (`id_item`, `id_lang`)
            ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4');
    }

    private function storeConfiguration(): bool
    {
        $domain = $this->domain();
        $base = $this->base();

        $serialized = serialize([
            'home' => $base . '/',
            'links' => [$base . '/a', 'http://www.' . $domain . '/b', '//' . $domain . '/c'],
            'meta' => [
                'callback' => $base . '/module/threadsafe_fixture/callback',
                'foreign' => 'http://other.test/x',
                'mail' => 'info@other.test',
                'bare' => $domain,
            ],
            'object' => (object) ['url' => $base . '/object'],
            'nested' => json_encode(['u' => $base . '/nested', 'umlaut' => 'Größe ✓']),
        ]);

        $json = json_encode([
            'shop' => $base . '/',
            'list' => [$base . '/one', 'https://' . $domain . '/two'],
            'text' => 'Größe ✓',
            'number' => 1.0,
        ]);

        return Configuration::updateValue('THREADSAFE_FIXTURE_SERIALIZED', $serialized)
            && Configuration::updateValue('THREADSAFE_FIXTURE_JSON', (string) $json)
            && Configuration::updateValue('THREADSAFE_FIXTURE_URL', $base . '/plain/url')
            && Configuration::updateValue('THREADSAFE_FIXTURE_BARE', $domain)
            && Configuration::updateValue('THREADSAFE_FIXTURE_WEBHOOK', $base . '/module/threadsafe_fixture/hook?token=abc');
    }

    private function storeRows(): bool
    {
        $db = Db::getInstance();
        $base = $this->base();
        $domain = $this->domain();

        $ok = $db->insert('threadsafe_fixture_item', [
            'name' => 'plain',
            'url' => pSQL($base . '/item/1'),
            'payload' => pSQL('See ' . $base . '/item/1 or mail info@other.test'),
        ]);
        $ok = $ok && $db->insert('threadsafe_fixture_item', [
            'name' => 'serialized',
            'url' => pSQL('http://www.' . $domain . '/item/2'),
            'payload' => pSQL(serialize(['url' => $base . '/item/2', 'n' => 2, 'tags' => ['a', 'b']])),
        ]);
        $ok = $ok && $db->insert('threadsafe_fixture_item', [
            'name' => 'json',
            'url' => pSQL('//' . $domain . '/item/3'),
            'payload' => pSQL((string) json_encode(['url' => $base . '/item/3'])),
        ]);

        foreach (Language::getIDs(false) as $idLang) {
            for ($idItem = 1; $idItem <= 3; ++$idItem) {
                $ok = $ok && $db->insert('threadsafe_fixture_item_lang', [
                    'id_item' => $idItem,
                    'id_lang' => (int) $idLang,
                    'text' => pSQL('<p><a href="' . $base . '/lang/' . $idItem . '">link</a> <img src="//' . $domain . '/img/' . $idItem . '.png"></p>', true),
                ]);
            }
        }

        return (bool) $ok;
    }

    private function writeGeneratedFile(): bool
    {
        $dir = __DIR__ . '/generated';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }
        $content = json_encode([
            'generated' => date('c'),
            'urls' => [$this->base() . '/', 'https://' . $this->domain() . '/feed.xml'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return file_put_contents($dir . '/urls.json', (string) $content) !== false;
    }
}
