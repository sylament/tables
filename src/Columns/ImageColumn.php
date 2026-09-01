<?php

declare(strict_types=1);

namespace Sylament\Tables\Columns;

use BackedEnum;
use Closure;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\UnableToCheckFileExistence;
use LogicException;
use Sylament\Support\Components\Concerns\CanWrap;
use Sylament\Support\Enums\TextSize;
use Sylament\Support\Filesystem\FilesystemRegistry;
use Sylament\Support\Twig\ComponentAttributeBag;
use Symfony\Contracts\Service\Attribute\Required;
use Traversable;

use function Sylament\Support\blank;
use function Sylament\Support\filled;
use function Sylament\Support\sanitize_url;
use function Sylament\Support\wrap;

class ImageColumn extends Column
{
    use CanWrap;

    protected string|Closure|null $template = '@SylamentTables/partials/ImageColumn/index.html.twig';

    protected ?FilesystemRegistry $filesystemRegistry = null;

    protected ?FilesystemOperator $cachedDisk = null;

    protected ?string $cachedDiskName = null;

    protected string|Closure|null $diskName = null;

    protected string|Closure|null $visibility = null;

    protected int|string|Closure|null $imageHeight = null;

    protected int|string|Closure|null $imageWidth = null;

    protected bool|Closure $isCircular = false;

    protected bool|Closure $isSquare = false;

    protected bool|Closure $isStacked = false;

    protected int|Closure|null $overlap = null;

    protected int|Closure|null $ring = null;

    protected int|Closure|null $limit = null;

    protected bool|Closure $hasLimitedRemainingText = false;

    protected TextSize|string|Closure|null $limitedRemainingTextSize = null;

    protected bool|Closure $shouldCheckFileExistence = true;

    protected string|Closure|null $alt = null;

    protected string|Closure|null $defaultImageUrl = null;

    /**
     * @var array<array<mixed>|Closure>
     */
    protected array $extraImgAttributes = [];

    #[Required]
    public function setFilesystemRegistry(FilesystemRegistry $filesystemRegistry): void
    {
        $this->filesystemRegistry = $filesystemRegistry;
    }

    public function getFilesystemRegistry(): FilesystemRegistry
    {
        return $this->filesystemRegistry ?? throw new LogicException(sprintf(
            'The [%s] column was built with `new` and cannot resolve a storage disk; register it as a service so the filesystem registry reaches it.',
            $this->getName(),
        ));
    }

    public function disk(string|Closure|null $disk): static
    {
        $this->diskName = $disk;

        return $this;
    }

    public function getDiskName(): string
    {
        $name = $this->evaluate($this->diskName);

        if (filled($name)) {
            return $name;
        }

        return $this->getFilesystemRegistry()->getDefaultDiskName();
    }

    /**
     * Memoised per resolved name, not per instance: `disk()` may name a closure, so the same
     * column can answer for two storages over its lifetime.
     */
    public function getDisk(): FilesystemOperator
    {
        $name = $this->getDiskName();

        if ($this->cachedDisk instanceof FilesystemOperator && $this->cachedDiskName === $name) {
            return $this->cachedDisk;
        }

        $this->cachedDiskName = $name;

        return $this->cachedDisk = $this->getFilesystemRegistry()->get($name);
    }

    public function visibility(string|Closure|null $visibility): static
    {
        $this->visibility = $visibility;

        return $this;
    }

    /**
     * The explicitly configured visibility only — unset, each file's own visibility is read from
     * the storage when its URL is minted, not inferred from a disk name as Filament does.
     */
    public function getVisibility(): ?string
    {
        return $this->evaluate($this->visibility);
    }

    public function imageHeight(int|string|Closure|null $height): static
    {
        $this->imageHeight = $height;

        return $this;
    }

    public function getImageHeight(): ?string
    {
        $height = $this->evaluate($this->imageHeight);

        if ($height === null) {
            return null;
        }

        if (is_int($height)) {
            return "{$height}px";
        }

        return $height;
    }

    public function imageWidth(int|string|Closure|null $width): static
    {
        $this->imageWidth = $width;

        return $this;
    }

    public function getImageWidth(): ?string
    {
        $width = $this->evaluate($this->imageWidth);

        if ($width === null) {
            return null;
        }

        if (is_int($width)) {
            return "{$width}px";
        }

        return $width;
    }

    public function imageSize(int|string|Closure $size): static
    {
        $this->imageWidth($size);
        $this->imageHeight($size);

        return $this;
    }

    public function circular(bool|Closure $condition = true): static
    {
        $this->isCircular = $condition;

        return $this;
    }

    public function isCircular(): bool
    {
        return (bool) $this->evaluate($this->isCircular);
    }

    public function square(bool|Closure $condition = true): static
    {
        $this->isSquare = $condition;

        return $this;
    }

    public function isSquare(): bool
    {
        return (bool) $this->evaluate($this->isSquare);
    }

    public function stacked(bool|Closure $condition = true): static
    {
        $this->isStacked = $condition;

        return $this;
    }

    public function isStacked(): bool
    {
        return (bool) $this->evaluate($this->isStacked);
    }

    public function overlap(int|Closure|null $overlap): static
    {
        $this->overlap = $overlap;

        return $this;
    }

    public function getOverlap(): ?int
    {
        return $this->evaluate($this->overlap);
    }

    public function ring(int|Closure|null $ring): static
    {
        $this->ring = $ring;

        return $this;
    }

    public function getRing(): ?int
    {
        return $this->evaluate($this->ring);
    }

