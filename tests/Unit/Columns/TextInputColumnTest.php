<?php

declare(strict_types=1);

use Sylament\Tables\Columns\Contracts\Editable;
use Sylament\Tables\Columns\TextInputColumn;
use Twig\Markup;

it('is editable, click-disabled and typed text out of the box', function (): void {
    $column = TextInputColumn::make('title');

    expect($column)->toBeInstanceOf(Editable::class)
        ->and($column->isClickDisabled())->toBeTrue()
        ->and($column->getType())->toBe('text')
        ->and($column->getInputMode())->toBeNull()
        ->and($column->getStep())->toBeNull()
        ->and($column->getValidationConstraints())->toBe([]);
});

it('reads the type and input mode from a literal and a closure', function (): void {
    expect(TextInputColumn::make('title')->type('number')->inputMode(fn (): string => 'numeric'))
        ->getType()->toBe('number')
        ->getInputMode()->toBe('numeric');
});

it('sets an affix inline through its second argument and through the inline setter', function (): void {
    $viaArgument = TextInputColumn::make('title')->prefix('#', isInline: true)->suffix('kg', isInline: true);
    $viaSetter = TextInputColumn::make('title')->prefix('#')->suffix('kg')->inlinePrefix()->inlineSuffix();
    $plain = TextInputColumn::make('title')->prefix('#')->suffix('kg');

    expect($viaArgument->isPrefixInline())->toBeTrue()
        ->and($viaArgument->isSuffixInline())->toBeTrue()
        ->and($viaSetter->isPrefixInline())->toBeTrue()
        ->and($viaSetter->isSuffixInline())->toBeTrue()
        ->and($plain->isPrefixInline())->toBeFalse()
        ->and($plain->isSuffixInline())->toBeFalse()
        ->and($plain->getPrefixLabel())->toBe('#')
        ->and($plain->getSuffixLabel())->toBe('kg');
});

it('keeps an affix label markup rather than flattening it', function (): void {
    $label = new Markup('<b>kg</b>', 'UTF-8');

    expect(TextInputColumn::make('title')->suffix($label)->getSuffixLabel())->toBe($label);
});

it('carries the affix icons and their colours', function (): void {
    $column = TextInputColumn::make('title')
        ->prefixIcon('heroicons:check-circle', isInline: true)
        ->prefixIconColor('success')
        ->suffixIcon(fn (): string => 'heroicons:x-circle')
        ->suffixIconColor(fn (): array => ['500' => '#fff']);

    expect($column->getPrefixIcon())->toBe('heroicons:check-circle')
        ->and($column->isPrefixInline())->toBeTrue()
        ->and($column->getPrefixIconColor())->toBe('success')
        ->and($column->getSuffixIcon())->toBe('heroicons:x-circle')
        ->and($column->getSuffixIconColor())->toBe(['500' => '#fff'])
        ->and($column->isSuffixInline())->toBeFalse();
});

it('merges extra input attributes onto the control', function (): void {
    $column = TextInputColumn::make('title')
        ->extraInputAttributes(['data-role' => 'title'])
        ->extraInputAttributes(fn (): array => ['data-row' => 'one'], merge: true);

    expect($column->getExtraInputAttributes())->toMatchArray(['data-role' => 'title', 'data-row' => 'one']);
});
