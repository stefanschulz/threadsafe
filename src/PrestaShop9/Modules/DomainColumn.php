<?php

declare(strict_types=1);

namespace ThreadSafe\PrestaShop9\Modules;

/**
 * A column a module rule knows more about than the generic engine can find out: which format it holds and whether it
 * holds a bare host name.
 */
final readonly class DomainColumn
{
    public const array FORMS = ['plain', 'serialized', 'json'];

    /**
     * @param string      $table  without the table prefix
     * @param string|null $form   plain | serialized | json; null = the engine detects it
     * @param bool        $bare   the column holds bare domain names, which are replaced too
     */
    public function __construct(
        public string $table,
        public string $column,
        public ?string $form = null,
        public bool $bare = false,
    ) {
    }
}