    public function limit(int|Closure|null $limit = 3): static
    {
        $this->limit = $limit;

        return $this;
    }

    public function getLimit(): ?int
    {
        return $this->evaluate($this->limit);
    }

    public function limitedRemainingText(bool|Closure $condition = true, TextSize|string|Closure|null $size = null): static
    {
        $this->hasLimitedRemainingText = $condition;
        $this->limitedRemainingTextSize($size);

        return $this;
    }

    public function hasLimitedRemainingText(): bool
    {
        return (bool) $this->evaluate($this->hasLimitedRemainingText);
    }

    public function limitedRemainingTextSize(TextSize|string|Closure|null $size): static
    {
        $this->limitedRemainingTextSize = $size;

        return $this;
    }

    public function getLimitedRemainingTextSize(): TextSize|string|null
    {
        $size = $this->evaluate($this->limitedRemainingTextSize);

        if (blank($size)) {
            return null;
        }

        if (is_string($size)) {
            return TextSize::tryFrom($size) ?? $size;
        }

        return $size;
    }

    /**
     * The class fragment templates emit for the remaining text's size: `sy-size-<value>` for a
     * `TextSize` case, a custom string as-is, null when unset — so templates never type-sniff
     * the enum-vs-string union.
     */
    public function getLimitedRemainingTextSizeClass(): ?string
    {
        $size = $this->getLimitedRemainingTextSize();

        if ($size === null) {
            return null;
        }

        if ($size instanceof BackedEnum) {
            return 'sy-size-' . $size->value;
        }

        return $size;
    }

    public function checkFileExistence(bool|Closure $condition = true): static
    {
        $this->shouldCheckFileExistence = $condition;

        return $this;
    }

    public function shouldCheckFileExistence(): bool
    {
        return (bool) $this->evaluate($this->shouldCheckFileExistence);
    }

    public function alt(string|Closure|null $alt): static
    {
        $this->alt = $alt;

        return $this;
    }

    public function getAlt(mixed $state = null): ?string
    {
        return $this->evaluate($this->alt, ['state' => $state]);
    }

    public function defaultImageUrl(string|Closure|null $url): static
    {
        $this->defaultImageUrl = $url;

        return $this;
    }

    public function getDefaultImageUrl(): ?string
    {
        return sanitize_url($this->evaluate($this->defaultImageUrl), ['http', 'https', 'data']);
    }

    /**
     * @param array<mixed>|Closure $attributes
     */
    public function extraImgAttributes(array|Closure $attributes, bool $merge = false): static
    {
        if ($merge) {
            $this->extraImgAttributes[] = $attributes;
        } else {
            $this->extraImgAttributes = [$attributes];
        }

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getExtraImgAttributes(): array
    {
        $attributeBag = new ComponentAttributeBag();

        foreach ($this->extraImgAttributes as $extraImgAttributes) {
            $attributeBag = $attributeBag->merge($this->evaluate($extraImgAttributes) ?: []);
        }

        return $attributeBag->getAttributes();
    }

    public function getExtraImgAttributeBag(): ComponentAttributeBag
    {
        return new ComponentAttributeBag($this->getExtraImgAttributes());
    }

    /**
     * An absolute or `data:` state is the URL itself; anything else is a path on the column's
     * disk, existence-checked and minted through the filesystem registry. Every emitted URL
     * passes `sanitize_url()`.
     */
    public function getImageUrl(?string $state = null): ?string
    {
        if ($state === null) {
            return null;
        }

        if (filter_var($state, FILTER_VALIDATE_URL) !== false || str_starts_with($state, 'data:')) {
            return sanitize_url($state, ['http', 'https', 'data']);
        }

        $storage = $this->getDisk();

        if ($this->shouldCheckFileExistence()) {
            try {
                if (!$storage->fileExists($state)) {
                    return null;
                }
            } catch (UnableToCheckFileExistence) {
                return null;
            }
        }

        return $this->getFilesystemRegistry()->getFileUrl($storage, $state, $this->getVisibility());
    }

    /**
     * The cell's images, one entry per state value, sliced to the limit: each value resolved to
     * a URL (a non-string or unresolvable value falls back to the default image), paired with
     * its alt text. A blank state renders the default image alone when one is set.
     *
     * @return list<array{url: ?string, alt: ?string}>
     */
    public function getStateItems(): array
    {
        $values = $this->getWrappedState();
        $limit = $this->getLimit();

        if ($this->getStateOverLimitCount() > 0) {
            $values = array_slice($values, 0, $limit);
        }

        $defaultImageUrl = $this->getDefaultImageUrl();

        return array_map(
            fn (mixed $value): array => [
                'url' => filled($value) && is_string($value)
                    ? ($this->getImageUrl($value) ?? $defaultImageUrl)
                    : $defaultImageUrl,
                'alt' => $this->getAlt($value),
            ],
            $values,
        );
    }

    public function getStateOverLimitCount(): int
    {
        $limit = $this->getLimit();

        if ($limit === null || $limit < 1) {
            return 0;
        }

        $count = count($this->getWrappedState());

        return $count > $limit ? $count - $limit : 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function getExtraTemplateData(): array
    {
        $height = $this->getImageHeight() ?? ($this->isStacked() ? '2rem' : '2.5rem');

        return [
            'height' => $height,
            'width' => $this->getImageWidth() ?? (($this->isCircular() || $this->isSquare()) ? $height : null),
        ];
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
            return filled($this->getDefaultImageUrl()) ? [null] : [];
        }

        return array_values(wrap($state));
    }
}
