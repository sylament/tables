<?php

declare(strict_types=1);

namespace Sylament\Tables\Columns;

use BackedEnum;
use Closure;
use LogicException;
use Sylament\Forms\Components\Concerns\CanDisableOptions;
use Sylament\Forms\Components\Concerns\CanSelectPlaceholder;
use Sylament\Forms\Components\Concerns\HasEnum;
use Sylament\Forms\Components\Concerns\HasExtraInputAttributes;
use Sylament\Forms\Components\Concerns\HasOptions;
use Sylament\Schemas\State\Casts\Contracts\StateCast;
use Sylament\Schemas\State\Casts\EnumStateCast;
use Sylament\Tables\Columns\Concerns\CanBeValidated;
use Sylament\Tables\Columns\Concerns\CanUpdateState;
use Sylament\Tables\Columns\Contracts\Editable;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints\Choice;
use Twig\Markup;

class SelectColumn extends Column implements Editable
{
    use CanBeValidated {
        buildValidationConstraints as buildBaseValidationConstraints;
    }
    use CanDisableOptions;
    use CanSelectPlaceholder;
    use CanUpdateState;
    use HasEnum;
    use HasExtraInputAttributes;
    use HasOptions {
        getOptions as getBaseOptions;
    }

    protected string|Closure|null $template = '@SylamentTables/partials/SelectColumn/index.html.twig';

    protected bool|Closure $areOptionsRemembered = false;

    /**
     * @var array<string|int, string|Markup|array<string|int, string|Markup>>|null
     */
    protected ?array $rememberedOptions = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->disabledClick();

        $this->placeholder('Select an option');
    }

    public function rememberOptions(bool|Closure $condition = true): static
    {
        $this->areOptionsRemembered = $condition;

        return $this;
    }

    public function areOptionsRemembered(): bool
    {
        return (bool) $this->evaluate($this->areOptionsRemembered);
    }

    /**
     * @return array<string|int, string|Markup|array<string|int, string|Markup>>
     */
    public function getOptions(): array
    {
        if (!$this->areOptionsRemembered()) {
            return $this->getBaseOptions();
        }

        return $this->rememberedOptions ??= $this->getBaseOptions();
    }

    /**
     * The enum arm's cast and nothing else: an option map's keys are the
     * vocabulary the browser posts back, so only a backed enum stands between
     * them and the record. Forms'
     * {@see CanDisableOptions::getEnabledOptionValues()} reverses the list
     * through `set()`, which is what keeps the `Choice` seed's keys strings.
     *
     * @return array<StateCast>
     */
    public function getStateCasts(): array
    {
        $enum = $this->getEnum();

        if ($enum === null) {
            return [];
        }

        $this->assertBackedEnum($enum);

        return [new EnumStateCast($enum)];
    }

    /**
     * @phpstan-assert class-string<BackedEnum> $enum
     */
    protected function assertBackedEnum(string $enum): void
    {
        if (is_a($enum, BackedEnum::class, allow_string: true)) {
            return;
        }

        throw new LogicException(sprintf(
            'The [%s] column was given the enum [%s], which is not a backed enum, so its cases cannot round-trip through a select. Back the enum with string or int values.',
            $this->getName(),
            $enum,
        ));
    }

    /**
     * The enabled option keys are string-cast, because a `<select>` posts strings
     * and `Choice` compares strictly — an integer-keyed option map would
     * otherwise refuse every selection. Rebuilt per validation so a closure's
     * option map is read at the moment the input is judged.
     *
     * @return list<Constraint>
     */
    protected function buildValidationConstraints(): array
    {
        return [
            ...$this->buildBaseValidationConstraints(),
            new Choice(choices: $this->getEnabledOptionValues()),
        ];
    }
}
