<?php

declare(strict_types=1);

namespace ThreadSafe\PrestaShop9\Steps;

use ThreadSafe\Core\Change;
use ThreadSafe\Core\ChangeKind;

/**
 * One value to set in the database: a cell identified by a key column, an old and a new value.
 *
 * @internal
 */
final readonly class ConfigurationOperation
{
    public function __construct(
        public string $table,
        public string $keyColumn,
        public int|string $keyValue,
        public string $column,
        public ?string $old,
        public string $new,
        public string $target,
        public string $description,
        public bool $domainValue,
        public bool $insert = false,
    ) {
    }

    public function change(): Change
    {
        return new Change($this->insert ? ChangeKind::Create : ChangeKind::Update, $this->target, $this->description, $this->old, $this->new);
    }
}
