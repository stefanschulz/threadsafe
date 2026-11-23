<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Web;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Tests\Support\TempDirectory;
use ThreadSafe\Web\AgentPage;
use ThreadSafe\Web\BackupWorkspace;

/**
 * The button that copies the pairing string (field test of the PrestaShop module, docs/PHASE15.md 8): a plugin or a module page has no script of its own, so
 * the page of the agent has to bring the script that the button needs; the wizard of the tool has it in every page already and must not get it twice.
 */
final class AgentPageCopyTest extends TestCase
{
    use TempDirectory;

    private AgentPage $page;

    protected function setUp(): void
    {
        $directory = $this->tempDirectory() . '/tools';
        mkdir($directory, 0777, true);
        $this->page = new AgentPage(BackupWorkspace::in($directory));
    }

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    private function body(bool $embedded): string
    {
        return $this->page->body('csrf', 'https://shop.example/agent.php', ['notice' => '', 'error' => '', 'pairing' => 'ts1:abc'], $embedded);
    }

    public function testAnEmbeddedPageBringsTheScriptOfTheButton(): void
    {
        $html = $this->body(true);

        self::assertStringContainsString('data-copy="#pairing-string"', $html);
        self::assertStringContainsString('<script>' . AgentPage::COPY_SCRIPT . '</script>', $html);
    }

    public function testThePageOfTheWizardGetsNoSecondScript(): void
    {
        $html = $this->body(false);

        self::assertStringContainsString('data-copy="#pairing-string"', $html);
        self::assertStringNotContainsString('<script>', $html);
    }

    public function testThereIsNoScriptWithoutAPairingString(): void
    {
        $html = $this->page->body('csrf', 'https://shop.example/agent.php', ['notice' => '', 'error' => '', 'pairing' => ''], true);

        self::assertStringNotContainsString('data-copy', $html);
        self::assertStringNotContainsString('COPY', $html);
    }
}
