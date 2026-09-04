<?php

declare(strict_types=1);

namespace Sylament\Tables\Columns\Summarizers\Contracts;

/**
 * A summarizer whose figure the one batched select can carry, under an alias the
 * host pushes onto it before reading its statements — which is what makes the
 * lookup that reads the figure back hit.
 */
interface HasSelectAlias
{
    public function selectAlias(?string $alias): static;

    public function getSelectAlias(): string;
}
