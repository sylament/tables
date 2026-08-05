<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Fixtures\Concerns;

use Closure;
use LogicException;
use Sylament\Tables\Concerns\HasTable;
use Sylament\Tables\Contracts\InteractsWithTable;
use Sylament\Tables\Table;

final class TableHost implements InteractsWithTable
{
    use HasTable;

    public function __construct(
        private readonly ?Closure $configureTable = null,
    ) {
    }

    public function table(Table $table): Table
    {
        if (!$this->configureTable instanceof Closure) {
            return $table;
        }

        $table = ($this->configureTable)($table);

        if (!$table instanceof Table) {
            throw new LogicException('The configureTable fixture closure must return a Table.');
        }

        return $table;
    }
}
