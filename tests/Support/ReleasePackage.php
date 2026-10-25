<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Support;

use ThreadSafe\Build\ReleaseBuilder;

/**
 * A small but complete release package for the tests of the installer and the update: a tree with a program, a stamp, an autoloader
 * and a few files, made into a package by the real {@see ReleaseBuilder}.
 */
final class ReleasePackage
{
    public const int EPOCH = 1761955200;

    /**
     * @param array<string, string> $extra more files of the tree: path => content
     *
     * @return string the path of `threadsafe-<version>.zip` in $directory
     */
    public static function build(string $directory, string $version = '0.1.0', array $extra = []): string
    {
        $tree = $directory . '/tree-' . $version;
        $files = [
            'bin/threadsafe' => "#!/usr/bin/env php\n<?php echo 'threadsafe ' . ThreadSafe\\Core\\Version::CURRENT . \"\\n\";\n",
            'src/Core/Version.php' => "<?php\nnamespace ThreadSafe\\Core;\nfinal class Version\n{\n    public const string CURRENT = '0.0.0-dev';\n}\n",
            'plugins/demo/plugin.json' => '{"id": "demo"}',
            'public/app.css' => 'body { color: #000; }',
            'public/.htaccess' => 'RewriteEngine On',
            'composer.json' => '{"name": "x/y"}',
            'LICENSE' => 'GPL',
            'README.md' => '# readme',
            'vendor/autoload.php' => "<?php\n// the autoloader of the test package\n",
            'vendor/composer/ClassLoader.php' => '<?php // loader',
            ...$extra,
        ];
        foreach ($files as $path => $content) {
            @mkdir(dirname($tree . '/' . $path), 0777, true);
            file_put_contents($tree . '/' . $path, $content);
        }
        $stage = $directory . '/stage-' . $version;
        (new ReleaseBuilder($tree))->stage($stage, $version);
        // the staging copies the program and not vendor/, as the real build adds that with Composer
        foreach (['vendor/autoload.php', 'vendor/composer/ClassLoader.php'] as $path) {
            @mkdir(dirname($stage . '/' . $path), 0777, true);
            copy($tree . '/' . $path, $stage . '/' . $path);
        }
        $zip = $directory . '/threadsafe-' . $version . '.zip';
        (new ReleaseBuilder($stage))->write($zip, $version, self::EPOCH);

        return $zip;
    }

    private function __construct()
    {
    }
}
