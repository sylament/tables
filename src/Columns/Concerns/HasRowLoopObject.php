<?php

declare(strict_types=1);

namespace Sylament\Tables\Columns\Concerns;

use stdClass;

trait HasRowLoopObject
{
    protected ?stdClass $loop = null;

    /**
     * @param array<string, mixed>|stdClass|null $loop
     */
    public function rowLoop(array|stdClass|null $loop): static
    {
        $this->loop = is_array($loop) ? $this->makeRowLoopObject($loop) : $loop;

        return $this;
    }

    public function getRowLoop(): ?stdClass
    {
        return $this->loop;
    }

    /**
     * @param array<string, mixed> $loop
     */
    private function makeRowLoopObject(array $loop): stdClass
    {
        $iteration = $loop['index'] ?? null;
        $isEven = is_int($iteration) ? $iteration % 2 === 0 : null;

        return (object) [
            'iteration' => $iteration,
            'index' => $loop['index0'] ?? null,
            'remaining' => $loop['revindex0'] ?? null,
            'count' => $loop['length'] ?? null,
            'first' => $loop['first'] ?? null,
            'last' => $loop['last'] ?? null,
            'odd' => $isEven === null ? null : !$isEven,
            'even' => $isEven,
            'depth' => 1,
            'parent' => null,
        ];
    }
}
