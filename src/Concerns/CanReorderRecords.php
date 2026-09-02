<?php

declare(strict_types=1);

namespace Sylament\Tables\Concerns;

use Doctrine\ORM\QueryBuilder;
use LogicException;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;

trait CanReorderRecords
{
    /**
     * A transient UI mode rather than browsing state, so it carries no URL
     * mapping and no session half; it mutates only through
     * {@see toggleTableReordering()}.
     */
    #[LiveProp]
    public bool $isTableReordering = false;

    #[LiveAction]
    public function toggleTableReordering(): void
    {
        $this->isTableReordering = !$this->isTableReordering;

        $this->flushCachedTableRecords();
    }

    /**
     * Re-gated by the definition on every read, so a flag left standing by a
     * table that has since lost its reorder column is inert.
     */
    public function isTableReordering(): bool
    {
        return $this->getTable()->isReorderable() && $this->isTableReordering;
    }

    /**
     * The new order the drag produced, as record keys. Every key is untrusted,
     * so the write re-resolves them through the table's own query and assigns
     * positions 1..N by array position — a key the query does not answer for is
     * skipped, and its slot is left as a gap in the numbering — and one flush
     * carries the lot, so lifecycle callbacks, versioning and the unit of work
     * stay honest.
     *
     * @param array<mixed> $order
     */
    #[LiveAction]
    public function reorderTable(#[LiveArg] array $order): void
    {
        $table = $this->getTable();

        if (!$table->isReorderable()) {
            return;
        }

        $order = array_values(array_map(
            static fn (mixed $key): string => (string) $key,
            array_filter($order, static fn (mixed $key): bool => is_scalar($key) && !is_bool($key)),
        ));

        $column = (string) $table->getReorderColumn();
        $query = $table->hasQuery() ? $table->getQuery() : null;
        $entityManager = $table->getQueryEntityManager();

        if (!$query instanceof QueryBuilder || $entityManager === null) {
            throw new LogicException(sprintf('Table [%s] reorders records it does not query; a position column is written through the [query()] and [relationship()] lanes only.', static::class));
        }

        $table->callBeforeReordering($order);

        $records = $this->resolveReorderedTableRecords($query, $order);
        $accessor = $table->getPropertyAccessor();

        foreach ($table->getReorderDirection() === 'desc' ? array_reverse($order) : $order as $index => $key) {
            $record = $records[$key] ?? null;

            if ($record === null) {
                continue;
            }

            $accessor->setOnRecord($record, $column, $index + 1);
        }

        $entityManager->flush();

        $this->flushCachedTableRecords();

        $table->callAfterReordering($order);
    }

    /**
     * The submitted keys as managed entities, keyed by record key — the
     * selection lane's re-resolution, narrowed to the dragged page.
     *
     * @param list<string> $order
     *
     * @return array<string, object>
     */
    private function resolveReorderedTableRecords(QueryBuilder $query, array $order): array
    {
        if ($order === []) {
            return [];
        }

        /** @var list<object> $records */
        $records = $query
            ->andWhere(sprintf('%s IN (:sylament_reordered_record_keys)', $this->resolveTableRecordKeyExpression($query)))
            ->setParameter('sylament_reordered_record_keys', $order)
            ->getQuery()
            ->getResult();

        $keyedRecords = [];

        foreach ($records as $record) {
            $keyedRecords[$this->getTableRecordKey($record)] = $record;
        }

        return $keyedRecords;
    }
}
