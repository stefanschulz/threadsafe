<?php

declare(strict_types=1);

namespace ThreadSafe\Replace;

/**
 * Counters collected while rewriting values. Mutable on purpose: one instance is passed through a whole run.
 */
final class Stats
{
    /** URL forms whose host was replaced. */
    public int $urlsReplaced = 0;

    /** Bare domain names (no URL form) found and left untouched. */
    public int $bareFound = 0;

    /** Bare domain names that were replaced because replacing bare names was allowed. */
    public int $bareReplaced = 0;

    /** Occurrences after "@" (e-mail addresses) that were deliberately left untouched. */
    public int $emailsSkipped = 0;

    /** Values that looked structured (serialized/JSON) and contained the domain but could not be parsed; left untouched. */
    public int $unparseable = 0;

    public function add(self $other): void
    {
        $this->urlsReplaced += $other->urlsReplaced;
        $this->bareFound += $other->bareFound;
        $this->bareReplaced += $other->bareReplaced;
        $this->emailsSkipped += $other->emailsSkipped;
        $this->unparseable += $other->unparseable;
    }

    /** @return array<string, int> */
    public function toArray(): array
    {
        return ['urlsReplaced' => $this->urlsReplaced, 'bareFound' => $this->bareFound, 'bareReplaced' => $this->bareReplaced, 'emailsSkipped' => $this->emailsSkipped, 'unparseable' => $this->unparseable];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $stats = new self();
        $stats->urlsReplaced = (int) ($data['urlsReplaced'] ?? 0);
        $stats->bareFound = (int) ($data['bareFound'] ?? 0);
        $stats->bareReplaced = (int) ($data['bareReplaced'] ?? 0);
        $stats->emailsSkipped = (int) ($data['emailsSkipped'] ?? 0);
        $stats->unparseable = (int) ($data['unparseable'] ?? 0);

        return $stats;
    }

    /** Whether anything was found that a human may want to look at. */
    public function hasFindings(): bool
    {
        return $this->urlsReplaced + $this->bareFound + $this->bareReplaced + $this->emailsSkipped + $this->unparseable > 0;
    }
}
