<?php

declare(strict_types=1);

namespace Sylament\Tables\Columns\Summarizers;

use Doctrine\ORM\QueryBuilder;
use Sylament\Tables\Columns\Summarizers\Concerns\HasSelectAlias;
use Sylament\Tables\Query\SummaryQuery;

class Average extends Summarizer implements Contracts\HasSelectAlias
{
    use HasSelectAlias;

    protected function setUp(): void
    {
        parent::setUp();

        $this->numeric();
    }

    public function summarize(QueryBuilder $query, string $attribute): int|float|null
    {
        return self::toNumber(SummaryQuery::readRow($query, ["AVG({$attribute}) AS sy_value"])['sy_value'] ?? null);
    }

    /**
     * @return array<string, string>
     */
    public function getSelectStatements(string $column): array
    {
        return [$this->getSelectAlias() => "AVG({$column})"];
    }

    public function getDefaultLabel(): ?string
    {
        return 'Average';
    }
}
