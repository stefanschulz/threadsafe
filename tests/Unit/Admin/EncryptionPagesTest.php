<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ThreadSafe\Crypto\AgeKeys;
use ThreadSafe\Crypto\Passphrases;
use ThreadSafe\Engine\Budget;
use ThreadSafe\Profile\ProfileStore;
use ThreadSafe\Storage\BackupCatalog;
use ThreadSafe\Tests\Support\AdminBrowser;
use ThreadSafe\Tests\Support\BackupFixture;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * Encrypted backups in the browser: the public keys in the profile form, the page where a key pair is made (shown once, never kept),
 * the passphrase of one backup, and what the pages say about and do with an encrypted backup.
 */
final class EncryptionPagesTest extends TestCase
{
    use TempDirectory;

    private BackupFixture $fixture;

    private AdminBrowser $browser;

    protected function setUp(): void
    {
        $this->fixture = new BackupFixture($this->tempDirectory());
        $this->browser = new AdminBrowser($this->fixture->data, '', static fn (): Budget => Budget::unlimited());
        $this->browser->now = time();
        $this->browser->install();
        $this->browser->login();
        $this->browser->get('/');
    }

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    private function store(): ProfileStore
    {
        return new ProfileStore($this->fixture->data . '/profiles');
    }

    /**
     * @param array<string, string> $changes
     *
     * @return array<string, string>
     */
    private function fields(array $changes = []): array
    {
        return $changes + ['action' => 'save', 'skip_test' => '1', 'id' => 'news', 'name' => 'News', 'type' => 'generic', 'path' => $this->fixture->site, 'storage' => $this->fixture->storageDirectory . '-news',
            'compression' => '6', 'part_size' => '', 'db_mode' => 'site', 'default_filters' => '1', 'exclude' => '', 'structure_only' => '', 'note' => ''];
    }

    /** @return array{secret: string, public: string, file: string} */
    private function pairFrom(string $body): array
    {
        self::assertSame(1, preg_match('/AGE-SECRET-KEY-1[A-Z0-9]+/', $body, $secret), 'the secret key is on the page');
        self::assertSame(1, preg_match('/<code id="public-key">(age1[a-z0-9]{58})<\/code>/', $body, $public));
        self::assertSame(1, preg_match('/<pre id="secret-key" class="secret"><code>(.*?)<\/code><\/pre>/s', $body, $file));

        return ['secret' => $secret[0] ?? '', 'public' => $public[1] ?? '', 'file' => html_entity_decode($file[1] ?? '')];
    }

    public function testTheFormTakesPublicKeysShowsThemAgainAndLeavesThemAloneWhenItHasNoSuchField(): void
    {
        $one = AgeKeys::generate()['public'];
        $two = AgeKeys::generate()['public'];

        $created = $this->browser->post('/profiles', $this->fields(['recipients' => "$one\n\n $two \n$one\n"]));

        self::assertSame(303, $created->status, $created->body);
        self::assertSame([$one, $two], $this->store()->load('news')->encryption?->recipients, 'blank lines and the duplicate are gone');
        $edit = $this->browser->get('/profiles/news/edit');
        self::assertStringContainsString("$one\n$two</textarea>", $edit->body);
        self::assertStringContainsString('/profiles/news/keys', $edit->body, 'the way to a new key pair');
        self::assertStringContainsString('for 2 public keys', $this->browser->get('/profiles/news')->body);

        $this->browser->post('/profiles/news', $this->fields());
        self::assertSame([$one, $two], $this->store()->load('news')->encryption?->recipients, 'a form without the field leaves the keys alone');

        $this->browser->post('/profiles/news', $this->fields(['recipients' => '']));
        self::assertNull($this->store()->load('news')->encryption, 'a form that sends the field empty takes the keys away');
    }

