<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Fixtures\Doctrine;

use Doctrine\DBAL\Driver as DriverInterface;
use Doctrine\DBAL\Driver\Connection as ConnectionInterface;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use SensitiveParameter;

final class RecordingDriver extends AbstractDriverMiddleware
{
    public function __construct(
        DriverInterface $driver,
        private readonly RecordingMiddleware $recorder,
    ) {
        parent::__construct($driver);
    }

    /**
     * {@inheritDoc}
     */
    public function connect(
        #[SensitiveParameter]
        array $params,
    ): ConnectionInterface {
        return new RecordingConnection(parent::connect($params), $this->recorder);
    }
}
