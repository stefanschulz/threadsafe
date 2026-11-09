<?php

declare(strict_types=1);

namespace ThreadSafe\PrestaShop9\Steps;

use ThreadSafe\Core\AlwaysRuns;
use ThreadSafe\Core\Context;
use ThreadSafe\Core\Exception\SelfTestFailed;
use ThreadSafe\Core\Notice;
use ThreadSafe\Core\Plan;
use ThreadSafe\Core\StepResult;
use ThreadSafe\Database\Connection;
use ThreadSafe\Http\HttpClient;
use ThreadSafe\Http\HttpResult;
use ThreadSafe\Http\NameLookup;
use ThreadSafe\PrestaShop9\ShopAddress;
use ThreadSafe\PrestaShop9\State;
use ThreadSafe\Replace\Stats;
use ThreadSafe\Replace\UrlMatcher;

/**
 * Proves that the migrated shop really serves: requests the home page, a category, a product, a product image through
 * its friendly URL (it only works with a correct `.htaccess`), the contact and login pages and the back office login
 * page over HTTP, follows redirects on the shop's own host and fails on a redirect loop, a redirect to another host,
 * a bad status, or links to the old domain on the home page.
 *
 * Runs after a real migration only (a dry run has no result to test). Any failed check ends the run with exit code 4;
 * the migration itself stays applied. The shop is requested at its own domain (https if the shop uses https
 * everywhere) or at `selfTestUrl`, the address the web server answers on when that differs from the shop's domain
 * (e.g. another container); the shop's domain is always sent as Host header.
 */
final class SelfTest implements AlwaysRuns
{
    public function __construct(private readonly ?HttpClient $client = null)
    {
    }

    public function id(): string
    {
        return 'self-test';
    }

    public function title(): string
    {
        return 'Check that the migrated shop answers';
    }

    public function plan(Context $context): Plan
    {
        $plan = new Plan();
        $request = State::request($context);
        if (!$request->selfTest) {
            return $plan->notice(Notice::warning('The self-test is switched off: nothing proves that the migrated shop serves.'));
        }

        $target = $this->target($context);

        return $plan->notice(Notice::info(sprintf('After the migration the shop is requested at %s (Host: %s) and must answer.', $target['base'] . $target['prefix'] . '/', $target['host'])));
    }

    public function apply(Context $context, Plan $plan): StepResult
    {
        $request = State::request($context);
        if (!$request->selfTest) {
            return new StepResult(0, [Notice::warning('The self-test is switched off.')]);
        }

        $client = $this->client ?? new HttpClient($request->selfTestInsecure);
        $target = $this->target($context);
        $notices = [];
        $failures = [];
        $asked = false;

        foreach ($this->checks($context, $target) as $check) {
            if (isset($check['skip'])) {
                $notices[] = Notice::warning('Not checked - ' . $check['skip'], $check['name']);
                continue;
            }
            $started = microtime(true);
            $result = $client->get($target['base'] . $target['prefix'] . $check['path'], $target['host'], $target['pinned']);
            if (!$target['pinned'] && !$asked && NameLookup::failed($result)) {
                // the first request could not even look up the name: the others cannot either, and each would wait for its timeout
                return new StepResult(0, [...$notices, Notice::warning(NameLookup::warning($target['host']))]);
            }
            $asked = true;
            $problem = $this->judge($result, $check, $request->urlMatcher());
            $label = sprintf('%s (%s)', $check['name'], $check['path']);
            if ($problem === null) {
                $context->logger->info(sprintf('self-test ok: %s in %.1fs', $label, microtime(true) - $started));
                $notices[] = Notice::info(sprintf('ok: %s - HTTP %d', $label, $result->status));
            } else {
                $context->logger->error("self-test FAILED: $label - $problem");
                $failures[] = "$label: $problem";
            }
        }

        if ($failures !== []) {
            throw new SelfTestFailed(sprintf('The migrated shop does not work (%d check(s) failed): %s', count($failures), implode('; ', $failures)));
        }

        return new StepResult(0, $notices);
    }

    /**
     * Where and how the shop is requested.
     *
     * @return array{base: string, prefix: string, host: string, pinned: bool}
     */
    private function target(Context $context): array
    {
        $address = ShopAddress::of($context);
        $explicit = State::request($context)->selfTestUrl;

        return [
            'base' => $explicit !== null ? rtrim($explicit, '/') : $address->origin(),
            'prefix' => $address->prefix,
            'host' => $address->host,
            'pinned' => $explicit !== null,
        ];
    }

