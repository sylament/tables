<?php

declare(strict_types=1);

namespace Sylament\Tables\Table\Concerns;

use Sylament\Tables\Contracts\InteractsWithTable;

trait BelongsToLiveComponent
{
    protected InteractsWithTable $liveComponent;

    public function liveComponent(InteractsWithTable $liveComponent): static
    {
        $this->liveComponent = $liveComponent;

        return $this;
    }

    public function getLiveComponent(): InteractsWithTable
    {
        return $this->liveComponent;
    }
}
