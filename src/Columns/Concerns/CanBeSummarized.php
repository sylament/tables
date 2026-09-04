<?php

declare(strict_types=1);

namespace Sylament\Tables\Columns\Concerns;

use Closure;
use Doctrine\ORM\QueryBuilder;
use Sylament\Tables\Columns\Summarizers\Summarizer;

use function Sylament\Support\filled;
use function Sylament\Support\wrap;

trait CanBeSummarized
{
    /**
     * @var array<string|int, Summarizer>
     */
    protected array $summarizers = [];

    /**
     * @param array<Summarizer>|Summarizer $summarizers
     */
    public function summarize(array|Summarizer $summarizers): static
    {
        foreach (wrap($summarizers) as $summarizer) {
            $summarizer->column($this);

            $id = $summarizer->getId();

            if (filled($id)) {
                $this->summarizers[$id] = $summarizer;

                continue;
            }

            $this->summarizers[] = $summarizer;
        }

        return $this;
    }

    public function getSummarizer(string $id): ?Summarizer
    {
        return $this->getSummarizers()[$id] ?? null;
    }

    /**
     * Given a query, every summarizer is re-pointed at it and the hidden ones drop
     * out — deliberately mutating, because that re-point is how the band tells each
     * summarizer which dataset this row of the summary is over.
     *
     * @return array<string|int, Summarizer>
     */
    public function getSummarizers(QueryBuilder|Closure|null $query = null): array
    {
        if ($query === null) {
            return $this->summarizers;
        }

        return array_filter(
            $this->summarizers,
            static fn (Summarizer $summarizer): bool => $summarizer->query($query)->isVisible(),
        );
    }

    public function hasSummary(QueryBuilder|Closure|null $query = null): bool
    {
        return $this->getSummarizers($query) !== [];
    }
}
