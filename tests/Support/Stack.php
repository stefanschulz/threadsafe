<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Support;

use PDO;

/**
 * Access to the Docker test stacks from inside the tools container (see .docker/ and tests/stack/).
 *
 * Both stacks answer on port 80 under their service names; the shop only responds normally when the request
 * carries the shop's own domain in the Host header, which is why requests take a separate host argument.
 */
final class Stack
{
    public const string SOURCE_URL = 'http://prestashop';
    public const string SOURCE_HOST = 'localhost:8190';
    public const string TARGET_URL = 'http://target-prestashop';
    public const string TARGET_HOST = 'localhost:8290';

    /** Integration tests only run when tests/stack/run.sh has prepared the stacks. */
    public static function enabled(): bool
    {
        return getenv('THREADSAFE_STACK') === '1';
    }

    public static function sourceDb(): PDO
    {
        return self::connect('db', 'prestashop', 'prestashop', self::env('DB_PASSWORD', 'prestashop'));
    }

    public static function targetDb(): PDO
    {
        return self::connect('target-db', 'prestashop_target', 'target', self::env('TARGET_DB_PASSWORD', 'target'));
    }

    /**
     * GET with a fixed Host header. Follows up to five redirects that stay on the same host.
     */
    public static function get(string $baseUrl, string $hostHeader, string $path = '/'): HttpResponse
    {
        $url = $baseUrl . $path;
        $response = new HttpResponse(0, [], '');

        for ($hop = 0; $hop <= 5; ++$hop) {
            $response = self::request($url, $hostHeader);
            $location = $response->header('location');
            if ($response->status < 300 || $response->status >= 400 || $location === null) {
                break;
            }
            $url = self::resolve($baseUrl, $hostHeader, $location);
            if ($url === null) {
                break;
            }
        }

        return $response;
    }

    private static function request(string $url, string $hostHeader): HttpResponse
    {
        $context = stream_context_create(['http' => [
            'method' => 'GET',
            'header' => "Host: $hostHeader\r\nUser-Agent: ThreadSafe-Tests\r\n",
            'follow_location' => 0,
            'ignore_errors' => true,
            'timeout' => 60,
        ]]);
        $body = @file_get_contents($url, false, $context);

        $status = 0;
        $headers = [];
        foreach ($http_response_header as $line) {
            if (preg_match('~^HTTP/\S+\s+(\d{3})~', $line, $m) === 1) {
                $status = (int) $m[1];
                $headers = [];
            } elseif (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($name))] = trim($value);
            }
        }

        return new HttpResponse($status, $headers, $body === false ? '' : $body);
    }

    /** The URL to request for a Location header, or null if it leads to another host. */
    private static function resolve(string $baseUrl, string $hostHeader, string $location): ?string
    {
        if (str_starts_with($location, '/')) {
            return $baseUrl . $location;
        }
        $parts = parse_url($location);
        if (!is_array($parts) || !isset($parts['host'])) {
            return null;
        }
        $authority = $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if (strcasecmp($authority, $hostHeader) !== 0) {
            return null;
        }

        return $baseUrl . ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    private static function connect(string $host, string $database, string $user, string $password): PDO
    {
        return new PDO(
            "mysql:host=$host;dbname=$database;charset=utf8mb4",
            $user,
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    }

    private static function env(string $name, string $default): string
    {
        $value = getenv($name);

        return $value === false || $value === '' ? $default : $value;
    }
}