    /**
     * @param array{base: string, prefix: string, host: string, pinned: bool} $target
     *
     * @return list<array{name: string, path: string, kind: string, skip?: string}>
     */
    private function checks(Context $context, array $target): array
    {
        $db = State::connection($context);
        $source = State::source($context);
        $checks = [['name' => 'home page', 'path' => '/', 'kind' => 'home']];

        $category = $this->homeCategory($db);
        $checks[] = $category === null
            ? ['name' => 'category page', 'path' => '', 'kind' => 'page', 'skip' => 'the shop has no home category']
            : ['name' => 'category page', 'path' => "/index.php?id_category=$category&controller=category", 'kind' => 'page'];

        $product = $this->firstProduct($db);
        if ($product === null) {
            $checks[] = ['name' => 'product page', 'path' => '', 'kind' => 'page', 'skip' => 'the shop has no active product'];
            $checks[] = ['name' => 'product image', 'path' => '', 'kind' => 'image', 'skip' => 'the shop has no product image'];
        } else {
            $checks[] = ['name' => 'product page', 'path' => "/index.php?id_product={$product['id']}&controller=product", 'kind' => 'page'];
            $checks[] = $product['image'] === null
                ? ['name' => 'product image', 'path' => '', 'kind' => 'image', 'skip' => 'the product has no image']
                : (is_file($source->root . '/.htaccess')
                    ? ['name' => 'product image', 'path' => $product['image'], 'kind' => 'image']
                    : ['name' => 'product image', 'path' => '', 'kind' => 'image', 'skip' => 'there is no .htaccess (friendly image URLs need the web server rules)']);
        }

        $checks[] = ['name' => 'contact page', 'path' => '/index.php?controller=contact', 'kind' => 'page'];
        $checks[] = ['name' => 'login page', 'path' => '/index.php?controller=authentication', 'kind' => 'page'];
        $checks[] = $source->adminDirectory === null
            ? ['name' => 'back office login', 'path' => '', 'kind' => 'page', 'skip' => 'the back office folder was not found']
            : ['name' => 'back office login', 'path' => '/' . rawurlencode($source->adminDirectory) . '/', 'kind' => 'page'];

        return $checks;
    }

    /**
     * @param array{name: string, path: string, kind: string} $check
     */
    private function judge(HttpResult $result, array $check, ?UrlMatcher $oldDomains): ?string
    {
        if ($result->problem !== null) {
            return $result->problem;
        }
        if ($result->status !== 200) {
            return 'HTTP ' . $result->status;
        }
        if ($check['kind'] === 'image' && !str_starts_with((string) $result->header('content-type'), 'image/')) {
            return 'answered 200 but not with an image (' . ($result->header('content-type') ?? 'no content type') . ')';
        }
        if ($check['kind'] === 'home') {
            if (!str_contains($result->body, '</html>')) {
                return 'the page is incomplete (no closing </html>), probably a PHP error';
            }

            return $this->oldDomainLinks($result->body, $oldDomains);
        }

        return null;
    }

    /** Links to the old domain on the home page mean that something was missed by the replacement. */
    private function oldDomainLinks(string $body, ?UrlMatcher $oldDomains): ?string
    {
        if ($oldDomains === null) {
            return null;
        }
        $stats = new Stats();
        $oldDomains->rewrite($body, false, $stats);

        return $stats->urlsReplaced > 0 ? sprintf('%d link(s) on the page still point to the old domain', $stats->urlsReplaced) : null;
    }

    private function homeCategory(Connection $db): ?int
    {
        $id = $db->scalar('SELECT value FROM ' . $db->quote($db->table('configuration')) . " WHERE name = 'PS_HOME_CATEGORY' ORDER BY id_configuration LIMIT 1");

        return is_numeric($id) && (int) $id > 0 ? (int) $id : null;
    }

    /**
     * @return array{id: int, image: string|null}|null the first active product and the friendly URL path of its first image
     */
    private function firstProduct(Connection $db): ?array
    {
        $product = $db->table('product');
        $row = $db->select("SELECT id_product FROM {$db->quote($product)} WHERE active = 1 ORDER BY id_product LIMIT 1")[0] ?? null;
        if ($row === null) {
            return null;
        }
        $id = (int) $row['id_product'];

        $image = $db->select(
            "SELECT i.id_image, pl.link_rewrite, (SELECT t.name FROM {$db->quote($db->table('image_type'))} t WHERE t.products = 1 ORDER BY t.id_image_type LIMIT 1) AS type
             FROM {$db->quote($db->table('image'))} i
             JOIN {$db->quote($db->table('product_lang'))} pl ON pl.id_product = i.id_product
             WHERE i.id_product = ? ORDER BY i.position, i.id_image, pl.id_lang LIMIT 1",
            [$id],
        )[0] ?? null;
        if ($image === null || !is_string($image['type']) || $image['link_rewrite'] === '') {
            return ['id' => $id, 'image' => null];
        }

        return ['id' => $id, 'image' => sprintf('/%d-%s/%s.jpg', (int) $image['id_image'], $image['type'], $image['link_rewrite'])];
    }
}
