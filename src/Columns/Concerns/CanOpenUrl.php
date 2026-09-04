<?php

declare(strict_types=1);

namespace Sylament\Tables\Columns\Concerns;

use Closure;

use function Sylament\Support\sanitize_url;

trait CanOpenUrl
{
    protected bool|Closure $shouldOpenUrlInNewTab = false;

    protected string|Closure|null $url = null;

    public function openUrlInNewTab(bool|Closure $condition = true): static
    {
        $this->shouldOpenUrlInNewTab = $condition;

        return $this;
    }

    public function url(string|Closure|null $url, bool|Closure|null $shouldOpenInNewTab = null): static
    {
        if ($shouldOpenInNewTab !== null) {
            $this->openUrlInNewTab($shouldOpenInNewTab);
        }

        $this->url = $url;

        return $this;
    }

    /**
     * Arity-switched: called with no argument it answers only a URL that does
     * not read the state, and called with one only a URL that does — so a cell
     * whose links are per state item renders none on its wrapper.
     */
    public function getUrl(mixed $state = null): ?string
    {
        if (func_num_args() === 1) {
            return $this->hasStateBasedUrls()
                ? sanitize_url($this->evaluate($this->url, ['state' => $state]))
                : null;
        }

        if ($this->hasStateBasedUrls()) {
            return null;
        }

        return sanitize_url($this->evaluate($this->url));
    }

    public function hasStateBasedUrls(): bool
    {
        return $this->evaluationValueIsFunctionAndHasParameter($this->url, parameterName: 'state');
    }

    public function shouldOpenUrlInNewTab(): bool
    {
        return (bool) $this->evaluate($this->shouldOpenUrlInNewTab);
    }
}
