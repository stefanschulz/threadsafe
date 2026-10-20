<?php

declare(strict_types=1);

namespace ThreadSafe\PrestaShop9\DevMode;

use ThreadSafe\Core\Change;

/**
 * One database modification of the dev mode, described well enough to show it in the plan and to perform and journal it.
 *
 * @internal
 */
final readonly class DevOperation
{
    public const string SET = 'set';

    public const string INSERT = 'insert';

    public const string DELETE = 'delete';

    public const string EMPTY_TABLE = 'empty-table';

    public const string DEACTIVATE_MODULE = 'deactivate-module';

    /**
     * @param string               $kind   one of the constants above
     * @param string               $target what the report shows, e.g. "db ps_configuration PS_MAIL_METHOD"
     * @param array<string, mixed> $data   what performing needs (ids, table names, old values)
     */
    private function __construct(
        public string $kind,
        public string $ruleId,
        public string $target,
        public string $description,
        public ?string $before,
        public ?string $after,
        public bool $secret,
        public int $count,
        public array $data,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function set(string $ruleId, string $name, int $id, ?string $old, string $new, bool $secret, string $target, string $description, array $data = []): self
    {
        return new self(self::SET, $ruleId, $target, $description, $old, $new, $secret, 1, ['id' => $id, 'name' => $name] + $data);
    }

    public static function insert(string $ruleId, string $name, string $value, string $target, string $description): self
    {
        return new self(self::INSERT, $ruleId, $target, $description, null, $value, false, 1, ['name' => $name]);
    }

    public static function delete(string $ruleId, string $name, int $id, ?string $old, string $target): self
    {
        return new self(self::DELETE, $ruleId, $target, 'remove the setting', $old, null, true, 1, ['id' => $id, 'name' => $name]);
    }

    public static function emptyTable(string $ruleId, string $table, int $rows): self
    {
        return new self(self::EMPTY_TABLE, $ruleId, "db $table", 'empty the table', null, null, false, $rows, ['table' => $table]);
    }

    /**
     * @param list<int> $shopIds the shops the module is still enabled for (module_shop rows)
     */
    public static function deactivateModule(string $ruleId, string $module, int $id, bool $active, array $shopIds, string $table): self
    {
        return new self(
            self::DEACTIVATE_MODULE,
            $ruleId,
            "module $module",
            'deactivate the module',
            $active ? 'active' : 'inactive',
            'inactive',
            false,
            1,
            ['id' => $id, 'name' => $module, 'active' => $active, 'shopIds' => $shopIds, 'table' => $table],
        );
    }

    public function change(): Change
    {
        return match ($this->kind) {
            self::INSERT => Change::create($this->target, $this->description, $this->after, secret: $this->secret),
            self::DELETE => Change::delete($this->target, $this->description, $this->before, secret: $this->secret),
            self::EMPTY_TABLE => Change::delete($this->target, $this->description, count: $this->count),
            default => Change::update($this->target, $this->description, $this->before, $this->after, secret: $this->secret),
        };
    }
}
