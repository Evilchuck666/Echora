<?php

namespace App\Ordering;

use App\Doctrine\SqlValue;
use Doctrine\DBAL\Connection;

/**
 * A manual order kept in a table, within a scope: the items of a playlist (playlist_item, by playlist_id) or the
 * playlists of a user (playlist, by owner_id).
 *
 * Each row has a sort key: a BIGINT, positive (a CHECK says so) and unique in its scope (a unique index), never
 * exposed. Keys leave gaps of STEP, so that placing a row changes its key alone: at the end, the last key plus STEP;
 * at the start, half the first key; elsewhere, the middle of its new neighbours' keys. When two neighbours' keys
 * touch, the scope is renumbered first. Removing a row changes no other key. Positions (indexes from 0) are derived
 * from the order, so they stay consecutive whatever is removed.
 *
 * Callers lock the scope first (in a transaction), so that two requests don't read the same keys and write clashing
 * ones. Table and column names come from the code, never from a request.
 */
final readonly class SortKeys
{
    /** Gap between the keys of consecutive rows when appending or renumbering: room for 20 moves to the same spot. */
    public const int STEP = 1 << 20;

    public function __construct(
        private Connection $connection,
        private string $table,
        private string $scopeColumn,
    ) {
    }

    public function count(string $scopeId): int
    {
        return SqlValue::int($this->connection->fetchOne("SELECT COUNT(*) FROM $this->table WHERE $this->scopeColumn = ?", [$scopeId]));
    }

    /**
     * Index of the row in its scope, from 0.
     */
    public function positionOf(string $scopeId, string $rowId): int
    {
        return SqlValue::int($this->connection->fetchOne(
            "SELECT COUNT(*) FROM $this->table WHERE $this->scopeColumn = ? AND sort_key < (SELECT sort_key FROM $this->table WHERE id = ?)",
            [$scopeId, $rowId],
        ));
    }

    /**
     * The key after the last row of the scope.
     */
    public function last(string $scopeId): int
    {
        return SqlValue::int($this->connection->fetchOne("SELECT COALESCE(MAX(sort_key), 0) FROM $this->table WHERE $this->scopeColumn = ?", [$scopeId])) + self::STEP;
    }

    /**
     * A key before the first row of the scope (renumbering it first if need be), for a row about to be inserted.
     */
    public function first(string $scopeId): int
    {
        return $this->between($scopeId, null, 0) ?? $this->renumberedThen(fn (): ?int => $this->between($scopeId, null, 0), $scopeId);
    }

    /**
     * Moves the row to the given index (from 0) in the scope's current order; the rows in between shift by one. An
     * index beyond the last row moves it to the end.
     *
     * @return int The index the row is at now
     */
    public function move(string $scopeId, string $rowId, int $position): int
    {
        $target = min($position, $this->count($scopeId) - 1);
        if ($target === $this->positionOf($scopeId, $rowId)) {
            return $target;
        }

        $key = $this->between($scopeId, $rowId, $target) ?? $this->renumberedThen(fn (): ?int => $this->between($scopeId, $rowId, $target), $scopeId);
        $this->connection->executeStatement("UPDATE $this->table SET sort_key = ? WHERE id = ?", [$key, $rowId]);

        return $target;
    }

    /**
     * A key that puts a row at the given index among the other rows of the scope (all of them for a row not inserted
     * yet), or null when its new neighbours' keys are adjacent.
     */
    private function between(string $scopeId, ?string $rowId, int $target): ?int
    {
        $keys = SqlValue::ints($this->connection->fetchFirstColumn(
            "SELECT sort_key FROM $this->table WHERE $this->scopeColumn = ? AND id IS DISTINCT FROM ? ORDER BY sort_key LIMIT 2 OFFSET ?",
            [$scopeId, $rowId, max(0, $target - 1)],
        ));
        // Keys are positive: the first row's previous neighbour is 0.
        [$previous, $next] = 0 === $target ? [0, $keys[0] ?? null] : [$keys[0] ?? throw new \LogicException('The row has a previous neighbour.'), $keys[1] ?? null];
        if (null === $next) {
            return $previous + self::STEP;
        }

        return $next - $previous >= 2 ? $previous + intdiv($next - $previous, 2) : null;
    }

    /**
     * @param callable(): ?int $key
     */
    private function renumberedThen(callable $key, string $scopeId): int
    {
        $this->renumber($scopeId);

        return $key() ?? throw new \LogicException('Renumbered keys always leave room.');
    }

    /**
     * Spreads the scope's keys again, STEP apart, keeping the order. The new keys are above the current highest one,
     * so that no row ever takes a key another one still has (the unique index is checked row by row): keys only grow,
     * by about 10^10 at most each time, far from the 9 × 10^18 of a BIGINT.
     */
    private function renumber(string $scopeId): void
    {
        $this->connection->executeStatement(
            "UPDATE $this->table t SET sort_key = o.highest + o.n * :step
             FROM (SELECT id, ROW_NUMBER() OVER (ORDER BY sort_key) AS n, MAX(sort_key) OVER () AS highest FROM $this->table WHERE $this->scopeColumn = :scope) o
             WHERE t.id = o.id",
            ['scope' => $scopeId, 'step' => self::STEP],
        );
    }
}
