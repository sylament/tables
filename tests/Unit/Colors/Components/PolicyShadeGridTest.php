<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Unit\Colors\Components;

use Sylament\Support\Colors\Color;
use Sylament\Support\Colors\ColorManager;
use Sylament\Tables\Colors\Components\TextColumnComponent\ItemComponent;
use Sylament\Tables\Colors\Components\TextColumnComponent\ItemComponent\IconComponent;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use UnexpectedValueException;

use function dirname;

/**
 * `ItemComponent` is the one tables policy that resolves shades, and the two stylesheets it has to
 * agree with ship from two packages: support's `utilities.css` defines the shade classes
 * `getComponentClasses()` names, and tables' own `components/text-column.css` reads the slot
 * variables those classes set. Neither support's nor schemas' `PolicyShadeGridTest` can cover it —
 * a test one package down cannot see a tables class — and both links fail in silence: an undefined
 * class sets no variable, a variable nobody sets makes the declaration reading it invalid at
 * computed-value time, and `.sy-ta-text-item` renders in the inherited colour with nothing in the
 * console.
 */
dataset('adversarial palettes', function (): iterable {
    $shades = array_keys(ColorManager::DEFAULT_COLORS['gray']);

    yield 'uniformly dark' => [array_fill_keys($shades, 'oklch(0.15 0.05 260)')];

    yield 'pale but for the darkest shade' => [
        array_replace(array_fill_keys($shades, 'oklch(0.99 0.01 90)'), [950 => 'oklch(0.2 0.05 90)']),
    ];

    yield 'dark but for the lightest shade' => [
        array_replace(array_fill_keys($shades, 'oklch(0.15 0.05 260)'), [50 => 'oklch(0.99 0 0)']),
    ];

    foreach (ColorManager::DEFAULT_COLORS as $name => $palette) {
        yield $name => [$palette];
    }
});

function stylesheet(string $path): string
{
    $css = file_get_contents($path);

    if ($css === false) {
        throw new UnexpectedValueException("Unable to read {$path}.");
    }

    return $css;
}

function supportStylesheet(string $name): string
{
    return stylesheet(dirname(__DIR__, 5) . "/support/assets/src/styles/{$name}");
}

function textColumnStylesheet(): string
{
    return stylesheet(dirname(__DIR__, 4) . '/assets/src/styles/components/text-column.css');
}

/**
 * @param array<int, string> $palette
 * @return array{ColorManager, list<string>} the manager holding the registered palette, and the
 *                                           classes it emits for the given policy
 */
function emittedClasses(string $policy, array $palette): array
{
    $colors = new ColorManager(new ContainerBuilder());
    $colors->register(['brand' => $palette]);

    $component = $policy === ItemComponent::class ? new ItemComponent($colors) : new IconComponent();

    return [$colors, $colors->getComponentClasses($component, 'brand')];
}

/**
 * @param array<int, string> $palette
 * @return list<string> the emitted classes with the two carriers `getComponentClasses()` always
 *                      prepends removed, leaving only what the colour map resolved
 */
function itemShadeClasses(array $palette): array
{
    [, $classes] = emittedClasses(ItemComponent::class, $palette);

    return array_values(array_diff($classes, ['sy-color', 'sy-color-brand']));
}

/**
 * @return list<string> the `--sy-` slot variables the coloured-item rule reads
 */
function itemSlotVariablesRead(): array
{
    if (preg_match('/&\.sy-color\s*\{(?<declarations>[^}]*)\}/', textColumnStylesheet(), $rule) !== 1) {
        throw new UnexpectedValueException('No `&.sy-color` rule in components/text-column.css — the coloured-item branch has moved or gone.');
    }

    preg_match_all('/--sy-[a-z-]+/', $rule['declarations'], $variables);

    return $variables[0];
}

/**
 * @param list<string> $classes
 * @return list<string> the `--sy-` slot variables those classes' rules declare
 */
function slotVariablesSetBy(array $classes): array
{
    $css = supportStylesheet('utilities.css');
    $variables = [];

    foreach ($classes as $class) {
        $selector = preg_quote(str_replace(':', '\:', $class), '/');

        if (preg_match('/^\.' . $selector . '\s*\{(?<declarations>[^}]*)\}/m', $css, $rule) === 1) {
            preg_match_all('/(--sy-[a-z-]+)\s*:/', $rule['declarations'], $declared);

            $variables = [...$variables, ...$declared[1]];
        }
    }

    return $variables;
}

