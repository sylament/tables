<?php

declare(strict_types=1);

namespace Sylament\Tables\Columns;

use BackedEnum;
use Closure;
use LogicException;
use stdClass;
use Sylament\Support\Components\Concerns\CanBeCopied;
use Sylament\Support\Components\Concerns\CanWrap;
use Sylament\Support\Components\Concerns\HasFontFamily;
use Sylament\Support\Components\Concerns\HasLineClamp;
use Sylament\Support\Components\Concerns\HasWeight;
use Sylament\Support\Enums\TextSize;
use Sylament\Tables\Columns\Concerns\CanFormatState;
use Sylament\Tables\Columns\Concerns\HasColor;
use Sylament\Tables\Columns\Concerns\HasDescription;
use Sylament\Tables\Columns\Concerns\HasIcon;
use Sylament\Tables\Columns\Concerns\HasIconColor;
use Sylament\Tables\Contracts\InteractsWithTable;
use Traversable;
use Twig\Markup;

use function Sylament\Support\blank;
use function Sylament\Support\wrap;

class TextColumn extends Column
{
    use CanBeCopied;
    use CanFormatState;
    use CanWrap;
    use HasColor;
    use HasDescription;
    use HasFontFamily;
    use HasIcon;
    use HasIconColor;
    use HasLineClamp;
    use HasWeight;

    protected string|Closure|null $template = '@SylamentTables/partials/TextColumn/index.html.twig';

    protected bool|Closure $isBadge = false;

    protected bool|Closure $isBulleted = false;

    protected bool|Closure $isListWithLineBreaks = false;

    protected int|Closure|null $listLimit = null;

    protected bool|Closure $isLimitedListExpandable = false;

    protected TextSize|string|Closure|null $size = null;

    public function badge(bool|Closure $condition = true): static
    {
        $this->isBadge = $condition;

        return $this;
    }

    public function isBadge(): bool
    {
        return (bool) $this->evaluate($this->isBadge);
    }

    public function bulleted(bool|Closure $condition = true): static
    {
        $this->isBulleted = $condition;

        return $this;
    }

    public function isBulleted(): bool
    {
        return (bool) $this->evaluate($this->isBulleted);
    }

    public function listWithLineBreaks(bool|Closure $condition = true): static
    {
        $this->isListWithLineBreaks = $condition;

        return $this;
    }

    public function isListWithLineBreaks(): bool
    {
        return (bool) $this->evaluate($this->isListWithLineBreaks) || $this->isBulleted();
    }

    public function limitList(int|Closure|null $limit = 3): static
    {
        $this->listLimit = $limit;

        return $this;
    }

    public function getListLimit(): ?int
    {
        return $this->evaluate($this->listLimit);
    }

    public function expandableLimitedList(bool|Closure $condition = true): static
    {
        $this->isLimitedListExpandable = $condition;

        return $this;
    }

    public function isLimitedListExpandable(): bool
    {
        return (bool) $this->evaluate($this->isLimitedListExpandable);
    }

    /**
     * The cell numbers its own rows: the row loop's one-based iteration (or its
     * zero-based index), offset by the pages already behind it whenever the host
     * paginates by a number rather than showing every record.
     */
    public function rowIndex(bool $isFromZero = false): static
    {
        $this->state(static function (TextColumn $column, InteractsWithTable $liveComponent, ?stdClass $rowLoop) use ($isFromZero): string {
            $rowIndex = $rowLoop?->{$isFromZero ? 'index' : 'iteration'};

            if (!is_int($rowIndex)) {
                throw new LogicException(sprintf('rowIndex() on column [%s] needs the row loop the table cell passes; the column was rendered without one.', $column->getName()));
            }

            $recordsPerPage = $liveComponent->getTableRecordsPerPage();

            if (!is_numeric($recordsPerPage)) {
                return (string) $rowIndex;
            }

            return (string) ($rowIndex + ((int) $recordsPerPage * ($liveComponent->getTablePage() - 1)));
        });

        return $this;
    }

    public function size(TextSize|string|Closure|null $size): static
    {
        $this->size = $size;

        return $this;
    }

    public function getSize(mixed $state): TextSize|string
    {
        $size = $this->evaluate($this->size, [
            'state' => $state,
        ]);

        if (blank($size)) {
            return TextSize::Small;
        }

        if (is_string($size)) {
            $size = TextSize::tryFrom($size) ?? $size;
        }

        if ($size === 'base') {
            return TextSize::Medium;
        }

        return $size;
    }

    /**
     * The class fragment templates emit for the size: `sy-size-<value>` for a `TextSize`
     * case, a custom string as-is — so templates never type-sniff the enum-vs-string union.
     */
    public function getSizeClass(mixed $state): string
    {
        $size = $this->getSize($state);

        if ($size instanceof BackedEnum) {
            return 'sy-size-' . $size->value;
        }

        return $size;
    }

    /**
     * The cell's values, paired with the content each renders. A limited list is sliced
     * first — unless the client can expand it in place, which needs every value in the
     * markup — and what survives collapses to one comma-joined entry unless the values
     * are badges or the list breaks onto its own lines, so a colour closure on a
     * collapsed cell sees the joined string rather than an item.
     *
     * @return array<int, array{state: mixed, content: string|Markup|null}>
     */
    public function getStateItems(): array
    {
        $values = $this->getWrappedState();

        if ($values === []) {
            return [];
        }

        $isListWithLineBreaks = $this->isListWithLineBreaks();
        $limit = $this->getListLimit();

        if ($this->countOverListLimit($values) > 0 && (!$isListWithLineBreaks || !$this->isLimitedListExpandable())) {
            $values = array_slice($values, 0, $limit);
        }

        if (count($values) > 1 && ! $isListWithLineBreaks && ! $this->isBadge()) {
            $joined = implode(', ', array_map(
                fn (mixed $value): string => (string) $this->formatStateForItem($value),
                $values,
            ));

            return [['state' => $joined, 'content' => $joined]];
        }

        return array_map(
            fn (mixed $value): array => ['state' => $value, 'content' => $this->formatStateForItem($value)],
            $values,
        );
    }

    /**
     * How many values `limitList()` keeps out of the rendered list — `ImageColumn`'s
     * sibling, and what the partial counts in its "and N more" message.
     */
    public function getStateOverListLimitCount(): int
    {
        return $this->countOverListLimit($this->getWrappedState());
    }

    /**
     * @param array<int, mixed> $values
     */
    private function countOverListLimit(array $values): int
    {
        $limit = $this->getListLimit();

        if ($limit === null || $limit < 1) {
            return 0;
        }

        $count = count($values);

        return $count > $limit ? $count - $limit : 0;
    }

    /**
     * @return array<int, mixed>
     */
    private function getWrappedState(): array
    {
        $state = $this->getState();

        if (! is_array($state) && $state instanceof Traversable) {
            $state = iterator_to_array($state, false);
        }

        if (blank($state)) {
            return [];
        }

        return array_values(wrap($state));
    }

    /**
     * Narrows `formatState()`'s `mixed` return to the `string|Markup|null` its pipeline
     * actually produces.
     */
    private function formatStateForItem(mixed $value): string|Markup|null
    {
        $formatted = $this->formatState($value);

        if ($formatted === null || is_string($formatted) || $formatted instanceof Markup) {
            return $formatted;
        }

        throw new LogicException(sprintf('formatState() for column [%s] must return a string, Markup, or null; %s given.', $this->getName(), get_debug_type($formatted)));
    }
}
