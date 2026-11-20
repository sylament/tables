<?php

declare(strict_types=1);

namespace Sylament\Tables\Testing;

use LogicException;
use PHPUnit\Framework\Assert;
use Sylament\Tables\Contracts\InteractsWithTable;
use Symfony\Component\DomCrawler\Crawler;

/**
 * @phpstan-require-extends Testable
 */
trait TestsRecords
{
    /**
     * @param iterable<object|array<string, mixed>|string|int> $records
     */
    public function assertCanSeeTableRecords(iterable $records, bool $inOrder = false): static
    {
        $host = $this->getTestedTableHost();
        $keys = $this->getTestedTableRecordKeys($host, $records);
        $renderedKeys = $this->getRenderedTableRecordKeys($host);
        $hostClass = $host::class;

        if ($inOrder) {
            $position = 0;

            foreach ($keys as $key) {
                $index = array_search($key, array_slice($renderedKeys, $position), true);

                Assert::assertIsInt(
                    $index,
                    'Failed asserting that table rows with keys [' . implode(', ', $keys) . "] are visible in that order on the [{$hostClass}] component.",
                );

                $position += $index + 1;
            }

            return $this;
        }

        foreach ($keys as $key) {
            Assert::assertContains(
                $key,
                $renderedKeys,
                "Failed asserting that a table row with key [{$key}] is visible on the [{$hostClass}] component.",
            );
        }

        return $this;
    }

    /**
     * @param iterable<object|array<string, mixed>|string|int> $records
     */
    public function assertCanNotSeeTableRecords(iterable $records): static
    {
        $host = $this->getTestedTableHost();
        $renderedKeys = $this->getRenderedTableRecordKeys($host);
        $hostClass = $host::class;

        foreach ($this->getTestedTableRecordKeys($host, $records) as $key) {
            Assert::assertNotContains(
                $key,
                $renderedKeys,
                "Failed asserting that a table row with key [{$key}] is not visible on the [{$hostClass}] component.",
            );
        }

        return $this;
    }

    public function assertCountTableRecords(int $count): static
    {
        $host = $this->getTestedTableHost();
        $hostClass = $host::class;

        if (!method_exists($host, 'getAllTableRecordsCount')) {
            throw new LogicException(sprintf('The [%s] component cannot count its table records: it has no [getAllTableRecordsCount()].', $hostClass));
        }

        Assert::assertSame(
            $count,
            $host->getAllTableRecordsCount(),
            "Failed asserting that the [{$hostClass}] table has [{$count}] records in total.",
        );

        return $this;
    }

    public function loadTable(): static
    {
        $this->call('loadTable');

        return $this;
    }

    private function getTestedTableHost(): InteractsWithTable
    {
        $host = $this->component();

        if (!$host instanceof InteractsWithTable) {
            throw new LogicException(sprintf('The [%s] component renders no table: it does not implement [%s].', $host::class, InteractsWithTable::class));
        }

        return $host;
    }

    /**
     * @param iterable<object|array<string, mixed>|string|int> $records
     * @return list<string>
     */
    private function getTestedTableRecordKeys(InteractsWithTable $host, iterable $records): array
    {
        $keys = [];

        foreach ($records as $record) {
            $keys[] = (is_object($record) || is_array($record)) ? $host->getTableRecordKey($record) : (string) $record;
        }

        return $keys;
    }

    /**
     * Every row carries `id="<host id>.table.records.<key>"`, so the keys are read from the
     * rendered ids, whole, in document order.
     *
     * @return list<string>
     */
    private function getRenderedTableRecordKeys(InteractsWithTable $host): array
    {
        $prefix = "{$host->getTableHostId()}.table.records.";
        $keys = [];

        foreach ($this->render()->crawler()->filter('[id]')->each(fn (Crawler $element): string => (string) $element->attr('id')) as $id) {
            if (str_starts_with($id, $prefix)) {
                $keys[] = mb_substr($id, mb_strlen($prefix));
            }
        }

        return $keys;
    }
}
