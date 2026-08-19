<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Fixtures\Doctrine;

use Doctrine\DBAL\Driver as DriverInterface;
use Doctrine\DBAL\Driver\Middleware;

/**
 * Records the SQL of every statement the connection runs, so a test can assert
 * what a read costs in queries. Pass it to {@see EntityManagerFactory::create()}
 * and {@see reset()} it after seeding, so only the statements under test count.
 */
final class RecordingMiddleware implements Middleware
{
    /**
     * @var list<string>
     */
    private array $statements = [];

    public function wrap(DriverInterface $driver): DriverInterface
    {
        return new RecordingDriver($driver, $this);
    }

    public function record(string $sql): void
    {
        $this->statements[] = $sql;
    }

    /**
     * @return list<string>
     */
    public function statements(): array
    {
        return $this->statements;
    }

    public function reset(): void
    {
        $this->statements = [];
    }
}
