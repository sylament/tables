<?php

declare(strict_types=1);

use Sylament\Schemas\Exceptions\ValidationException;
use Sylament\Tables\Columns\Column;
use Sylament\Tables\Columns\SelectColumn;
use Sylament\Tables\Columns\TextInputColumn;
use Sylament\Tables\Columns\ToggleColumn;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\TableHost;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Song;
use Sylament\Tables\Tests\Fixtures\Doctrine\EntityManagerFactory;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Type;
use Symfony\Component\Validator\Validation;

/*
 * The tables-tier validation seam, outside the container: the validator is set
 * by hand where the `#[Required]` setter would have supplied it, so a column
 * built with `new` also proves the throw a missing registration earns.
 */

function editableColumnTable(): Table
{
    $entityManager = EntityManagerFactory::create();

    return new TableHost(fn (Table $table): Table => $table
        ->query($entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song')))->getTable();
}

/**
 * A column mounted on a query-lane table over `Song`, holding one record and a
 * real validator.
 *
 * @template TColumn of Column
 * @param TColumn $column
 * @return TColumn
 */
function validatableColumn(Column $column, ?Song $record = null): Column
{
    $column->table(editableColumnTable())->record($record ?? new Song(1, 'Airbag', 100));
    $column->setValidator(Validation::createValidator());

    return $column;
}

it('refuses to validate at all without a validator', function (): void {
    expect(fn () => TextInputColumn::make('title')->validate('Airbag'))
        ->toThrow(LogicException::class, 'register it as a service');
});

it('accepts input that clears every declared constraint', function (): void {
    $column = validatableColumn(TextInputColumn::make('title')->constraint(new Length(min: 3)));

    expect(fn () => $column->validate('Airbag'))->not->toThrow(ValidationException::class);
});

it('throws the schemas validation exception keyed by the column name', function (): void {
    $column = validatableColumn(TextInputColumn::make('title')->constraint(new Length(min: 3)));

    try {
        $column->validate('ab');
    } catch (ValidationException $validationException) {
        expect(array_keys($validationException->getErrors()))->toBe(['title'])
            ->and($validationException->getErrors()['title'][0])->toContain('3 characters');

        return;
    }

    throw new LogicException('The short input was not refused.');
});

it('skips a content constraint on a blank string, keeping the implicit one', function (): void {
    $lengthOnly = validatableColumn(TextInputColumn::make('mood')->constraint(new Length(min: 3)));
    $withPresence = validatableColumn(TextInputColumn::make('mood')->constraints([new NotBlank(), new Length(min: 3)]));

    expect(fn () => $lengthOnly->validate(''))->not->toThrow(ValidationException::class)
        ->and(fn () => $withPresence->validate(''))->toThrow(ValidationException::class);
});

it('honours a condition on a declared constraint', function (): void {
    $off = validatableColumn(TextInputColumn::make('title')->constraint(new Length(min: 30), false));
    $on = validatableColumn(TextInputColumn::make('title')->constraint(new Length(min: 30), fn (): bool => true));

    expect(fn () => $off->validate('ab'))->not->toThrow(ValidationException::class)
        ->and(fn () => $on->validate('ab'))->toThrow(ValidationException::class);
});

it('evaluates a closure that answers with constraints', function (): void {
    $column = validatableColumn(TextInputColumn::make('title')->constraints(fn (): array => [new Length(min: 30)]));

    expect(fn () => $column->validate('ab'))->toThrow(ValidationException::class);
});

it('replaces a constraint message through validationMessages', function (): void {
    $column = validatableColumn(
        TextInputColumn::make('title')
            ->constraint(new Length(min: 3))
            ->validationMessages([Length::class => ['minMessage' => 'Far too short.']]),
    );

    try {
        $column->validate('ab');
    } catch (ValidationException $validationException) {
        expect($validationException->getErrors()['title'])->toBe(['Far too short.']);

        return;
    }

    throw new LogicException('The short input was not refused.');
});

it('refuses a validation message option the constraint does not carry', function (): void {
    $column = validatableColumn(
        TextInputColumn::make('title')
            ->constraint(new Length(min: 3))
            ->validationMessages([Length::class => ['nonesuchMessage' => 'Nope.']]),
    );

    expect(fn () => $column->validate('ab'))
        ->toThrow(LogicException::class, 'has no [nonesuchMessage] message option');
});

it('prepends a presence constraint when the record rejects null at the path', function (): void {
    $nullable = validatableColumn(SelectColumn::make('mood')->options(['calm' => 'Calm']));
    $nonNullable = validatableColumn(SelectColumn::make('tempo')->options(['slow' => 'Slow']));

    expect(fn () => $nullable->validate(''))->not->toThrow(ValidationException::class)
        ->and(fn () => $nonNullable->validate(''))->toThrow(ValidationException::class);
});

it('leaves the presence arm to a declared presence constraint', function (): void {
    $column = validatableColumn(
        SelectColumn::make('tempo')
            ->options(['slow' => 'Slow'])
            ->constraint(new NotBlank(message: 'Pick a tempo.')),
    );

    try {
        $column->validate('');
    } catch (ValidationException $validationException) {
        expect($validationException->getErrors()['tempo'])->toBe(['Pick a tempo.']);

        return;
    }

    throw new LogicException('The blank input was not refused.');
});

it('has no presence arm without a record class to read a type off', function (): void {
    $onRecords = TextInputColumn::make('tempo');
    $onRecords->table(new TableHost(fn (Table $table): Table => $table->records(fn (): array => []))->getTable());

    expect($onRecords->getValidationConstraints())->toBe([])
        ->and(validatableColumn(TextInputColumn::make('tempo'))->getValidationConstraints())->toHaveCount(2);
});

it('seeds a boolean type constraint on the toggle column', function (): void {
    $column = validatableColumn(ToggleColumn::make('isFeatured'));

    expect(fn () => $column->validate(true))->not->toThrow(ValidationException::class)
        ->and(fn () => $column->validate('yes'))->toThrow(ValidationException::class);
});

it('seeds a choice over the enabled option keys, as the browser posts them', function (): void {
    $column = validatableColumn(
        SelectColumn::make('mood')
            ->options([1 => 'Calm', 2 => 'Wild'])
            ->disableOptionWhen(fn (mixed $value): bool => $value === 2),
    );

    expect(fn () => $column->validate('1'))->not->toThrow(ValidationException::class)
        ->and(fn () => $column->validate('2'))->toThrow(ValidationException::class)
        ->and(fn () => $column->validate('9'))->toThrow(ValidationException::class);
});

it('rebuilds the choice seed per validation, so a changed option map is honoured', function (): void {
    $allowed = ['calm'];
    $column = validatableColumn(SelectColumn::make('mood')->options(function () use (&$allowed): array {
        return array_combine($allowed, $allowed);
    }));

    expect(fn () => $column->validate('wild'))->toThrow(ValidationException::class);

    $allowed = ['calm', 'wild'];

    expect(fn () => $column->validate('wild'))->not->toThrow(ValidationException::class);
});

it('seeds a numeric type constraint from an integer record property', function (): void {
    $column = validatableColumn(TextInputColumn::make('plays'));

    expect(fn () => $column->validate('12'))->not->toThrow(ValidationException::class)
        ->and(fn () => $column->validate('abc'))->toThrow(ValidationException::class);
});

it('seeds a string type constraint from a string record property', function (): void {
    $column = validatableColumn(TextInputColumn::make('mood'));

    expect(fn () => $column->validate('calm'))->not->toThrow(ValidationException::class)
        ->and(fn () => $column->validate(['x' => 'y']))->toThrow(ValidationException::class);
});

it('leaves a boolean record property unseeded, so a string-posting column still writes', function (): void {
    $textInput = validatableColumn(TextInputColumn::make('isExplicit'));
    $select = validatableColumn(SelectColumn::make('isExplicit')->options([1 => 'Yes', 0 => 'No']));

    expect($textInput->getValidationConstraints())->toBe([])
        ->and(fn () => $select->validate('1'))->not->toThrow(ValidationException::class);
});

it('seeds no type arm for an object or enum record property', function (): void {
    expect(validatableColumn(TextInputColumn::make('format'))->getValidationConstraints())->toBe([])
        ->and(validatableColumn(TextInputColumn::make('artist'))->getValidationConstraints())->toBe([]);
});

it('leaves the type arm to a declared type constraint', function (): void {
    $declared = validatableColumn(TextInputColumn::make('plays')->constraint(new Type('string')));
    $seeded = validatableColumn(ToggleColumn::make('isFeatured'));

    $types = array_values(array_filter(
        $declared->getValidationConstraints(),
        fn (object $constraint): bool => $constraint instanceof Type,
    ));

    expect($types)->toHaveCount(1)
        ->and($types[0]->type)->toBe('string')
        ->and($seeded->getValidationConstraints())->toHaveCount(1);
});
