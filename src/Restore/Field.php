<?php

declare(strict_types=1);

namespace ThreadSafe\Restore;

/**
 * One value a restore asks for beyond the common ones (a website type declares them, see {@see \ThreadSafe\Plugin\Restorable}); the
 * wizard draws a form control for it, the command line has an option of the same meaning.
 */
final readonly class Field
{
    public const string TEXT = 'text';

    public const string CHOICE = 'choice';

    /**
     * @param string       $kind    text or choice
     * @param list<string> $choices for a choice: the allowed values (the empty string means "leave as it is")
     * @param bool         $proposeHost the wizard starts a text field with the host it is served at (the new domain: a restore puts the site where the
     *                                  tool is, and an empty field would keep the old domain everywhere)
     */
    public function __construct(
        public string $name,
        public string $label,
        public string $kind = self::TEXT,
        public string $default = '',
        public array $choices = [],
        public bool $required = false,
        public string $help = '',
        public bool $proposeHost = false,
    ) {
    }
}
