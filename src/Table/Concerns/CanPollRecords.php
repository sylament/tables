<?php

declare(strict_types=1);

namespace Sylament\Tables\Table\Concerns;

use Closure;
use InvalidArgumentException;

trait CanPollRecords
{
    protected string|Closure|null $pollingInterval = null;

    public function poll(string|Closure|null $interval = '10s'): static
    {
        $this->pollingInterval = $interval;

        return $this;
    }

    public function getPollingInterval(): ?string
    {
        return $this->evaluate($this->pollingInterval);
    }

    /**
     * The interval as the table controller's `pollInterval` value wants it, or
     * null where nothing polls. Only Filament's two duration units parse — an
     * interval that reads as anything else throws rather than picking a number
     * on the reader's behalf, since a table silently polling at the wrong rate
     * is invisible until it costs somebody a database.
     */
    public function getPollingIntervalMilliseconds(): ?int
    {
        $interval = $this->getPollingInterval();

        if ($interval === null) {
            return null;
        }

        if (preg_match('/^(\d+(?:\.\d+)?)\s*(ms|s)$/', mb_trim($interval), $matches) !== 1) {
            throw new InvalidArgumentException(sprintf('Table [%s] polls every [%s], which is not a duration; use a number of milliseconds or seconds, like [500ms] or [10s].', static::class, $interval));
        }

        $value = (float) $matches[1];

        return (int) round($matches[2] === 's' ? $value * 1000 : $value);
    }
}
