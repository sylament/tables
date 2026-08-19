<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Fixtures\Doctrine;

use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement as StatementInterface;

final class RecordingStatement extends AbstractStatementMiddleware
{
    public function __construct(
        StatementInterface $statement,
        private readonly RecordingMiddleware $recorder,
        private readonly string $sql,
    ) {
        parent::__construct($statement);
    }

    public function execute(): Result
    {
        $this->recorder->record($this->sql);

        return parent::execute();
    }
}
