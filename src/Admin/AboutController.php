<?php

declare(strict_types=1);

namespace ThreadSafe\Admin;

use ThreadSafe\Core\Paths;
use ThreadSafe\Core\Version;
use ThreadSafe\Plugin\PluginRegistry;
use ThreadSafe\Web\Html;
use ThreadSafe\Web\Response;

/**
 * The page "About", behind the login: what this is, which version, under which license, whose it is, what it contains of other people's
 * work, and a block of plain facts that a person can copy into a report of a problem (no paths, no secrets). It is reached from the version
 * under the name in the menu. The license text is the file `LICENSE` of the program, as plain text.
 */
final class AboutController
{
    public const string COPYRIGHT = '2026 Stefan Schulz, The Loom';

    public const string SITE = 'https://the-loom.de';

    public const string REPOSITORY = 'https://github.com/stefanschulz/threadsafe';

    public const string LICENSE = 'GPL-3.0-or-later';

    /**
     * @param array<string, string> $params
     */
    public function index(Context $context, array $params = []): Response
    {
        $version = Html::e(Version::CURRENT);
        $facts = $this->facts();
        $body = '<p>A backup and migration tool for PHP web applications: it backs up many sites, restores them on another domain, path or database, and adjusts the application on the way.</p>'
            . '<div class="scroll"><table class="facts"><tbody>'
            . '<tr><th scope="row">Version</th><td>' . $version . (str_contains(Version::CURRENT, '-') ? ' (a pre-release)' : '') . '</td></tr>'
            . '<tr><th scope="row">License</th><td>' . self::LICENSE . ' - <a href="' . Html::e($context->urls->to('/about/license')) . '">the license text</a></td></tr>'
            . '<tr><th scope="row">Copyright</th><td>&copy; ' . Html::e(self::COPYRIGHT) . ' (<a href="' . Html::e(self::SITE) . '" rel="noopener noreferrer">the-loom.de</a>)</td></tr>'
            . '<tr><th scope="row">Source code</th><td><a href="' . Html::e(self::REPOSITORY) . '" rel="noopener noreferrer">' . Html::e(self::REPOSITORY) . '</a></td></tr>'
            . '<tr><th scope="row">Documentation</th><td><a href="' . Html::e(self::REPOSITORY . '#documentation') . '" rel="noopener noreferrer">the list of documents</a>, '
            . '<a href="' . Html::e(self::REPOSITORY . '/releases') . '" rel="noopener noreferrer">the releases</a> with what changed</td></tr>'
            . '</tbody></table></div>'
            . '<h2>No warranty</h2>'
            . '<p>This program is free software: you can redistribute it and change it under the terms of the GNU General Public License, version 3 or (at your option) any later version. '
            . 'It is distributed in the hope that it is useful, but <strong>without any warranty</strong>; without even the implied warranty of merchantability or fitness for a particular purpose. '
            . 'Keep a copy of every backup that you cannot afford to lose somewhere else, and try a restore before you need one.</p>'
            . '<h2>Other people\'s work in it</h2>'
            . '<p>The pages use the fonts <strong>IBM Plex Sans</strong> and <strong>IBM Plex Mono</strong>, &copy; 2018 IBM Corp., under the SIL Open Font License 1.1 ('
            . '<a href="' . Html::e($context->urls->asset('fonts/OFL.txt')) . '">the license text</a>). They are part of the program and are not loaded from another server. '
            . 'There is no other code of third parties in it at run time: it needs PHP and its own extensions only.</p>'
            . '<h2>For a report of a problem</h2>'
            . '<p>Plain facts about this installation, without paths and without secrets.</p>'
            . '<pre id="facts" class="code">' . Html::e($facts) . '</pre>'
            . '<p class="actions"><button type="button" class="secondary js-only" data-copy="#facts">Copy</button></p>';

        return View::page($context, 'About ThreadSafe ' . Version::CURRENT, $body, '/about');
    }

    /**
     * The license text, as plain text.
     *
     * @param array<string, string> $params
     */
    public function license(Context $context, array $params = []): Response
    {
        $text = @file_get_contents(Paths::program() . '/LICENSE');
        if ($text === false) {
            return new Response(404, ['Content-Type: text/plain; charset=utf-8', 'X-Content-Type-Options: nosniff'], "The file LICENSE is not in this installation. The text is at https://www.gnu.org/licenses/gpl-3.0.txt\n");
        }

        return new Response(200, ['Content-Type: text/plain; charset=utf-8', 'X-Content-Type-Options: nosniff', 'Cache-Control: no-cache'], $text);
    }

    /** What a person tells in a report: the version, the PHP and its limits, the drivers, how it was installed, the website types. */
    private function facts(): string
    {
        $drivers = array_values(array_filter(['pdo_mysql', 'mysqli'], extension_loaded(...)));
        $limit = (float) ini_get('max_execution_time');
        $lines = [
            'ThreadSafe ' . Version::CURRENT,
            'Data format ' . Version::DATA_FORMAT,
            'Installation: ' . (Paths::home() !== null ? 'from a release' : 'from a clone or an unpacked package'),
            'PHP ' . PHP_VERSION . ' (' . PHP_SAPI . '), ' . PHP_OS_FAMILY,
            'memory_limit ' . (string) ini_get('memory_limit') . ', max_execution_time ' . ($limit > 0 ? $limit . ' s' : 'none'),
            'Database drivers: ' . ($drivers === [] ? 'none' : implode(', ', $drivers)),
            'Website types: ' . implode(', ', PluginRegistry::load()->ids()),
        ];

        return implode("\n", $lines);
    }
}
