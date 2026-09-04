<?php

declare(strict_types=1);

namespace Sylament\Tables\Columns\Summarizers;

use Doctrine\ORM\QueryBuilder;
use LogicException;
use Sylament\Tables\Columns\Summarizers\Concerns\HasSelectAlias;
use Sylament\Tables\Query\SummaryQuery;

class Count extends Summarizer implements Contracts\HasSelectAlias
{
    use HasSelectAlias;

    protected bool $hasIcons = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->numeric();
    }

    /**
     * @throws LogicException under `icons()`, whose per-icon counts wait for a column
     *                        type that renders them
     */
    public function summarize(QueryBuilder $query, string $attribute): int|float|null
    {
        if ($this->hasIcons) {
            throw new LogicException(sprintf('Cannot summarize [%s] with icons(): an icon count needs one count per distinct icon of an IconColumn, which this toolkit does not render yet. Drop icons(), or take the summary over with Summarizer::using().', $this->getColumn()->getName()));
        }

        return self::toNumber(SummaryQuery::readRow($query, ["COUNT({$attribute}) AS sy_value"])['sy_value'] ?? null);
    }

    /**
     * @return array<string, string>
     */
    public function getSelectStatements(string $column): array
    {
        return $this->hasIcons ? [] : [$this->getSelectAlias() => "COUNT({$column})"];
    }

    public function icons(bool $condition = true): static
    {
        $this->hasIcons = $condition;

        return $this;
    }

    public function hasIcons(): bool
    {
        return $this->hasIcons;
    }

    public function getDefaultLabel(): ?string
    {
        return $this->hasIcons ? null : 'Count';
    }
}
