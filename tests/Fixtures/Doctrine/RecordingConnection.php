<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Fixtures\Doctrine;

use Doctrine\DBAL\Driver\Connection as ConnectionInterface;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement as StatementInterface;

final class RecordingConnection extends AbstractConnectionMiddleware
{
    public const string BEGIN = 'BEGIN';

    public const string COMMIT = 'COMMIT';

    public const string ROLLBACK = 'ROLLBACK';

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

    /**
     * The three transaction calls carry no SQL of their own — the driver speaks
     * them natively — so they are recorded as markers instead, which is what
     * lets a test read a BEGIN…COMMIT (or a ROLLBACK) out of the same statement
     * list the queries land in. A nested transaction never reaches here: DBAL
     * turns that into a savepoint, whose SQL flows through `exec()` above.
     */
    public function beginTransaction(): void
    {
        $this->recorder->record(self::BEGIN);

        parent::beginTransaction();
    }

    public function commit(): void
    {
        $this->recorder->record(self::COMMIT);

        parent::commit();
    }

    public function rollBack(): void
    {
        $this->recorder->record(self::ROLLBACK);

        parent::rollBack();
    }
}
