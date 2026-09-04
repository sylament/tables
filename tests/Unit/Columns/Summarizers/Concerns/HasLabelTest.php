<?php

declare(strict_types=1);

use Sylament\Tables\Columns\Summarizers\Summarizer;
use Twig\Markup;

it('carries no default label on the base and takes one from label()', function (): void {
    expect(Summarizer::make()->getLabel())->toBeNull()
        ->and(Summarizer::make()->getDefaultLabel())->toBeNull()
        ->and(Summarizer::make()->label('Total')->getLabel())->toBe('Total')
        ->and(Summarizer::make()->label(fn (): string => 'Computed')->getLabel())->toBe('Computed');
});

/*
 * A blank label is how a caller drops the default one a concrete summarizer carries,
 * which is a different answer from never having set one.
 */
it('reads a blank label as no label at all', function (): void {
    expect(Summarizer::make()->label('')->getLabel())->toBeNull()
        ->and(Summarizer::make()->label('  ')->getLabel())->toBeNull();
});

it('keeps a markup label unescaped', function (): void {
    $label = new Markup('<b>Total</b>', 'UTF-8');

    expect(Summarizer::make()->label($label)->getLabel())->toBe($label);
});

it('hides a label without dropping it', function (): void {
    expect(Summarizer::make()->label('Total')->isLabelHidden())->toBeFalse()
        ->and(Summarizer::make()->label('Total')->hiddenLabel()->isLabelHidden())->toBeTrue()
        ->and(Summarizer::make()->label('Total')->hiddenLabel()->getLabel())->toBe('Total')
        ->and(Summarizer::make()->label('Total')->hiddenLabel(fn (): bool => false)->isLabelHidden())->toBeFalse();
});
