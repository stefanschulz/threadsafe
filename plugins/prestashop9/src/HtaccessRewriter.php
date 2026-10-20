<?php

declare(strict_types=1);

namespace ThreadSafe\PrestaShop9;

use ThreadSafe\Replace\DomainMapping;

/**
 * Adapts the PrestaShop-managed part of an `.htaccess` (between `# ~~start~~` and `# ~~end~~`) to a new domain and
 * base path, the way PrestaShop itself would regenerate it (`Tools::generateHtaccess`, verified against it by the
 * integration tests). Everything outside the markers belongs to the shop owner and is not touched.
 *
 * Lines that depend on the domain or the path:
 *   #Domain: <domain>
 *   RewriteCond %{HTTP_HOST} ^<domain>$            (before the image rewrites; may end in [OR])
 *   RewriteRule . - [E=REWRITEBASE:<base path>]    (every other rule uses the variable)
 *   ErrorDocument 404 <base path>index.php?controller=404
 */
final class HtaccessRewriter
{
    private const string BLOCK = '/# ~~start~~.*# ~~end~~[^\n]*/s';

    /**
     * @param list<DomainMapping> $mappings
     * @param string|null         $newBasePath new physical URI, null = unchanged
     */
    public function __construct(
        private readonly array $mappings,
        private readonly ?string $newBasePath,
    ) {
    }

    public function rewrite(string $content): HtaccessRewrite
    {
        if (preg_match(self::BLOCK, $content, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return new HtaccessRewrite($content, 0, ['The .htaccess has no PrestaShop markers (# ~~start~~ … # ~~end~~); it was not changed.']);
        }

        [$block, $offset] = $match[0];
        $warnings = [];
        $lines = explode("\n", $block);
        $changed = 0;
        $touched = false;
        /** @var list<string> $oldPaths base paths replaced so far */
        $oldPaths = [];

        foreach ($lines as $index => $line) {
            $eol = str_ends_with($line, "\r") ? "\r" : '';
            $text = $eol === '' ? $line : substr($line, 0, -1);
            $new = $text;

            if (preg_match('/^#Domain: (.+)$/', $text, $m) === 1) {
                $mapping = $this->mappingFor($m[1]);
                $touched = $mapping !== null || $this->mappings === [];
                $new = '#Domain: ' . ($mapping->to ?? $m[1]);
            } elseif (preg_match('/^(RewriteCond %\{HTTP_HOST\} \^)(.+?)(\$.*)$/', $text, $m) === 1) {
                $mapping = $this->mappingFor($m[2]);
                $new = $m[1] . ($mapping->to ?? $m[2]) . $m[3];
            } elseif (preg_match('/^RewriteRule \. - \[E=REWRITEBASE:(.*)\]$/', $text, $m) === 1) {
                if ($this->newBasePath !== null && $touched && $m[1] !== $this->newBasePath) {
                    $oldPaths[] = $m[1];
                    $new = 'RewriteRule . - [E=REWRITEBASE:' . $this->newBasePath . ']';
                }
            } elseif ($this->newBasePath !== null && preg_match('/^ErrorDocument 404 (.*)index\.php\?controller=404$/', $text, $m) === 1 && in_array($m[1], $oldPaths, true)) {
                $new = 'ErrorDocument 404 ' . $this->newBasePath . 'index.php?controller=404';
            } elseif ($this->newBasePath !== null && preg_match('/^RewriteRule \^[^ ]+ \S*index\.php \[L,R\]$/', $text) === 1) {
                $warnings[] = 'The .htaccess contains rules for virtual shop URLs (multistore); their base path was not changed.';
            }

            if ($new !== $text) {
                ++$changed;
                $lines[$index] = $new . $eol;
            }
        }

        $rewritten = substr($content, 0, $offset) . implode("\n", $lines) . substr($content, $offset + strlen($block));
        $outside = substr($content, 0, $offset) . substr($content, $offset + strlen($block));
        foreach ($this->mappings as $mapping) {
            if (stripos($outside, $mapping->fromHost()) !== false) {
                $warnings[] = "The .htaccess mentions \"{$mapping->fromHost()}\" outside the PrestaShop markers; custom rules are not changed and may need a manual update.";
            }
        }

        return new HtaccessRewrite($rewritten, $changed, array_values(array_unique($warnings)));
    }

    private function mappingFor(string $domain): ?DomainMapping
    {
        $domain = strtolower($domain);
        foreach ($this->mappings as $mapping) {
            if ($mapping->from === $domain) {
                return $mapping;
            }
        }

        return null;
    }
}
