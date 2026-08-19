<?php

declare(strict_types=1);

use Sylament\Tables\Filters\Indicator;
use Twig\Markup;

it('takes its label from make and evaluates a closure label', function (): void {
    expect(Indicator::make('Published')->getLabel())->toBe('Published')
        ->and(Indicator::make(fn (): string => 'Draft')->getLabel())->toBe('Draft')
        ->and((string) Indicator::make(new Markup('<b>x</b>', 'UTF-8'))->getLabel())->toBe('<b>x</b>');
});

it('is removable by default and takes a closure condition', function (): void {
    expect(Indicator::make('Published')->isRemovable())->toBeTrue()
        ->and(Indicator::make('Published')->removable(false)->isRemovable())->toBeFalse()
        ->and(Indicator::make('Published')->removable(fn (): bool => false)->isRemovable())->toBeFalse();
});

it('carries a remove field and a color', function (): void {
    $indicator = Indicator::make('Published')->removeField('value')->color('danger');

    expect($indicator->getRemoveField())->toBe('value')
        ->and($indicator->getColor())->toBe('danger')
        ->and(Indicator::make('Published')->getRemoveField())->toBeNull();
});

it('runs configure on make, unlike Filament', function (): void {
    $remove = Indicator::configureUsing(fn (Indicator $indicator): Indicator => $indicator->color('warning'));

    expect(Indicator::make('Published')->getColor())->toBe('warning');

    $remove();
});
