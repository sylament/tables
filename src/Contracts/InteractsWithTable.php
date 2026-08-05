<?php

declare(strict_types=1);

namespace Sylament\Tables\Contracts;

use Sylament\Tables\Table;

interface InteractsWithTable
{
    public function getTable(): Table;

    /**
     * @return list<object|array<string, mixed>>
     */
    public function getTableRecords(): array;
}
