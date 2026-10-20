<?php

declare(strict_types=1);

namespace ThreadSafe\PrestaShop9\DevMode;

use InvalidArgumentException;
use JsonException;
use ThreadSafe\Core\Resources;
use ThreadSafe\Migration\MigrationRequest;

/**
 * The rules of the dev mode: the ones shipped in plugins/prestashop9/resources/dev-mode.json plus an optional file of the
 * user (same format; a rule with a known id replaces the shipped one, a new id adds a rule).
 *
 * Rule files are data, not code: they can only set, clear and remove configuration values, empty tables, deactivate
 * modules and keep search engines out.
 */
final readonly class DevModeRules
{
    /**
     * @param array<string, DevRule> $rules        by id, in order
     * @param list<string>           $warnPatterns LIKE patterns of configuration names that look like credentials
     * @param list<string>           $warnIgnore   names that match a pattern but are harmless
     */
    private function __construct(
        private array $rules,
        public array $warnPatterns,
        public int $warnMinLength,
        public array $warnIgnore,
    ) {
    }

    /**
     * @throws InvalidArgumentException the user's rule file cannot be read or is invalid
     */
    public static function load(?string $extraFile = null): self
    {
        $base = Resources::json('prestashop9/dev-mode.json');
        $rules = [];
        foreach (self::ruleList($base, 'dev-mode.json') as $rule) {
            $rules[$rule->id] = $rule;
        }

        if ($extraFile !== null) {
            $content = is_file($extraFile) ? file_get_contents($extraFile) : false;
            if ($content === false) {
                throw new InvalidArgumentException("The dev rules file \"$extraFile\" cannot be read.");
            }
            try {
                $extra = json_decode($content, true, 16, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                throw new InvalidArgumentException('The dev rules file is not valid JSON: ' . $e->getMessage(), 0, $e);
            }
            if (!is_array($extra)) {
                throw new InvalidArgumentException('The dev rules file must contain a JSON object.');
            }
            foreach (self::ruleList($extra, 'the dev rules file') as $rule) {
                $rules[$rule->id] = $rule;
            }
        }

        $minLength = $base['warnMinLength'] ?? 12;

        return new self(
            $rules,
            self::strings($base['warnPatterns'] ?? [], 'warnPatterns'),
            is_int($minLength) ? $minLength : 12,
            self::strings($base['warnIgnore'] ?? [], 'warnIgnore'),
        );
    }

    /**
     * The rules the request wants applied: all but the skipped ones.
     *
     * @param list<string> $modules modules that have a module rule (their `module:<name>` ids may be skipped)
     *
     * @return list<DevRule>
     *
     * @throws InvalidArgumentException a skipped id is unknown
     */
    public function activeFor(MigrationRequest $request, array $modules = []): array
    {
        $this->assertKnown($request->devSkip, $modules);

        return array_values(array_filter($this->rules, static fn (DevRule $rule): bool => !in_array($rule->id, $request->devSkip, true)));
    }

    /**
     * @param list<string> $ids
     * @param list<string> $modules modules that have a module rule; `module:<name>` of these may be skipped too
     *
     * @throws InvalidArgumentException
     */
    public function assertKnown(array $ids, array $modules = []): void
    {
        foreach ($ids as $id) {
            if (!isset($this->rules[$id]) && !(str_starts_with($id, 'module:') && in_array(substr($id, 7), $modules, true))) {
                throw new InvalidArgumentException(sprintf('Unknown dev rule "%s" (known: %s).', $id, implode(', ', [...array_keys($this->rules), ...array_map(static fn (string $m): string => "module:$m", $modules)])));
            }
        }
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        return array_keys($this->rules);
    }

    /**
     * @param array<mixed> $data
     *
     * @return list<DevRule>
     */
    private static function ruleList(array $data, string $origin): array
    {
        $list = $data['rules'] ?? null;
        if (!is_array($list)) {
            throw new InvalidArgumentException("$origin has no list \"rules\".");
        }

        $rules = [];
        foreach ($list as $entry) {
            if (!is_array($entry) || !is_string($entry['id'] ?? null) || preg_match('/^[a-z][a-z0-9-]*$/', $entry['id']) !== 1) {
                throw new InvalidArgumentException("Each rule in $origin needs an \"id\" of lower-case letters, digits and hyphens.");
            }
            $id = $entry['id'];
            $known = ['id', 'title', 'description', 'set', 'clear', 'remove', 'emptyTables', 'deactivateModules', 'noindex'];
            foreach (array_keys($entry) as $key) {
                if (!in_array($key, $known, true)) {
                    throw new InvalidArgumentException("Rule \"$id\" in $origin has an unknown key \"$key\".");
                }
            }

            $set = [];
            $rawSet = $entry['set'] ?? [];
            foreach (is_array($rawSet) ? $rawSet : [] as $value) {
                $when = is_array($value) ? ($value['when'] ?? null) : null;
                if (!is_array($value) || !is_string($value['name'] ?? null) || !is_string($value['value'] ?? null)
                    || ($when !== null && !in_array($when, ['mailCatcher', 'noMailCatcher'], true))) {
                    throw new InvalidArgumentException("Rule \"$id\" in $origin has an invalid \"set\" entry (needs name and value; when is mailCatcher or noMailCatcher).");
                }
                $set[] = ['name' => $value['name'], 'value' => $value['value'], 'when' => $when, 'create' => ($value['create'] ?? false) === true];
            }

            $noindex = null;
            if (isset($entry['noindex'])) {
                $raw = $entry['noindex'];
                if (!is_array($raw) || !is_string($raw['robots'] ?? null) || !is_string($raw['header'] ?? null)) {
                    throw new InvalidArgumentException("Rule \"$id\" in $origin has an invalid \"noindex\" (needs robots and header).");
                }
                $noindex = ['robots' => $raw['robots'], 'header' => $raw['header']];
            }

            $rules[] = new DevRule(
                $id,
                is_string($entry['title'] ?? null) ? $entry['title'] : $id,
                is_string($entry['description'] ?? null) ? $entry['description'] : '',
                $set,
                self::strings($entry['clear'] ?? [], "$id.clear"),
                self::strings($entry['remove'] ?? [], "$id.remove"),
                self::strings($entry['emptyTables'] ?? [], "$id.emptyTables"),
                self::strings($entry['deactivateModules'] ?? [], "$id.deactivateModules"),
                $noindex,
            );
        }

        return $rules;
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $value, string $what): array
    {
        if (!is_array($value) || array_filter($value, static fn (mixed $v): bool => !is_string($v) || $v === '') !== []) {
            throw new InvalidArgumentException("\"$what\" must be a list of non-empty strings.");
        }

        return array_values($value);
    }
}
