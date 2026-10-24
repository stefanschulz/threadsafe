<?php

declare(strict_types=1);

namespace ThreadSafe\Joomla\Steps;

use ThreadSafe\Core\AtomicFile;
use ThreadSafe\Core\Change;
use ThreadSafe\Core\Context;
use ThreadSafe\Core\Notice;
use ThreadSafe\Core\Plan;
use ThreadSafe\Core\Step;
use ThreadSafe\Core\StepResult;
use ThreadSafe\Joomla\State;

/**
 * Dev mode, file part: keeps search engines out of the copy. `robots.txt` forbids everything and a block at the top of `.htaccess` sends
 * `X-Robots-Tag: noindex, nofollow` with every response (what robots.txt cannot do: a page that a search engine already knows is only
 * removed by a noindex). The meta tag of the pages comes from the `robots` setting that `WriteConfiguration` makes.
 *
 * Joomla has no `.htaccess` until a person copies `htaccess.txt`; one is made with the block alone, which changes nothing else (the
 * addresses without `index.php` need the rules of `htaccess.txt`, and a site that has them has the file).
 */
final class BlockSearchEngines implements Step
{
    private const string START = '# BEGIN ThreadSafe dev mode';

    private const string END = '# END ThreadSafe dev mode';

    public const string ROBOTS = "User-agent: *\nDisallow: /\n";

    public const string HEADER = 'noindex, nofollow';

    public function id(): string
    {
        return 'block-search-engines';
    }

    public function title(): string
    {
        return 'Dev mode: keep search engines out (robots.txt, X-Robots-Tag)';
    }

    public function plan(Context $context): Plan
    {
        $plan = new Plan();
        if (!State::request($context)->isDevMode()) {
            return $plan;
        }
        $root = State::source($context)->root;
        foreach ($this->files($root) as $name => [$old, $new]) {
            if ($old === $new) {
                continue;
            }
            $plan->add($old === null ? Change::create("file $name", 'create it (search engines are kept out)') : Change::update("file $name", 'keep search engines out'));
        }
        $plan->notice(Notice::info('On a web server other than Apache the header is not sent: robots.txt and the robots meta tag of the pages keep search engines out; send "X-Robots-Tag: ' . self::HEADER . '" from the web server too.'));

        return $plan;
    }

    public function apply(Context $context, Plan $plan): StepResult
    {
        if (!State::request($context)->isDevMode()) {
            return new StepResult(0);
        }
        $root = State::source($context)->root;
        $applied = 0;
        foreach ($this->files($root) as $name => [$old, $new]) {
            if ($old === $new) {
                continue;
            }
            $path = $root . '/' . $name;
            $backup = $context->journal->backupFile($path);
            AtomicFile::write($path, $new);
            $context->journal->file($path, $old === null ? 'create' : 'update', $backup);
            ++$applied;
        }

        return new StepResult($applied);
    }

    /**
     * @return array<string, array{string|null, string}> file name => current and wanted content (current null: no file)
     */
    private function files(string $root): array
    {
        $robots = is_file($root . '/robots.txt') ? file_get_contents($root . '/robots.txt') : false;
        $htaccess = is_file($root . '/.htaccess') ? file_get_contents($root . '/.htaccess') : false;

        return [
            'robots.txt' => [$robots === false ? null : $robots, self::ROBOTS],
            '.htaccess' => [$htaccess === false ? null : $htaccess, self::withBlock($htaccess === false ? '' : $htaccess)],
        ];
    }

    /** The `.htaccess` content with exactly one header block at the very top. */
    public static function withBlock(string $content): string
    {
        $block = self::START . " - written by ThreadSafe; delete the block to let search engines in.\n"
            . "<IfModule mod_headers.c>\n"
            . '    Header set X-Robots-Tag "' . self::HEADER . "\"\n"
            . "</IfModule>\n"
            . self::END . "\n";
        $without = preg_replace('/' . preg_quote(self::START, '/') . '.*?' . preg_quote(self::END, '/') . "\r?\n?/s", '', $content) ?? $content;

        return $without === '' ? $block : $block . "\n" . ltrim($without, "\r\n");
    }
}
