<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Fixtures\Concerns;

use Sylament\Schemas\Concerns\HasSchemas;
use Sylament\Schemas\Contracts\InteractsWithSchemas;
use Sylament\Schemas\Schema;
use Symfony\UX\LiveComponent\Attribute\LiveProp;

/**
 * A schema host with its own writable `$data` bag, mirroring forms'
 * `FieldRenderHost`: a field rendered inside `Schema::make(new SchemaStateHost())
 * ->statePath('data')` resolves `getState()` against `$data`, so an unset key
 * reads back null instead of throwing on a missing host property.
 */
class SchemaStateHost implements InteractsWithSchemas
{
    use HasSchemas;

    /**
     * @var array<string, mixed>
     */
    #[LiveProp(writable: true)]
    public array $data = [];

    public function schema(Schema $schema): Schema
    {
        return $schema->statePath('data');
    }
}
