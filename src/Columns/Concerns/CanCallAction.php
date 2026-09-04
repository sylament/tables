<?php

declare(strict_types=1);

namespace Sylament\Tables\Columns\Concerns;

use Closure;
use Sylament\Actions\Action;
use Sylament\Tables\Contracts\InteractsWithTable;

trait CanCallAction
{
    protected Closure|Action|null $action = null;

    public function action(Closure|Action|string|null $action): static
    {
        if (is_string($action)) {
            $action = static function (InteractsWithTable $liveComponent, object|array|null $record) use ($action): mixed {
                if ($record !== null) {
                    return $liveComponent->{$action}($record);
                }

                return $liveComponent->{$action}();
            };
        }

        $this->action = $action;

        return $this;
    }

    public function getAction(): Closure|Action|null
    {
        return $this->action;
    }
}
