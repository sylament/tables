<?php

declare(strict_types=1);

use Symfony\Component\ErrorHandler\ErrorHandler;

require __DIR__ . '/../../../vendor/autoload.php';

ErrorHandler::register(null, false);
