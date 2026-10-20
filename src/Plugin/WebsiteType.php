<?php

declare(strict_types=1);

namespace ThreadSafe\Plugin;

use ThreadSafe\Cli\Input;
use ThreadSafe\Cli\OptionSpec;
use ThreadSafe\Core\Step;
use ThreadSafe\Migration\RequestBuilder;

/**
 * A website type: everything ThreadSafe knows about one kind of application (PrestaShop 9, "any website" ...). The entry
 * class of a plugin implements it. Internal interface: it may change whenever the program needs it to.
 */
interface WebsiteType
{
    /** Stable identifier, equal to the plugin id: the word after `migrate` on the command line. */
    public function id(): string;

    /** Human-readable name, e.g. "PrestaShop 9". */
    public function title(): string;

    /**
     * The command line options only this type has (the common ones - root, domains, database, self-test ... - belong to
     * the command).
     *
     * @return list<OptionSpec>
     */
    public function options(): array;

    /**
     * The request settings that follow from this type's options, under the names {@see RequestBuilder} knows.
     *
     * @return array<string, mixed>
     */
    public function settings(Input $input): array;

    public function requestBuilder(): RequestBuilder;

    /**
     * The steps of a migration, in order.
     *
     * @return list<Step>
     */
    public function steps(): array;
}
