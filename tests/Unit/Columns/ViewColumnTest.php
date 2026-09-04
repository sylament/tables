<?php

declare(strict_types=1);

use Sylament\Tables\Columns\ViewColumn;

it('refuses to render without a template, naming the class and the fix', function (): void {
    expect(fn (): string => ViewColumn::make('summary')->getTemplate())
        ->toThrow(LogicException::class, 'The [Sylament\Tables\Columns\ViewColumn] column has no template. Call template() on it, or declare a $template property on a subclass.');
});

it('answers the template it was given', function (): void {
    expect(ViewColumn::make('summary')->template('@TablesFixtures/partials/ViewColumnFixture/index.html.twig')->getTemplate())
        ->toBe('@TablesFixtures/partials/ViewColumnFixture/index.html.twig');
});
