<?php

declare(strict_types=1);

namespace Sylament\Tables\Columns\Summarizers;

use Closure;
use Doctrine\ORM\QueryBuilder;
use Sylament\Tables\Query\SummaryQuery;

class Values extends Summarizer
{
    public const string VALUES_TEMPLATE = '@SylamentTables/partials/Summarizer/values.html.twig';

    protected string|Closure|null $defaultTemplate = self::VALUES_TEMPLATE;

    protected bool|Closure $isBulleted = true;

    /**
     * The distinct values as the driver hands them over, which is upstream's own
     * semantics: nothing casts them, and the template formats each on its own.
     *
     * @return list<mixed>
     */
    public function summarize(QueryBuilder $query, string $attribute): array
    {
        return SummaryQuery::readDistinct($query, $attribute);
    }

    /**
     * The list reaches the template raw, because the template formats each entry on
     * its own — which is what keeps a `formatStateUsing()` closure looking at one
     * value rather than at the whole list, the shape every other summarizer's
     * closure sees and the only one a scalar-typed closure survives.
     *
     * @return array<array-key, mixed>
     */
    public function getExtraTemplateData(): array
    {
        return [
            'state' => $this->getState(),
        ];
    }

    public function bulleted(bool|Closure $condition = true): static
    {
        $this->isBulleted = $condition;

        return $this;
    }

    public function isBulleted(): bool
    {
        return (bool) $this->evaluate($this->isBulleted);
    }
}
