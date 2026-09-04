<?php

declare(strict_types=1);

namespace Sylament\Tables\Columns;

use BackedEnum;
use Closure;
use Doctrine\ORM\EntityManagerInterface;
use Stringable;
use Sylament\Support\Components\Concerns\CanWrap;
use Sylament\Support\Contracts\HasLabel;
use Sylament\Support\Enums\IconSize;
use Sylament\Support\Icons\IconManager;
use Sylament\Tables\Columns\Concerns\HasColor;
use Sylament\Tables\Columns\Concerns\HasIcon;
use Sylament\Tables\Twig\TablesIconAlias;
use Symfony\Contracts\Service\Attribute\Required;
use Traversable;
use Twig\Markup;

use function Sylament\Support\blank;
use function Sylament\Support\filled;
use function Sylament\Support\wrap;

class IconColumn extends Column
{
    use CanWrap;
    use HasColor {
        getColor as getBaseColor;
    }
    use HasIcon {
        getIcon as getBaseIcon;
    }

    protected string|Closure|null $template = '@SylamentTables/partials/IconColumn/index.html.twig';

    protected bool|Closure|null $isBoolean = null;

    /**
     * @var string|array<int|string, string|int>|Closure|null
     */
    protected string|array|Closure|null $falseColor = null;

    protected string|BackedEnum|Markup|Closure|false|null $falseIcon = null;

    /**
     * @var string|array<int|string, string|int>|Closure|null
     */
    protected string|array|Closure|null $trueColor = null;

    protected string|BackedEnum|Markup|Closure|false|null $trueIcon = null;

    protected bool|Closure $isListWithLineBreaks = false;

    protected IconSize|string|Closure|null $size = null;

    private ?IconManager $iconManager = null;

    private ?bool $cachedIsBooleanFromMetadata = null;

    #[Required]
    public function setIconManager(IconManager $iconManager): void
    {
        $this->iconManager = $iconManager;
    }

    public function boolean(bool|Closure $condition = true): static
    {
        $this->isBoolean = $condition;

        return $this;
    }

    public function listWithLineBreaks(bool|Closure $condition = true): static
    {
        $this->isListWithLineBreaks = $condition;

        return $this;
    }

    /**
     * @param string|array<int|string, string|int>|Closure|null $color
     */
    public function false(string|BackedEnum|Markup|Closure|false|null $icon = null, string|array|Closure|null $color = null): static
    {
        $this->falseIcon($icon);
        $this->falseColor($color);

        return $this;
    }

    /**
     * @param string|array<int|string, string|int>|Closure|null $color
     */
    public function falseColor(string|array|Closure|null $color): static
    {
        $this->boolean();
        $this->falseColor = $color;

        return $this;
    }

    public function falseIcon(string|BackedEnum|Markup|Closure|false|null $icon): static
    {
        $this->boolean();
        $this->falseIcon = $icon;

        return $this;
    }

    /**
     * @param string|array<int|string, string|int>|Closure|null $color
     */
    public function true(string|BackedEnum|Markup|Closure|false|null $icon = null, string|array|Closure|null $color = null): static
    {
        $this->trueIcon($icon);
        $this->trueColor($color);

        return $this;
    }

    /**
     * @param string|array<int|string, string|int>|Closure|null $color
     */
    public function trueColor(string|array|Closure|null $color): static
    {
        $this->boolean();
        $this->trueColor = $color;

        return $this;
    }

    public function trueIcon(string|BackedEnum|Markup|Closure|false|null $icon): static
    {
        $this->boolean();
        $this->trueIcon = $icon;

        return $this;
    }

    public function size(IconSize|string|Closure|null $size): static
    {
        $this->size = $size;

        return $this;
    }

    public function getSize(mixed $state): IconSize|string|null
    {
        $size = $this->evaluate($this->size, [
            'state' => $state,
        ]);

        if (blank($size) || $size === 'base') {
            return null;
        }

        if (is_string($size)) {
            return IconSize::tryFrom($size) ?? $size;
        }

        return $size;
    }

    public function getIcon(mixed $state): string|BackedEnum|Markup|null
    {
        if (filled($icon = $this->getBaseIcon($state))) {
            return $icon;
        }

        if (!$this->isBoolean() || $state === null) {
            return null;
        }

        return $state ? $this->getTrueIcon() : $this->getFalseIcon();
    }

    /**
     * @return string|array<int|string, string|int>|null
     */
    public function getColor(mixed $state): string|array|null
    {
        if (filled($color = $this->getBaseColor($state))) {
            return $color;
        }

        if (!$this->isBoolean() || $state === null) {
            return null;
        }

        return $state ? $this->getTrueColor() : $this->getFalseColor();
    }

