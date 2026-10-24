<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Joomla;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use ThreadSafe\Backup\Manifest;
use ThreadSafe\Joomla\Restore\JoomlaRestore;
use ThreadSafe\Restore\Check;

/**
 * What a restore of a Joomla backup asks, where the old address comes from, and what is checked before anything is written.
 */
final class JoomlaRestoreTest extends TestCase
{
    /**
     * A manifest with the given application version and extras.
     *
     * @param array<string, mixed> $extras
     */
    private static function manifest(?string $version = '6.2.0', array $extras = []): Manifest
    {
        $now = new DateTimeImmutable('2026-10-23 12:00:00 UTC');

        return new Manifest('joomla-20261023-120000-abcd1234', '0.0.0-dev', 'blog', 'Blog', 'joomla', ['name' => 'Joomla', 'version' => $version], '8.4.0', [], 'snapshot', $extras, ['excludePaths' => [], 'structureOnlyTables' => [], 'literalExcludes' => []], ['files' => 0, 'directories' => 0, 'fileBytes' => 0, 'tables' => 0, 'rows' => 0, 'databaseBytes' => 0], [], $now, $now);
    }

    public function testTheOldAddressIsTheLiveSiteIfThereIsOne(): void
    {
        $manifest = self::manifest(extras: ['liveSite' => 'https://Live.Example.org/blog', 'addressCandidates' => [['host' => 'other.example', 'count' => 9]]]);

        self::assertSame('live.example.org', (new JoomlaRestore())->sourceDomain($manifest));
        self::assertSame('live.example.org:8080', (new JoomlaRestore())->sourceDomain(self::manifest(extras: ['liveSite' => 'http://live.example.org:8080'])));
    }

    public function testElseItIsTheHostThatTheContentPointsToMostOften(): void
    {
        $manifest = self::manifest(extras: ['liveSite' => '', 'addressCandidates' => [['host' => 'www.example.org', 'count' => 40], ['host' => 'cdn.example.net', 'count' => 3]]]);

        self::assertSame('www.example.org', (new JoomlaRestore())->sourceDomain($manifest));
    }

    public function testWithoutAnyHintThereIsNoOldAddress(): void
    {
        self::assertNull((new JoomlaRestore())->sourceDomain(self::manifest()));
        self::assertNull((new JoomlaRestore())->sourceDomain(self::manifest(extras: ['addressCandidates' => 'broken', 'liveSite' => 'not a url'])));
    }

    public function testTheFormProposesTheOldAddressAndListsTheOthers(): void
    {
        $manifest = self::manifest(extras: ['addressCandidates' => [['host' => 'www.example.org', 'count' => 40], ['host' => 'cdn.example.net', 'count' => 3]]]);

        $fields = (new JoomlaRestore())->fields($manifest);

        self::assertSame(['from', 'to', 'path'], array_map(static fn ($field): string => $field->name, $fields));
        self::assertSame('www.example.org', $fields[0]->default);
        self::assertStringContainsString('www.example.org (40), cdn.example.net (3)', $fields[0]->help);
        self::assertFalse($fields[1]->required, 'a restore on the same domain is allowed');
    }

    public function testTheAnswersBecomeSettingsAndAnOldAddressWithoutANewOneIsOnlyAProposal(): void
    {
        $restore = new JoomlaRestore();

        self::assertSame(['from' => 'old.example', 'to' => 'new.example', 'path' => '/sub/'], $restore->restoreSettings(['from' => 'old.example', 'to' => 'new.example', 'path' => '/sub/']));
        self::assertSame([], $restore->restoreSettings(['from' => 'old.example', 'to' => '', 'path' => '']), 'the pre-filled old address alone changes nothing');
        self::assertSame(['to' => 'new.example'], $restore->restoreSettings(['from' => '', 'to' => 'new.example']));
    }

    /**
     * @param list<Check> $checks
     *
     * @return list<string>
     */
    private static function levels(array $checks, string $level): array
    {
        return array_values(array_map(static fn (Check $check): string => $check->message, array_filter($checks, static fn (Check $check): bool => $check->level === $level)));
    }

    public function testOnlyJoomla5And6AreRestored(): void
    {
        $restore = new JoomlaRestore();

        self::assertSame([], self::levels($restore->restoreChecks(self::manifest('5.4.8', ['addressCandidates' => [['host' => 'a.example', 'count' => 1]]])), 'error'));
        self::assertSame([], self::levels($restore->restoreChecks(self::manifest('6.2.0', ['addressCandidates' => [['host' => 'a.example', 'count' => 1]]])), 'error'));
        foreach (['4.4.10', '3.10.12', '7.0.0'] as $version) {
            $errors = self::levels($restore->restoreChecks(self::manifest($version)), 'error');
            self::assertCount(1, $errors, $version);
            self::assertStringContainsString("Joomla $version", $errors[0]);
        }
        self::assertStringContainsString('unknown version', self::levels($restore->restoreChecks(self::manifest(null)), 'error')[0]);
    }

    public function testWhatTheBackupDidNotKnowIsWarnedAbout(): void
    {
        $warnings = self::levels((new JoomlaRestore())->restoreChecks(self::manifest('6.2.0', ['extrasWarning' => 'no database', 'configurationWarning' => 'odd file', 'liveSite' => 'https://x.example'])), 'warning');
        $all = implode("\n", $warnings);

        self::assertStringContainsString('no database', $all);
        self::assertStringContainsString('odd file', $all);
        self::assertStringContainsString('live_site = https://x.example', $all);

        $unknown = implode("\n", self::levels((new JoomlaRestore())->restoreChecks(self::manifest()), 'warning'));
        self::assertStringContainsString('does not say which domain', $unknown);
    }
}