    public function testAMistypedKeyComesBackWithTheFormAndNothingIsStored(): void
    {
        $good = AgeKeys::generate()['public'];
        $this->browser->post('/profiles', $this->fields(['recipients' => $good]));
        $typo = substr($good, 0, 30) . ($good[30] === 'q' ? 'p' : 'q') . substr($good, 31);

        $wrong = $this->browser->post('/profiles/news', $this->fields(['recipients' => $typo]));

        self::assertSame(400, $wrong->status);
        self::assertStringContainsString('is not one', $wrong->body);
        self::assertSame([$good], $this->store()->load('news')->encryption?->recipients, 'the stored profile is as it was');
    }

    public function testTheKeyPairIsShownOnceWithMeansToKeepItAndNothingOfItIsKept(): void
    {
        $page = $this->browser->get('/profiles/blog/keys');
        self::assertSame(200, $page->status);
        self::assertStringContainsString('Make a key pair', $page->body);

        $made = $this->browser->post('/profiles/blog/keys/new');

        self::assertSame(200, $made->status);
        $pair = $this->pairFrom($made->body);
        self::assertSame($pair['public'], AgeKeys::publicOf($pair['secret']), 'the public key belongs to the secret key');
        self::assertStringContainsString('# public key: ' . $pair['public'], $pair['file'], 'the key file is the file of age-keygen');
        foreach (['data-copy="#secret-key"', 'data-save="#secret-key"', 'data-print', 'data-copy="#public-key"', 'name="stored"', 'required'] as $means) {
            self::assertStringContainsString($means, $made->body);
        }
        self::assertStringContainsString('only time', $made->body);
        self::assertStringContainsString('no-store', (string) $made->header('Cache-Control'));
        self::assertNull($this->store()->load('blog')->encryption, 'nothing is changed by looking');

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->fixture->data, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($files as $file) {
            self::assertStringNotContainsString($pair['secret'], (string) file_get_contents($file->getPathname()), 'the secret key is in no file of the data directory: ' . $file->getPathname());
        }
    }

    public function testThePublicKeyIsUsedOnlyWhenTheSecretKeyIsSaidToBeSafe(): void
    {
        $pair = $this->pairFrom($this->browser->post('/profiles/blog/keys/new')->body);

        $refused = $this->browser->post('/profiles/blog/keys/use', ['public' => $pair['public']]);
        self::assertSame(303, $refused->status);
        self::assertSame('/profiles/blog/keys', $refused->header('Location'));
        self::assertNull($this->store()->load('blog')->encryption);
        self::assertStringContainsString('make a new one', $this->browser->get('/profiles/blog/keys')->body);

        $mistyped = $this->browser->post('/profiles/blog/keys/use', ['public' => 'age1nothing', 'stored' => '1']);
        self::assertSame('/profiles/blog/keys', $mistyped->header('Location'));
        self::assertNull($this->store()->load('blog')->encryption);

        $used = $this->browser->post('/profiles/blog/keys/use', ['public' => $pair['public'], 'stored' => '1']);
        self::assertSame(303, $used->status);
        self::assertSame('/profiles/blog', $used->header('Location'));
        self::assertSame([$pair['public']], $this->store()->load('blog')->encryption?->recipients);
        $profile = $this->browser->get('/profiles/blog')->body;
        self::assertStringContainsString('for 1 public key', $profile);
        self::assertStringContainsString(substr($pair['public'], 0, 12) . '...' . substr($pair['public'], -6), $profile);

        $second = $this->pairFrom($this->browser->post('/profiles/blog/keys/new')->body);
        $this->browser->post('/profiles/blog/keys/use', ['public' => $second['public'], 'stored' => '1']);
        self::assertSame([$pair['public'], $second['public']], $this->store()->load('blog')->encryption?->recipients, 'a second pair is added, the first stays');
    }

