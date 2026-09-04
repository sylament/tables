<?php

declare(strict_types=1);

namespace Sylament\Tables\Columns\Concerns;

use Closure;
use LogicException;
use ReflectionObject;
use ReflectionProperty;
use Sylament\Forms\Validation\RequiredValue;
use Sylament\Schemas\Exceptions\ValidationException;
use Sylament\Schemas\Validation\Contracts\ImplicitConstraint;
use Sylament\Schemas\Validation\PresenceGate;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Constraints\Type;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Service\Attribute\Required;

trait CanBeValidated
{
    /**
     * @var array<array{Constraint|array<Constraint>|Closure|null, bool|Closure}>
     */
    protected array $constraints = [];

    /**
     * @var array<class-string<Constraint>, string|Closure|array<string, string|Closure>>
     */
    protected array $validationMessages = [];

    private ValidatorInterface $validator;

    #[Required]
    public function setValidator(ValidatorInterface $validator): void
    {
        $this->validator = $validator;
    }

    public function constraint(Constraint|Closure $constraint, bool|Closure $condition = true): static
    {
        $this->constraints[] = [$constraint, $condition];

        return $this;
    }

    /**
     * @param array<Constraint|Closure>|Closure $constraints
     */
    public function constraints(array|Closure $constraints, bool|Closure $condition = true): static
    {
        if ($constraints instanceof Closure) {
            $this->constraints[] = [$constraints, $condition];

            return $this;
        }

        foreach ($constraints as $constraint) {
            $this->constraints[] = [$constraint, $condition];
        }

        return $this;
    }

    /**
     * @param array<class-string<Constraint>, string|Closure|array<string, string|Closure>> $messages
     */
    public function validationMessages(array $messages): static
    {
        $this->validationMessages = $messages;

        return $this;
    }

    /**
     * Message options per constraint class, normalised: a string value becomes the
     * constraint's `message` option. A value resolving to '' is dropped so the
     * constraint keeps its own default.
     *
     * @return array<class-string<Constraint>, array<string, string>>
     */
    public function getValidationMessages(): array
    {
        $messages = [];

        foreach ($this->validationMessages as $constraintClass => $options) {
            $evaluated = array_filter(array_map(
                fn (string|Closure $message): string => $this->evaluate($message),
                is_array($options) ? $options : ['message' => $options],
            ));

            if ($evaluated !== []) {
                $messages[$constraintClass] = $evaluated;
            }
        }

        return $messages;
    }

    /**
     * @return list<Constraint>
     */
    public function getValidationConstraints(): array
    {
        return $this->applyValidationMessages($this->buildValidationConstraints());
    }

    public function validate(mixed $input): void
    {
        $violations = $this->getValidator()->validate(
            $input,
            PresenceGate::apply($input, $this->getValidationConstraints()),
        );

        $messages = [];

        foreach ($violations as $violation) {
            $messages[] = (string) $violation->getMessage();
        }

        if ($messages === []) {
            return;
        }

        throw new ValidationException([$this->getName() => $messages]);
    }

    public function getValidator(): ValidatorInterface
    {
        return $this->validator ?? throw new LogicException(sprintf(
            'The editable column [%s] validates its input, which needs the container-built instance — register it as a service so the validator is injected.',
            $this->getName(),
        ));
    }

    /**
     * @return list<Constraint>
     */
    protected function buildValidationConstraints(): array
    {
        $constraints = [];

        foreach ($this->constraints as [$constraint, $condition]) {
            if (!$this->evaluate($condition)) {
                continue;
            }

            $evaluated = $this->evaluate($constraint);

            if ($evaluated === null) {
                continue;
            }

            foreach ($evaluated instanceof Constraint ? [$evaluated] : $evaluated as $single) {
                $constraints[] = $single;
            }
        }

        if ($this->isRequiredByRecord() && !self::hasPresenceConstraint($constraints)) {
            array_unshift($constraints, new RequiredValue());
        }

        $recordType = self::hasTypeConstraint($constraints) ? null : $this->getRecordTypeConstraint();

        if ($recordType instanceof Type) {
            array_unshift($constraints, $recordType);
        }

        return $constraints;
    }

    /**
     * @param  list<Constraint> $constraints
     * @return list<Constraint>
     */
    protected function applyValidationMessages(array $constraints): array
    {
        $messages = $this->getValidationMessages();

        if ($messages === []) {
            return $constraints;
        }

        foreach ($constraints as $index => $constraint) {
            $options = $messages[$constraint::class] ?? [];

            if ($options === []) {
                continue;
            }

            $available = self::getValidationMessageOptions($constraint);
            $constraint = clone $constraint;

            foreach ($options as $option => $message) {
                if (!in_array($option, $available, true)) {
                    throw new LogicException(sprintf(
                        'Sylament cannot apply a validation message to the column [%s] because %s has no [%s] message option. Use one of: [%s].',
                        $this->getName(),
                        $constraint::class,
                        $option,
                        implode('], [', $available),
                    ));
                }

                $constraint->{$option} = $message;
            }

            $constraints[$index] = $constraint;
        }

        return $constraints;
    }

    /**
     * Whether the record's own write-side type at this column's path refuses the
     * null a blank cell writes — so the blank is a refusal shown under the
     * control rather than the type error the write would otherwise raise. False
     * on a lane with no record class to read a type off.
     */
    protected function isRequiredByRecord(): bool
    {
        $recordClass = isset($this->table) ? $this->getTable()->getRecordClass() : null;

        if ($recordClass === null) {
            return false;
        }

        return !$this->getPropertyAccessor()->acceptsNullOnRecord($recordClass, $this->getName());
    }

    /**
     * The type constraint the record's own declared type at this column's path
     * asks for, so a value that type refuses is a refusal painted under the
     * control rather than the write's type error. An `int` or `float` asks for
     * `numeric` rather than its own name: the accessor coerces a numeric string,
     * which is the only shape a control ever posts. A `bool` asks for nothing on
     * those same grounds: only a checkbox posts a JSON boolean, and the two
     * columns whose control does seed their own `Type('bool')`. An enum, an
     * object, a collection and an unreadable type ask for nothing either — the
     * enum arm is the option `Choice` and the state cast's. Null on a lane with
     * no record class to read a type off.
     */
    protected function getRecordTypeConstraint(): ?Type
    {
        $recordClass = isset($this->table) ? $this->getTable()->getRecordClass() : null;

        if ($recordClass === null) {
            return null;
        }

        $type = match ($this->getPropertyAccessor()->getBuiltinTypeOnRecord($recordClass, $this->getName())) {
            'int', 'float' => 'numeric',
            'string' => 'string',
            default => null,
        };

        return $type === null ? null : new Type($type);
    }

    /**
     * @param list<Constraint> $constraints
     */
    private static function hasTypeConstraint(array $constraints): bool
    {
        return array_any($constraints, static fn (Constraint $constraint): bool => $constraint instanceof Type);
    }

    /**
     * @param list<Constraint> $constraints
     */
    private static function hasPresenceConstraint(array $constraints): bool
    {
        return array_any($constraints, static fn (Constraint $constraint): bool => $constraint instanceof ImplicitConstraint
            || $constraint instanceof NotBlank
            || $constraint instanceof NotNull);
    }

    /**
     * @return list<string>
     */
    private static function getValidationMessageOptions(Constraint $constraint): array
    {
        $options = [];

        foreach (new ReflectionObject($constraint)->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $name = $property->getName();

            if ($name === 'message' || str_ends_with($name, 'Message')) {
                $options[] = $name;
            }
        }

        return $options;
    }
}
