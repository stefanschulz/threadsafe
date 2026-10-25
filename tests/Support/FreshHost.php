<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Support;

use RuntimeException;

/**
 * The host with nothing on it (compose service `fresh`, docs/PHASE7.md 4.2) and a browser for it. The tools container mounts its /var/www as
 * /shops/fresh, so the tests can put the installer there and look at what the installation made; everything else goes over HTTP, through
 * Apache with the limits of 5 seconds and 64 MB, as a person's browser would. The requests are sent with the host name `localhost` (port 80 inside
 * the container), because the update's health check asks the installation's own address from inside the host.
 */
final class FreshHost
{
    public const string URL = 'http://fresh';

    public const string HOST = 'localhost';

    /** The /var/www of the host, as the tools container sees it. */
    public const string WWW = '/shops/fresh';

    public const string KEY = 'a long secret key for the fresh host';

    private string $jar;

    private string $csrf = '';

    public function __construct()
    {
        $this->jar = sys_get_temp_dir() . '/threadsafe-fresh-jar-' . bin2hex(random_bytes(4));
    }

    public function __destruct()
    {
        @unlink($this->jar);
    }

    /** Whether the stack has the host and it answers. */
    public static function available(): bool
    {
        if (!is_dir(self::WWW . '/html') || !function_exists('curl_init')) {
            return false;
        }
        $curl = curl_init(self::URL . '/');
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_HTTPHEADER => ['Host: ' . self::HOST]]);
        curl_exec($curl);
        $code = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

        return $code > 0;
    }

    /** Back to a host with nothing on it: an empty document root, no home, no files of an earlier test. */
    public static function reset(): void
    {
        foreach (['html', 'threadsafe'] as $name) {
            exec('rm -rf ' . escapeshellarg(self::WWW . '/' . $name) . ' 2>&1', $ignored, $code);
            if ($code !== 0) {
                throw new RuntimeException("Cannot empty " . self::WWW . "/$name.");
            }
        }
        mkdir(self::WWW . '/html', 0755);
        exec('chown www-data:www-data ' . escapeshellarg(self::WWW . '/html') . ' 2>&1');
    }

    /** Puts the installer and its key file into the document root, as a person does with FTP. */
    public static function upload(string $installer, bool $key = true): void
    {
        copy($installer, self::WWW . '/html/install.php');
        if ($key) {
            file_put_contents(self::WWW . '/html/install-key.php', "<?php return '" . self::KEY . "';");
        }
        foreach (glob(self::WWW . '/html/install*.php') ?: [] as $file) {
            chmod($file, 0644);
        }
    }

    /**
     * @param array<string, string>                   $fields
     * @param array<string, array{string, string}>    $files  field => [path, name]
     * @param array<string, string>                   $headers
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public function request(string $method, string $path, array $fields = [], array $files = [], array $headers = []): array
    {
        $curl = curl_init(self::URL . $path);
        $sent = ['Host: ' . self::HOST];
        foreach ($headers as $name => $value) {
            $sent[] = "$name: $value";
        }
        $response = [];
        /** @var array<int, mixed> $options */
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_COOKIEJAR => $this->jar,
            CURLOPT_COOKIEFILE => $this->jar,
            CURLOPT_HTTPHEADER => $sent,
            CURLOPT_USERAGENT => 'ThreadSafe-Tests',
        ];
        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $post = $fields;
            foreach ($files as $field => [$file, $name]) {
                $post[$field] = new \CURLFile($file, 'application/zip', $name);
            }
            $options[CURLOPT_POSTFIELDS] = $files === [] ? http_build_query($fields) : $post;
        }
        foreach ($options as $option => $value) {
            curl_setopt($curl, $option, $value);
        }
        $raw = curl_exec($curl);
        if ($raw === false) {
            throw new RuntimeException('No answer from ' . self::URL . $path . ': ' . curl_error($curl));
        }
        $size = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
        $response['status'] = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $response['headers'] = [];
        foreach (explode("\r\n", substr((string) $raw, 0, $size)) as $line) {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $response['headers'][strtolower(trim($name))] = trim($value);
            }
        }
        $response['body'] = substr((string) $raw, $size);
        if (preg_match('/name="csrf" value="([0-9a-f]+)"/', $response['body'], $m) === 1) {
            $this->csrf = $m[1];
        } elseif (preg_match('/<meta name="csrf-token" content="([^"]+)"/', $response['body'], $m) === 1) {
            $this->csrf = $m[1];
        }

        return $response;
    }

    /**
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public function get(string $path): array
    {
        return $this->request('GET', $path);
    }

    /** The token of the last page that had one: the installer's `csrf` field, or the `csrf-token` of the interface. */
    public function csrf(): string
    {
        return $this->csrf;
    }

    /**
     * A POST of the installer's wizard (the token of the session goes with it).
     *
     * @param array<string, string> $fields
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public function installer(array $fields): array
    {
        return $this->request('POST', '/install.php', $fields + ['csrf' => $this->csrf]);
    }

    /**
     * A POST to the interface, with the token in the header as its script sends it.
     *
     * @param array<string, string>                $fields
     * @param array<string, array{string, string}> $files
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public function interface(string $path, array $fields = [], array $files = []): array
    {
        return $this->request('POST', $path, $fields + ['csrf' => $this->csrf], $files, ['X-CSRF-Token' => $this->csrf]);
    }

    /** Gives what the tests made (as root) to the web server's user again, as it would be if the installation had made it. */
    public static function ownedByTheWebServer(string $path): void
    {
        exec('chown -R www-data:www-data ' . escapeshellarg($path) . ' 2>&1');
    }

    /** What the installation wrote, as the tools container sees it. */
    public static function home(): string
    {
        return self::WWW . '/threadsafe';
    }
}