    public function testThePagesOfAnUnknownProfileSayNotFound(): void
    {
        self::assertSame(404, $this->browser->get('/profiles/nope/keys')->status);
        self::assertSame(404, $this->browser->post('/profiles/nope/keys/new')->status);
        self::assertSame(404, $this->browser->post('/profiles/nope/keys/use', ['public' => 'x', 'stored' => '1'])->status);
    }

    public function testABackupWithAPassphraseIsMadeInStepsAndTheRunKeepsNoTraceOfItAfterwards(): void
    {
        $page = $this->browser->get('/profiles/blog')->body;
        self::assertStringContainsString('name="passphrase"', $page);
        self::assertStringContainsString('data-suggest="#f-passphrase" data-alphabet="' . Passphrases::ALPHABET . '"', $page);

        $weak = $this->browser->post('/profiles/blog/backup', ['passphrase' => 'short']);
        self::assertSame(303, $weak->status);
        self::assertSame('/profiles/blog', $weak->header('Location'), 'no run was made');
        self::assertStringContainsString('is short', strtolower($this->browser->get('/profiles/blog')->body));
        self::assertSame([], $this->fixture->runs->all('backup'));

        $passphrase = Passphrases::generate();
        $start = $this->browser->post('/profiles/blog/backup', ['passphrase' => " $passphrase "]);
        self::assertSame(303, $start->status);
        $id = substr((string) $start->header('Location'), strlen('/runs/'));
        self::assertSame($passphrase, $this->fixture->runs->load($id)->state['passphrase'], 'the run needs it until it is done');

        for ($i = 0; $i < 50; ++$i) {
            $data = json_decode($this->browser->send('POST', "/runs/$id/tick", [], ['x-csrf-token' => $this->browser->csrf])->body, true);
            if (($data['status'] ?? '') !== 'running') {
                break;
            }
        }

        $run = $this->fixture->runs->load($id);
        self::assertSame('', $run->state['passphrase'], 'a finished run keeps nothing of it');
        self::assertStringNotContainsString($passphrase, (string) file_get_contents($this->fixture->data . '/runs/' . $id . '.json'));
        $entries = (new BackupCatalog($this->fixture->storage()))->entries('blog');
        self::assertCount(1, $entries);
        self::assertTrue($entries[0]->sidecar?->encryption['passphrase'] ?? false);
        self::assertFileDoesNotExist($this->fixture->storageDirectory . '/' . $entries[0]->name->base() . '.zip');
        self::assertFileExists($this->fixture->storageDirectory . '/' . $entries[0]->name->base() . '.zip.age');
    }

    public function testAnEncryptedBackupIsMarkedOnItsPagesAndIsDownloadedAsItIs(): void
    {
        $pair = AgeKeys::generate();
        $this->fixture->profiles->save($this->fixture->profiles->load('blog')->with(['encryption' => ['recipients' => [$pair['public']]]]));
        $name = $this->fixture->backup();
        $file = $name->base() . '.zip.age';
        $path = $this->fixture->storageDirectory . '/' . $file;
        self::assertFileExists($path);

        self::assertStringContainsString('(encrypted)', $this->browser->get('/profiles/blog')->body);
        $details = $this->browser->get('/profiles/blog/backups/' . $name->base());
        self::assertStringContainsString('format age: for 1 public key (' . substr($pair['public'], 0, 12) . '...' . substr($pair['public'], -6) . ')', $details->body);
        $dialog = $this->browser->get('/profiles/blog/backups/' . $name->base() . '/download');
        self::assertStringContainsString('Encrypted.', $dialog->body);

        $issue = $this->browser->post('/profiles/blog/backups/' . $name->base() . '/download', ['part' => $file]);
        self::assertSame(303, $issue->status, $issue->body);
        $response = $this->browser->get((string) $issue->header('Location'));
        self::assertSame(200, $response->status);
        self::assertSame('application/octet-stream', $response->header('Content-Type'));
        self::assertSame((string) filesize($path), $response->header('Content-Length'));
    }
}