it('emits only shade classes that utilities.css defines', function (array $palette): void {
    $css = supportStylesheet('utilities.css');
    $shadeClasses = itemShadeClasses($palette);

    expect($shadeClasses)->not->toBeEmpty();

    foreach ($shadeClasses as $shadeClass) {
        expect($css)->toContain('.' . str_replace(':', '\:', $shadeClass) . ' {');
    }
})->with('adversarial palettes');

/**
 * The class-defined check above passes for any grid shade under any slot key, so it says nothing
 * about whether the slots are the ones `.sy-ta-text-item` reads. Rename a slot from `text` to
 * `hover:text` and every emitted class still has a rule — it just sets `--sy-hover-text`, leaving
 * `text-(--sy-text)` unset.
 */
it('sets exactly the slot variables text-column.css reads', function (array $palette): void {
    $read = itemSlotVariablesRead();
    $set = slotVariablesSetBy(itemShadeClasses($palette));

    sort($read);
    sort($set);

    expect($set)->toBe(
        $read,
        "ItemComponent's slots and the `.sy-ta-text-item.sy-color` rule disagree on which `--sy-` "
        . 'variables carry the resolved shades. A variable the rule reads and no class sets is a '
        . 'declaration the browser drops; a variable a class sets and no rule reads is a shade '
        . 'resolved for nothing.',
    );
})->with('adversarial palettes');

/**
 * `IconComponent` returns an empty map on purpose — the palette swap is the whole answer and
 * `text-column.css` picks the shade off it (`& > .sy-icon.sy-color { @apply text-color-500; }`).
 * Pinned as an exact set so a slot added later cannot land unmeasured: the moment one does, the
 * icon rule has to read it instead, and this case is what says so.
 */
it('emits only the palette swap for the icon', function (array $palette): void {
    [, $classes] = emittedClasses(IconComponent::class, $palette);

    expect($classes)->toBe(['sy-color', 'sy-color-brand']);
})->with('adversarial palettes');

/**
 * `gray` is the one colour the two policies answer differently, and the asymmetry is a decision
 * rather than an oversight: `HasDefaultGrayColor` on the icon means the stylesheet's own grey
 * stands, and its deliberate absence on the item is what keeps `.sy-color-gray` — a *visible*
 * choice on a cell — reachable at all. Pinned in both directions because neither mistake shows
 * up anywhere else: adding the contract to `ItemComponent` makes `->color('gray')` a silent
 * no-op with a dead CSS rule behind it, and removing it from `IconComponent` paints the icon a
 * palette it was told not to take.
 */
it('emits a colour for gray on the item and nothing at all on the icon', function (): void {
    $colors = new ColorManager(new ContainerBuilder());

    expect($colors->getComponentClasses(new ItemComponent($colors), 'gray'))->toContain('sy-color-gray')
        ->and($colors->getComponentClasses(new IconComponent(), 'gray'))->toBe([]);
});

/**
 * The other half of the icon's markup contract, and the same silent failure as a missing shade
 * class: the partial stamps a position class the margin rule reads, and a rule that goes missing
 * renders the icon flush against the text with nothing in the console. The Integration suite pins
 * the emission; this pins the rule.
 */
it('defines a margin rule for both icon positions the partial stamps', function (string $position): void {
    expect(textColumnStylesheet())->toContain("&.sy-ta-text-icon-{$position} {");
})->with(['before', 'after']);

/**
 * The shades the icon rules hardcode, read back out of the stylesheets rather than restated, so a
 * shade edit moves the measurement instead of quietly invalidating it.
 *
 * @return array{itemGray: array{int, int}, itemColor: int, badgeSurface: int, badgeGray: array{int, int}}
 */
