<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Support;

use Closure;

/**
 * Test object with public, protected and private properties (their serialized names contain NUL bytes).
 */
final class Probe
{
    public function __construct(
        public string $pub,
        protected string $prot,
        private string $priv,
        public mixed $extra = null,
    ) {
    }

    /**
     * @param Closure(mixed): mixed $f
     */
    public function map(Closure $f): self
    {
        return new self((string) $f($this->pub), (string) $f($this->prot), (string) $f($this->privateValue()), $f($this->extra));
    }

    private function privateValue(): string
    {
        return $this->priv;
    }
}
