<?php

declare(strict_types=1);

use Sylament\Forms\Components\Checkbox;
use Sylament\Forms\Components\Field;
use Sylament\Schemas\Components\Component;
use Sylament\Tables\Filters\BaseFilter;
use Sylament\Tables\Tests\Fixtures\Filters\FieldFilter;

it('has no schema components on the bare base', function (): void {
    expect(BaseFilter::make('status')->getSchemaComponents())->toBe([])
        ->and(BaseFilter::make('status')->hasSchema())->toBeFalse()
        ->and(BaseFilter::make('status')->getFormField())->toBeNull();
});

it('lets an explicit schema win outright and skips the field modifier', function (): void {
    $filter = FieldFilter::make('status')
        ->schema([Checkbox::make('custom')])
        ->modifyFormFieldUsing(fn (Field $field): Field => $field->label('Never'));

    $components = $filter->getSchemaComponents();

    expect($filter->hasSchema())->toBeTrue()
        ->and($components)->toHaveCount(1)
        ->and($components[0]->getName())->toBe('custom')
        ->and($components[0]->getLabel())->toBe('Custom');
});

it('passes the form field through the modifier with named and typed injections', function (): void {
    expect(FieldFilter::make('status')->modifyFormFieldUsing(fn (Field $field): Field => $field->label('Typed'))->getSchemaComponents()[0]->getLabel())->toBe('Typed')
        ->and(FieldFilter::make('status')->modifyFormFieldUsing(fn ($field): Field => $field->label('Named'))->getSchemaComponents()[0]->getLabel())->toBe('Named')
        ->and(FieldFilter::make('status')->modifyFormFieldUsing(fn (Component $field): Component => $field->label('Component'))->getSchemaComponents()[0]->getLabel())->toBe('Component')
        ->and(FieldFilter::make('status')->modifyFormFieldUsing(fn (Checkbox $field): Checkbox => $field->label('Concrete'))->getSchemaComponents()[0]->getLabel())->toBe('Concrete');
});

it('keeps the unmodified field when the modifier returns null', function (): void {
    $components = FieldFilter::make('status')->modifyFormFieldUsing(function (Field $field): void {
        $field->label('Mutated');
    })->getSchemaComponents();

    expect($components[0]->getLabel())->toBe('Mutated');
});
