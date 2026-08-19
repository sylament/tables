<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Fixtures\Doctrine;

use Doctrine\DBAL\Driver\Connection as ConnectionInterface;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement as StatementInterface;

final class RecordingConnection extends AbstractConnectionMiddleware
{
    public function __construct(
        ConnectionInterface $connection,
        private readonly RecordingMiddleware $recorder,
    ) {
        parent::__construct($connection);
    }

    /**
     * A parameterised query is prepared here and recorded when it executes, so
     * preparing without executing costs nothing.
     */
    public function prepare(string $sql): StatementInterface
    {
        return new RecordingStatement(parent::prepare($sql), $this->recorder, $sql);
    }

    public function query(string $sql): Result
    {
        $this->recorder->record($sql);

        return parent::query($sql);
    }

    public function exec(string $sql): int|string
    {
        $this->recorder->record($sql);

        return parent::exec($sql);
    }
}
