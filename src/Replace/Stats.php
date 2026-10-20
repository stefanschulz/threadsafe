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

    /** Whether anything was found that a human may want to look at. */
    public function hasFindings(): bool
    {
        return $this->urlsReplaced + $this->bareFound + $this->bareReplaced + $this->emailsSkipped + $this->unparseable > 0;
    }
}
