<?php

declare(strict_types=1);

use Sylament\Support\Enums\TextSize;
use Sylament\Tables\Columns\ImageColumn;

it('is plain, unstacked and existence-checking by default', function (): void {
    $column = ImageColumn::make('avatar');

    expect($column->isCircular())->toBeFalse()
        ->and($column->isSquare())->toBeFalse()
        ->and($column->isStacked())->toBeFalse()
        ->and($column->shouldCheckFileExistence())->toBeTrue()
        ->and($column->getLimit())->toBeNull()
        ->and($column->hasLimitedRemainingText())->toBeFalse()
        ->and($column->getVisibility())->toBeNull();
});

it('evaluates the boolean surface', function (): void {
    expect(ImageColumn::make('avatar')->circular()->isCircular())->toBeTrue()
        ->and(ImageColumn::make('avatar')->square(fn (): bool => true)->isSquare())->toBeTrue()
        ->and(ImageColumn::make('avatar')->stacked()->isStacked())->toBeTrue()
        ->and(ImageColumn::make('avatar')->checkFileExistence(false)->shouldCheckFileExistence())->toBeFalse();
});

it('suffixes an integer dimension with px and passes a string through', function (): void {
    expect(ImageColumn::make('avatar')->imageHeight(40)->getImageHeight())->toBe('40px')
        ->and(ImageColumn::make('avatar')->imageHeight('3rem')->getImageHeight())->toBe('3rem')
        ->and(ImageColumn::make('avatar')->getImageHeight())->toBeNull()
        ->and(ImageColumn::make('avatar')->imageWidth(24)->getImageWidth())->toBe('24px');
});

it('sets both dimensions through imageSize', function (): void {
    $column = ImageColumn::make('avatar')->imageSize('4rem');

    expect($column->getImageHeight())->toBe('4rem')
        ->and($column->getImageWidth())->toBe('4rem');
});

it('defaults the rendered height and derives the width from the shape', function (): void {
    expect(ImageColumn::make('avatar')->getExtraTemplateData())
        ->toBe(['height' => '2.5rem', 'width' => null]);

    expect(ImageColumn::make('avatar')->stacked()->getExtraTemplateData())
        ->toBe(['height' => '2rem', 'width' => null]);

    expect(ImageColumn::make('avatar')->circular()->getExtraTemplateData())
        ->toBe(['height' => '2.5rem', 'width' => '2.5rem']);

    expect(ImageColumn::make('avatar')->square()->imageHeight(64)->getExtraTemplateData())
        ->toBe(['height' => '64px', 'width' => '64px']);

    expect(ImageColumn::make('avatar')->imageWidth(24)->getExtraTemplateData())
        ->toBe(['height' => '2.5rem', 'width' => '24px']);
});

it('evaluates overlap, ring and limit', function (): void {
    expect(ImageColumn::make('avatar')->overlap(4)->getOverlap())->toBe(4)
        ->and(ImageColumn::make('avatar')->ring(2)->getRing())->toBe(2)
        ->and(ImageColumn::make('avatar')->limit(5)->getLimit())->toBe(5)
        ->and(ImageColumn::make('avatar')->limit()->getLimit())->toBe(3);
});

it('injects the state into the alt closure', function (): void {
    $column = ImageColumn::make('avatar')->alt(fn (?string $state): string => 'Photo of ' . $state);

    expect($column->getAlt('a.png'))->toBe('Photo of a.png')
        ->and(ImageColumn::make('avatar')->getAlt('a.png'))->toBeNull();
});

it('passes an absolute or data url straight through', function (): void {
    expect(ImageColumn::make('avatar')->getImageUrl('https://example.com/a.png'))->toBe('https://example.com/a.png')
        ->and(ImageColumn::make('avatar')->getImageUrl('data:image/png;base64,AAA'))->toBe('data:image/png;base64,AAA');
});

it('refuses an unsafe absolute url', function (): void {
    // `%09` decodes to a control character, which `sanitize_url()` refuses — while the URL is
    // still absolute enough to take the passthrough arm rather than the disk arm.
    expect(ImageColumn::make('avatar')->getImageUrl('https://example.com/%09a.png'))->toBeNull();
});