function iconShades(): array
{
    $item = mb_strstr(textColumnStylesheet(), '& > .sy-icon {');

    if ($item === false) {
        throw new UnexpectedValueException('No `& > .sy-icon` rule in components/text-column.css — the item icon has moved or gone.');
    }

    if (preg_match('/@apply[^;]*\btext-gray-(?<light>\d+)\b[^;]*\bdark:text-gray-(?<dark>\d+)\b[^;]*;/', $item, $itemGray) !== 1) {
        throw new UnexpectedValueException('The item icon no longer applies a light and a dark gray shade.');
    }

    if (preg_match('/&\.sy-color\s*\{\s*@apply\s+(?<utilities>[^;]+);/', $item, $itemColor) !== 1) {
        throw new UnexpectedValueException('No `&.sy-color` branch under the item icon rule.');
    }

    if (preg_match('/^text-color-(?<shade>\d+)$/', mb_trim($itemColor['utilities']), $itemColorShade) !== 1) {
        throw new UnexpectedValueException("The coloured item icon applies [{$itemColor['utilities']}] where a single `text-color-<shade>` is measured — add the new arm to this test.");
    }

    $badge = mb_strstr(supportStylesheet('components/badge.css'), '&:not(.sy-color) {');

    if ($badge === false) {
        throw new UnexpectedValueException('No `&:not(.sy-color)` rule in support components/badge.css — the gray badge has moved or gone.');
    }

    if (preg_match('/@apply[^;]*\bbg-gray-(?<shade>\d+)\b[^;]*;/', $badge, $badgeSurface) !== 1) {
        throw new UnexpectedValueException('The gray badge no longer paints a gray background shade.');
    }

    if (preg_match('/&\s+\.sy-icon\s*\{\s*@apply\s+text-gray-(?<light>\d+)\s+dark:text-gray-(?<dark>\d+);/', $badge, $badgeGray) !== 1) {
        throw new UnexpectedValueException('The gray badge icon no longer applies a light and a dark gray shade.');
    }

    return [
        'itemGray' => [(int) $itemGray['light'], (int) $itemGray['dark']],
        'itemColor' => (int) $itemColorShade['shade'],
        'badgeSurface' => (int) $badgeSurface['shade'],
        'badgeGray' => [(int) $badgeGray['light'], (int) $badgeGray['dark']],
    ];
}

/**
 * The icons a `TextColumn` paints take their shade from a stylesheet, not from a policy —
 * `IconComponent` resolves none and the badged icon goes through the `Badge`'s own props — so no
 * contrast test upstairs reaches them, and five of the pairings miss the 3.0:1 a non-text element
 * owes. Every shade involved is Filament's (`columns/text.css`, `badge.css`), so the list is
 * pinned rather than fixed: raising them is a
 * [rule 1](../../../../../../docs/styling.md) sweep across two packages, and this goes red in
 * either direction — when a shade is raised, and when a palette lands that fails one that passes
 * today.
 *
 * Surfaces are the two the table chrome paints (`bg-white dark:bg-gray-900` on `.sy-ta-ctn`), the
 * same pair [`ItemComponent`](../../../../src/Colors/Components/TextColumnComponent/ItemComponent.php)
 * resolves its own slots against. The gray badge is measured light-only: its dark background is
 * `bg-gray-400/10`, an alpha overlay this ratio cannot express without compositing, and a browser
 * measured it at 3.14:1 — passing, so nothing failing is hidden by the omission.
 */
it('leaves the icon shades short of the non-text ratio in exactly the light-mode pairings Filament hands us', function (): void {
    $shades = iconShades();
    $gray = ColorManager::DEFAULT_COLORS['gray'];
    $surfaces = ['light' => 'oklch(1 0 0)', 'dark' => $gray[900]];

    $pairings = [];

    foreach (ColorManager::DEFAULT_COLORS as $palette => $colors) {
        foreach ($surfaces as $mode => $surface) {
            $pairings["coloured item icon · {$palette} {$mode}"] = [$surface, $colors[$shades['itemColor']]];
        }
    }

    [$itemLight, $itemDark] = $shades['itemGray'];
    $pairings['uncoloured item icon light'] = [$surfaces['light'], $gray[$itemLight]];
    $pairings['uncoloured item icon dark'] = [$surfaces['dark'], $gray[$itemDark]];

    [$badgeLight] = $shades['badgeGray'];
    $pairings['gray badge icon light'] = [$gray[$shades['badgeSurface']], $gray[$badgeLight]];

    $failures = [];

    foreach ($pairings as $label => [$surface, $icon]) {
        if (Color::calculateContrastRatio($surface, $icon) >= Color::WCAG_AA_NON_TEXT) {
            continue;
        }

        $failures[] = $label;
    }

    expect($failures)->toBe([
        'coloured item icon · primary light',
        'coloured item icon · success light',
        'coloured item icon · warning light',
        'uncoloured item icon light',
        'gray badge icon light',
    ]);
});