    /**
     * @return string|array<int|string, string|int>
     */
    public function getFalseColor(): string|array
    {
        return $this->evaluate($this->falseColor) ?? 'danger';
    }

    public function getFalseIcon(): string|BackedEnum|Markup|null
    {
        $icon = $this->evaluate($this->falseIcon);

        if ($icon === false) {
            return null;
        }

        return $icon ?? $this->resolveIcon(TablesIconAlias::COLUMNS_ICON_COLUMN_FALSE, 'heroicons:x-circle');
    }

    /**
     * @return string|array<int|string, string|int>
     */
    public function getTrueColor(): string|array
    {
        return $this->evaluate($this->trueColor) ?? 'success';
    }

    public function getTrueIcon(): string|BackedEnum|Markup|null
    {
        $icon = $this->evaluate($this->trueIcon);

        if ($icon === false) {
            return null;
        }

        return $icon ?? $this->resolveIcon(TablesIconAlias::COLUMNS_ICON_COLUMN_TRUE, 'heroicons:check-circle');
    }

    /**
     * Unset, the answer comes from the record class's Doctrine mapping — false on
     * the records lane, where there is no mapping to ask.
     */
    public function isBoolean(): bool
    {
        if (blank($this->isBoolean)) {
            return $this->isBooleanField();
        }

        return (bool) $this->evaluate($this->isBoolean);
    }

    public function isListWithLineBreaks(): bool
    {
        return (bool) $this->evaluate($this->isListWithLineBreaks);
    }

    /**
     * The cell's icons, one entry per state value: the icon, its colour and size,
     * the screen-reader text alternative that names it, and the raw value the
     * per-item url and tooltip closures were handed. An entry whose icon resolves
     * blank carries nothing but its state — the template skips it whole.
     *
     * @return list<array{state: mixed, icon: string|BackedEnum|Markup|null, color: string|array<int|string, string|int>|null, size: IconSize|string|null, alternative: ?string, url: ?string, tooltip: string|Markup|null}>
     */
    public function getStateItems(): array
    {
        return array_map(
            function (mixed $value): array {
                $icon = $this->getIcon($value);

                if (blank($icon)) {
                    return ['state' => $value, 'icon' => null, 'color' => null, 'size' => null, 'alternative' => null, 'url' => null, 'tooltip' => null];
                }

                $tooltip = $this->getTooltip($value);

                return [
                    'state' => $value,
                    'icon' => $icon,
                    'color' => $this->getColor($value),
                    'size' => $this->getSize($value),
                    'alternative' => $this->getTextAlternative($value, $tooltip),
                    'url' => $this->getUrl($value),
                    'tooltip' => $tooltip,
                ];
            },
            $this->getWrappedState(),
        );
    }

    /**
     * The icon a user's alias override names, or the shipped default.
     */
    private function resolveIcon(string $alias, string $fallback): string|BackedEnum|Markup
    {
        return ($this->iconManager ??= new IconManager())->resolve($alias) ?? $fallback;
    }

    private function isBooleanField(): bool
    {
        if ($this->cachedIsBooleanFromMetadata !== null) {
            return $this->cachedIsBooleanFromMetadata;
        }

        $table = $this->table;
        $entityManager = $table?->getQueryEntityManager();
        $recordClass = $table?->getRecordClass();

        if (!$entityManager instanceof EntityManagerInterface || $recordClass === null) {
            return $this->cachedIsBooleanFromMetadata = false;
        }

        return $this->cachedIsBooleanFromMetadata = $entityManager->getClassMetadata($recordClass)->getTypeOfField($this->getName()) === 'boolean';
    }

    /**
     * The icon is `aria-hidden`, so this is the only name the item has — and the
     * only accessible name an `<a>` wrapping it gets.
     */
    private function getTextAlternative(mixed $state, string|Markup|null $tooltip): ?string
    {
        $alternative = match (true) {
            filled($tooltip) => $tooltip,
            $this->isBoolean() => $state ? 'Yes' : 'No',
            $state instanceof HasLabel => $state->getLabel(),
            $state instanceof BackedEnum => $state->value,
            $state instanceof Markup => strip_tags((string) $state),
            is_scalar($state) || $state instanceof Stringable => $state,
            default => null,
        };

        if (blank($alternative)) {
            return null;
        }

        return mb_trim(strip_tags((string) $alternative));
    }

    /**
     * @return list<mixed>
     */
    private function getWrappedState(): array
    {
        $state = $this->getState();

        if (!is_array($state) && $state instanceof Traversable) {
            $state = iterator_to_array($state, false);
        }

        if (blank($state)) {
            return [];
        }

        return array_values(wrap($state));
    }
}
