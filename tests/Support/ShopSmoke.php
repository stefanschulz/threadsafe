<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Support;

use PDO;

/**
 * The pages a restored PrestaShop must serve (home page, a category, a product, a product image, the contact page, the back office
 * login), asked for over HTTP from the tools container, and what they answered - so that a shop can be compared with itself before it
 * was destroyed.
 */
final class ShopSmoke
{
    /**
     * @return array<string, string> name => path; the ids come from the shop's own database
     */
    public static function paths(PDO $db, string $prefix = 'ps_'): array
    {
        $product = (string) $db->query("SELECT id_product FROM {$prefix}product ORDER BY id_product LIMIT 1")?->fetchColumn();
        $image = (string) $db->query("SELECT CONCAT('/', i.id_image, '-', (SELECT name FROM {$prefix}image_type WHERE products = 1 ORDER BY id_image_type LIMIT 1), '/', pl.link_rewrite, '.jpg')
            FROM {$prefix}image i JOIN {$prefix}product_lang pl ON pl.id_product = i.id_product AND pl.id_lang = 1 ORDER BY i.id_image LIMIT 1")?->fetchColumn();

        return [
            'home page' => '/',
            'category' => '/index.php?id_category=2&controller=category',
            'product' => '/index.php?id_product=' . $product . '&controller=product',
            'product image' => $image,
            'contact' => '/index.php?controller=contact',
            'back office login' => '/admin-dev/',
        ];
    }

    /**
     * @param array<string, string> $paths
     *
     * @return array<string, array{status: int, type: string, title: string}>
     */
    public static function visit(string $baseUrl, string $host, array $paths): array
    {
        $result = [];
        foreach ($paths as $name => $path) {
            $response = Stack::get($baseUrl, $host, $path);
            $title = preg_match('~<title>(.*?)</title>~is', $response->body, $m) === 1 ? trim($m[1]) : '';
            $result[$name] = ['status' => $response->status, 'type' => (string) $response->header('content-type'), 'title' => $title];
        }

        return $result;
    }

    private function __construct()
    {
    }
}
