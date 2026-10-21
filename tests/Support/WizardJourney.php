<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * The way a person goes through the wizard, as requests: key, backup, requirements, target (with a connection test when there is
 * a database), summary, ticks the way the page's script does them, done. The cleanup is left to the caller.
 */
final class WizardJourney
{
    /**
     * @param array<string, string> $target the fields of the target form (root, db_...)
     *
     * @return array{done: array{status: int, headers: array<string, string>, body: string}, ticks: int}
     */
    public static function restore(WebWizard $wizard, string $key, array $target): array
    {
        $page = $wizard->request('GET', '/');
        Assert::assertSame(200, $page['status']);
        Assert::assertStringContainsString('name="key"', $page['body']);
        $login = $wizard->request('POST', '/', ['action' => 'login', 'key' => $key]);
        Assert::assertSame(303, $login['status'], $login['body']);
        Assert::assertTrue($wizard->hasCookie());
        Assert::assertStringContainsString('HttpOnly', (string) $login['headers']['set-cookie']);

        $backup = $wizard->follow($login);
        Assert::assertStringContainsString('Found next to the tool', $backup['body']);
        if (preg_match('/name="choice" value="([^"]+)"/', $backup['body'], $m) !== 1) {
            Assert::fail('The backup is not offered.');
        }
        $requirements = $wizard->follow($wizard->request('POST', '/', ['action' => 'choose-backup', 'choice' => $m[1], 'path' => '', 'csrf' => $wizard->csrf()]));
        Assert::assertStringContainsString('What this server offers', $requirements['body']);

        if (isset($target['db_host'])) {
            $tested = $wizard->request('POST', '/', ['action' => 'test-db', 'csrf' => $wizard->csrf()] + $target);
            Assert::assertStringContainsString('Connected to', $tested['body']);
            Assert::assertStringContainsString('holds 0 table', $tested['body']);
        }
        $wizard->follow($wizard->request('POST', '/', ['action' => 'save-target', 'csrf' => $wizard->csrf()] + $target));
        $summary = $wizard->request('GET', '/?step=summary');
        Assert::assertStringContainsString('Restore now', $summary['body'], $summary['body']);
        if (isset($target['db_password']) && $target['db_password'] !== '') {
            Assert::assertStringNotContainsString($target['db_password'], $summary['body'], 'the password is never shown again');
        }

        $run = $wizard->follow($wizard->request('POST', '/', ['action' => 'start', 'csrf' => $wizard->csrf()]));
        Assert::assertStringContainsString('Keep this page open', $run['body']);
        $last = [];
        $ticks = 0;
        for ($ticks = 1; $ticks <= 800; ++$ticks) {
            $answer = $wizard->request('POST', '/', ['action' => 'tick', 'format' => 'json', 'csrf' => $wizard->csrf()]);
            Assert::assertStringContainsString('application/json', (string) $answer['headers']['content-type']);
            $last = json_decode($answer['body'], true);
            Assert::assertContains($last['status'], ['running', 'finished'], $answer['body']);
            if ($last['status'] === 'finished') {
                break;
            }
        }
        Assert::assertSame('finished', $last['status']);

        $done = $wizard->request('GET', '/?step=done');
        Assert::assertStringContainsString('The backup is restored', $done['body']);

        return ['done' => $done, 'ticks' => $ticks];
    }
}
