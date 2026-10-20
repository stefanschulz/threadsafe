<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\PrestaShop9\DevMode;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ThreadSafe\Migration\MigrationRequest;
use ThreadSafe\PrestaShop9\DevMode\DevModeRules;
use ThreadSafe\Tests\Support\TempDirectory;

final class DevModeRulesTest extends TestCase
{
    use TempDirectory;

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    private function request(string ...$skip): MigrationRequest
    {
        return new MigrationRequest($this->tempDirectory(), devSkip: array_values($skip));
    }

    /** @param array<string, mixed> $data */
    private function file(array $data): string
    {
        $path = $this->tempDirectory() . '/rules.json';
        file_put_contents($path, json_encode($data, JSON_THROW_ON_ERROR));

        return $path;
    }

    public function testShippedRules(): void
    {
        $rules = DevModeRules::load();

        self::assertSame(['mail', 'payment', 'accounts', 'tracking', 'noindex'], $rules->ids());
        self::assertSame($rules->ids(), array_map(static fn ($rule): string => $rule->id, $rules->activeFor($this->request())));
    }

    public function testShippedRulesCoverTheLiveConnectionsOfARealShop(): void
    {
        $byId = [];
        foreach (DevModeRules::load()->activeFor($this->request()) as $rule) {
            $byId[$rule->id] = $rule;
        }

        self::assertContains('PS_MAIL_PASSWD', $byId['mail']->clear);
        self::assertContains('ps_accounts', $byId['accounts']->deactivateModules);
        self::assertContains('PS\_ACCOUNTS\_%', $byId['accounts']->remove);
        self::assertContains('PAYPAL\_LIVE\_%', $byId['payment']->remove);
        self::assertContains('PAYPAL_SANDBOX', array_column($byId['payment']->set, 'name'));
        self::assertContains('ps_checkout', $byId['payment']->deactivateModules);
        self::assertEqualsCanonicalizing(['ps_facebook', 'ps_googleanalytics', 'psxmarketingwithgoogle'], $byId['tracking']->deactivateModules);
        self::assertNotNull($byId['noindex']->noindex);
    }

    public function testSkippedRulesAreLeftOut(): void
    {
        $ids = array_map(static fn ($rule): string => $rule->id, DevModeRules::load()->activeFor($this->request('mail', 'noindex')));

        self::assertSame(['payment', 'accounts', 'tracking'], $ids);
    }

    public function testUnknownSkipIsAnError(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown dev rule "teleport" (known: mail, payment');

        DevModeRules::load()->activeFor($this->request('teleport'));
    }

    public function testUserRulesAddAndReplace(): void
    {
        $file = $this->file(['rules' => [
            ['id' => 'newsletter', 'title' => 'Stop the newsletter', 'clear' => ['NEWSLETTER_API_KEY'], 'deactivateModules' => ['ps_emailsubscription']],
            ['id' => 'tracking', 'title' => 'Only Facebook', 'deactivateModules' => ['ps_facebook']],
        ]]);

        $rules = DevModeRules::load($file);
        $byId = [];
        foreach ($rules->activeFor($this->request()) as $rule) {
            $byId[$rule->id] = $rule;
        }

        self::assertSame(['mail', 'payment', 'accounts', 'tracking', 'noindex', 'newsletter'], $rules->ids());
        self::assertSame(['ps_facebook'], $byId['tracking']->deactivateModules, 'A known id replaces the shipped rule.');
        self::assertSame(['NEWSLETTER_API_KEY'], $byId['newsletter']->clear);
    }

    public function testUserRuleCanBeSkippedToo(): void
    {
        $rules = DevModeRules::load($this->file(['rules' => [['id' => 'newsletter']]]));

        self::assertNotContains('newsletter', array_map(static fn ($rule): string => $rule->id, $rules->activeFor($this->request('newsletter'))));
    }

    /**
     * @param array<string, mixed> $data
     */
    #[DataProvider('invalidFiles')]
    public function testInvalidUserRulesAreRefused(array $data, string $messagePart): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($messagePart);

        DevModeRules::load($this->file($data));
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidFiles(): iterable
    {
        yield 'no rules list' => [['x' => 1], 'no list "rules"'];
        yield 'rule without id' => [['rules' => [['title' => 'x']]], 'needs an "id"'];
        yield 'bad id' => [['rules' => [['id' => 'Bad Id']]], 'needs an "id"'];
        yield 'unknown key' => [['rules' => [['id' => 'x', 'run' => 'rm -rf /']]], 'unknown key "run"'];
        yield 'set without value' => [['rules' => [['id' => 'x', 'set' => [['name' => 'A']]]]], 'invalid "set" entry'];
        yield 'set with unknown condition' => [['rules' => [['id' => 'x', 'set' => [['name' => 'A', 'value' => 'b', 'when' => 'always']]]]], 'invalid "set" entry'];
        yield 'clear is not a list of strings' => [['rules' => [['id' => 'x', 'clear' => [1]]]], '"x.clear"'];
        yield 'noindex without header' => [['rules' => [['id' => 'x', 'noindex' => ['robots' => 'x']]]], 'invalid "noindex"'];
    }

    public function testMissingAndBrokenFiles(): void
    {
        try {
            DevModeRules::load('/nope/rules.json');
            self::fail('A missing file must be refused.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('cannot be read', $e->getMessage());
        }

        $path = $this->tempDirectory() . '/broken.json';
        file_put_contents($path, '{nope');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not valid JSON');

        DevModeRules::load($path);
    }
}
