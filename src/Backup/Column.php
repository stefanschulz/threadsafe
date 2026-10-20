<?php

declare(strict_types=1);

namespace ThreadSafe\Backup;

/**
 * A column of a table as the dumper needs to know it.
 */
final readonly class Column
{
    /** Types whose cells can be larger than a statement may be; the others are read as they are. */
    private const array LARGE_TYPES = ['mediumblob', 'longblob', 'mediumtext', 'longtext'];

    public ColumnKind $kind;

    /** Cells of this column can be huge: they are measured first and read in pieces above a limit. */
    public bool $large;

    /**
     * @param string $type       DATA_TYPE of information_schema.COLUMNS
     * @param bool   $splittable a large column whose cells may be put together by `UPDATE ... CONCAT` (not a column that a
     *                           CHECK constraint holds to be valid JSON: a first piece alone would be refused)
     * @param bool   $onUpdate   the column changes by itself when its row is updated (`ON UPDATE CURRENT_TIMESTAMP`)
     */
    public function __construct(
        public string $name,
        public string $type,
        public bool $splittable = true,
        public bool $onUpdate = false,
    ) {
        $this->kind = ColumnKind::of($type);
        $this->large = in_array(strtolower($type), self::LARGE_TYPES, true);
    }

    /** A large column that is cut into pieces when a cell is above the limit. */
    public function isSplit(): bool
    {
        return $this->large && $this->splittable;
    }

    /** Text is cut by characters, bytes by bytes. */
    public function isText(): bool
    {
        return $this->kind === ColumnKind::Text;
    }

    /**
     * The values of the column come out of the query as hex digits (`HEX(column)`). Done for every binary column that is
     * not large: it makes BIT and geometry values look the same with both PHP interfaces. Large ones are read as they
     * are, because `HEX()` of a huge cell silently becomes NULL when the result exceeds `max_allowed_packet`.
     */
    public function hexInQuery(): bool
    {
        return $this->kind === ColumnKind::Binary && !$this->large;
    }
}
