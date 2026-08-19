<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Fixtures\Doctrine;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\StringType;

/**
 * Maps {@see Handle} onto a plain string column, so an entity keyed by one
 * hydrates its identifier as the object rather than the stored string.
 */
final class HandleType extends StringType
{
    public const string NAME = 'handle';

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        return $value === null ? null : (string) $value;
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?Handle
    {
        return $value === null ? null : new Handle((string) $value);
    }
}