it('sanitizes the default image url', function (): void {
    expect(ImageColumn::make('avatar')->defaultImageUrl('javascript:alert(1)')->getDefaultImageUrl())->toBeNull()
        ->and(ImageColumn::make('avatar')->defaultImageUrl('/images/placeholder.png')->getDefaultImageUrl())->toBe('/images/placeholder.png');
});

it('throws for a disk path when built outside the container', function (): void {
    expect(fn (): ?string => ImageColumn::make('avatar')->getImageUrl('covers/a.png'))
        ->toThrow(LogicException::class, 'register it as a service');
});

it('returns null for a null state', function (): void {
    expect(ImageColumn::make('avatar')->getImageUrl())->toBeNull();
});

it('resolves one item per state value with urls and alt text', function (): void {
    $items = ImageColumn::make('photos')
        ->record(['photos' => ['https://example.com/a.png', 'https://example.com/b.png']])
        ->alt(fn (?string $state): string => 'alt:' . $state)
        ->getStateItems();

    expect($items)->toBe([
        ['url' => 'https://example.com/a.png', 'alt' => 'alt:https://example.com/a.png'],
        ['url' => 'https://example.com/b.png', 'alt' => 'alt:https://example.com/b.png'],
    ]);
});

it('slices the items to the limit and counts the remainder', function (): void {
    $column = ImageColumn::make('photos')
        ->record(['photos' => ['https://a.example/1.png', 'https://a.example/2.png', 'https://a.example/3.png', 'https://a.example/4.png']])
        ->limit(2);

    expect($column->getStateItems())->toHaveCount(2)
        ->and($column->getStateOverLimitCount())->toBe(2);

    expect(ImageColumn::make('photos')->record(['photos' => ['https://a.example/1.png']])->getStateOverLimitCount())->toBe(0);
});

it('renders nothing for a blank state without a default image', function (): void {
    $column = ImageColumn::make('avatar')->record(['avatar' => null]);

    expect($column->getStateItems())->toBe([])
        ->and($column->getStateOverLimitCount())->toBe(0);
});

it('renders the default image for a blank state', function (): void {
    expect(ImageColumn::make('avatar')->record(['avatar' => null])->defaultImageUrl('/images/placeholder.png')->getStateItems())
        ->toBe([['url' => '/images/placeholder.png', 'alt' => null]]);
});

it('falls back to the default image for an unresolvable item', function (): void {
    expect(ImageColumn::make('avatar')->record(['avatar' => 'https://example.com/%09a.png'])->defaultImageUrl('/images/placeholder.png')->getStateItems())
        ->toBe([['url' => '/images/placeholder.png', 'alt' => null]]);
});

it('mirrors the text size handling for the remaining text', function (): void {
    expect(ImageColumn::make('photos')->getLimitedRemainingTextSize())->toBeNull()
        ->and(ImageColumn::make('photos')->limitedRemainingText(size: 'lg')->getLimitedRemainingTextSize())->toBe(TextSize::Large)
        ->and(ImageColumn::make('photos')->limitedRemainingText(size: 'sy-custom')->getLimitedRemainingTextSize())->toBe('sy-custom')
        ->and(ImageColumn::make('photos')->limitedRemainingText()->hasLimitedRemainingText())->toBeTrue();
});

it('emits a size class fragment for the remaining text', function (): void {
    expect(ImageColumn::make('photos')->getLimitedRemainingTextSizeClass())->toBeNull()
        ->and(ImageColumn::make('photos')->limitedRemainingTextSize(TextSize::Large)->getLimitedRemainingTextSizeClass())->toBe('sy-size-lg')
        ->and(ImageColumn::make('photos')->limitedRemainingTextSize('sy-custom')->getLimitedRemainingTextSizeClass())->toBe('sy-custom');
});

it('merges or replaces the extra img attributes', function (): void {
    $merged = ImageColumn::make('avatar')
        ->extraImgAttributes(['loading' => 'lazy'])
        ->extraImgAttributes(['decoding' => 'async'], merge: true)
        ->getExtraImgAttributes();

    $replaced = ImageColumn::make('avatar')
        ->extraImgAttributes(['loading' => 'lazy'])
        ->extraImgAttributes(['decoding' => 'async'])
        ->getExtraImgAttributes();

    // Later registrations land first and an earlier key wins a collision — the order Filament's
    // own defaults-first merge loop produces.
    expect($merged)->toBe(['decoding' => 'async', 'loading' => 'lazy'])
        ->and($replaced)->toBe(['decoding' => 'async']);
});
