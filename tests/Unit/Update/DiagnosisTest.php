<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Update;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Update\Diagnosis;

/**
 * What the page says about an update that was taken back: a cause in plain words and the steps that follow from it.
 */
final class DiagnosisTest extends TestCase
{
    /**
     * @return array<string, array{string, string, bool}> message, a word the cause names, whether the way out is the box that skips the check
     */
    public static function findings(): array
    {
        return [
            'the field test' => ['The new version did not answer: no answer from https://threadsafe.the-loom.de/health/83b0 (Failed to open stream: php_network_getaddresses: getaddrinfo for threadsafe.the-loom.de failed: Temporary failure in name resolution).', 'look up its own name', true],
            'no connection' => ['The new version did not answer: no answer from https://example.org/health/x (Connection refused).', 'could not reach its own address', true],
            'a certificate' => ['The new version did not answer: no answer from https://example.org/health/x (SSL operation failed: certificate verify failed).', 'certificate', true],
            'a page that is not ours' => ['The new version answered with HTTP 404 and not as expected; it did not start properly.', 'not as ThreadSafe does', true],
            'a version that failed' => ['The new version answered with HTTP 500 and not as expected; it did not start properly.', 'started and failed', false],
            'another version' => ['The address answered as version 0.1.0, not 0.2.0 (a cache in front of the site, or another installation under this address).', 'as another version', true],
            'something else' => ['Something unforeseen.', 'did not answer the check as expected', true],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('findings')]
    public function testEveryFindingHasACauseAndWaysOn(string $message, string $word, bool $offersTheBox): void
    {
        $diagnosis = Diagnosis::explain($message);

        self::assertStringContainsString($word, $diagnosis['cause']);
        self::assertGreaterThanOrEqual(2, count($diagnosis['steps']));
        self::assertSame($offersTheBox, str_contains(implode(' ', $diagnosis['steps']), 'Do not start the new version to check it'), 'the box is offered only where the check could not ask');
    }

    public function testARealFailureOfTheNewVersionIsNotToBeSkipped(): void
    {
        $steps = implode(' ', Diagnosis::explain('The new version answered with HTTP 500 and not as expected; it did not start properly.')['steps']);

        self::assertStringContainsString('error log', $steps);
        self::assertStringContainsString('Do not skip the check', $steps);
    }
}
