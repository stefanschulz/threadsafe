<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Replace;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Migration\ContentRewriteRun;
use ThreadSafe\Replace\TableRewriteResult;

/**
 * The pieces that let the content replacement continue in another process: results that can be added up and kept as data,
 * and primary keys that survive JSON.
 */
final class TableRewriteResultTest extends TestCase
{
    private function tableResult(int $changed, int $urls, ?string $before): TableRewriteResult
    {
        $result = new TableRewriteResult('ps_cms');
        $result->rowsExamined = $changed + 1;
        $result->rowsChanged = $changed;
        $column = $result->column('content');
        $column->rowsChanged = $changed;
        $column->stats->urlsReplaced = $urls;
        $column->stats->bareFound = 1;
        $column->sampleBefore = $before;
        $column->sampleAfter = $before === null ? null : 'after ' . $before;

        return $result;
    }

    public function testPartsOfATableAddUpAndKeepTheFirstSample(): void
    {
        $first = $this->tableResult(3, 5, 'first sample');
        $first->merge($this->tableResult(2, 4, 'second sample'));

        self::assertSame(5, $first->rowsChanged);
        self::assertSame(7, $first->rowsExamined);
        self::assertSame(5, $first->columns['content']->rowsChanged);
        self::assertSame(9, $first->columns['content']->stats->urlsReplaced);
        self::assertSame(2, $first->columns['content']->stats->bareFound);
        self::assertSame('first sample', $first->columns['content']->sampleBefore);
        self::assertSame('after first sample', $first->columns['content']->sampleAfter);
    }

    public function testAColumnThatAppearsLaterIsAdded(): void
    {
        $first = $this->tableResult(1, 1, 'x');
        $other = new TableRewriteResult('ps_cms');
        $other->column('title')->rowsChanged = 2;
        $first->merge($other);

        self::assertSame(['content', 'title'], array_keys($first->columns));
    }

    public function testAResultSurvivesJson(): void
    {
        $result = $this->tableResult(3, 5, "sample with \"quotes\" and ü");
        $result->candidatesWithoutKey = 4;

        $again = TableRewriteResult::fromArray(json_decode(json_encode($result->toArray(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR));

        self::assertSame($result->toArray(), $again->toArray());
        self::assertSame(4, $again->candidatesWithoutKey);
    }

    public function testKeysSurviveJsonEvenIfTheyAreNotText(): void
    {
        $key = [7, 'text key ü', "\xff\x00binary", null, 1.5];

        $encoded = json_decode(json_encode(ContentRewriteRun::encodeKey($key), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame($key, ContentRewriteRun::decodeKey($encoded));
        self::assertNull(ContentRewriteRun::decodeKey(null));
    }
}
