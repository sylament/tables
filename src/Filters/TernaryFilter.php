<?php

declare(strict_types=1);

namespace Sylament\Tables\Filters;

use Closure;
use Doctrine\ORM\QueryBuilder;
use Sylament\Forms\Components\Select;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;

use function Sylament\Support\blank;

class TernaryFilter extends SelectFilter
{
    protected string|TranslatableInterface|Closure|null $falseLabel = null;

    protected string|TranslatableInterface|Closure|null $trueLabel = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->trueLabel(new TranslatableMessage('components.select.boolean.true', domain: 'sylament-forms'));
        $this->falseLabel(new TranslatableMessage('components.select.boolean.false', domain: 'sylament-forms'));
        $this->placeholder('-');

        $this->boolean();

        $this->indicateUsing(function (array $state): array {
            if (blank($state['value'] ?? null)) {
                return [];
            }

            $stateLabel = $state['value'] ? $this->getTrueLabel() : $this->getFalseLabel();

            return [$this->makeStateIndicator($this->getIndicator(), (string) $stateLabel)];
        });
    }

    public function trueLabel(string|TranslatableInterface|Closure|null $label): static
    {
        $this->trueLabel = $label;

        return $this;
    }

    public function falseLabel(string|TranslatableInterface|Closure|null $label): static
    {
        $this->falseLabel = $label;

        return $this;
    }

    public function getTrueLabel(): ?string
    {
        $label = $this->translateText($this->evaluate($this->trueLabel));

        return $label === null ? null : (string) $label;
    }

    public function getFalseLabel(): ?string
    {
        $label = $this->translateText($this->evaluate($this->falseLabel));

        return $label === null ? null : (string) $label;
    }

    public function getFormField(): Select
    {
        return parent::getFormField()->boolean(
            trueLabel: $this->getTrueLabel(),
            falseLabel: $this->getFalseLabel(),
            placeholder: $this->getPlaceholder(),
        );
    }

    public function boolean(): static
    {
        return $this->queries(
            true: function (QueryBuilder $query): void {
                $parameter = $this->bindQueryParameter($query, true);

                $query->andWhere($query->expr()->eq($this->getAttributePath($query), ":{$parameter}"));
            },
            false: function (QueryBuilder $query): void {
                $parameter = $this->bindQueryParameter($query, false);

                $query->andWhere($query->expr()->eq($this->getAttributePath($query), ":{$parameter}"));
            },
        );
    }

    public function nullable(): static
    {
        return $this->queries(
            true: function (QueryBuilder $query): void {
                $query->andWhere($query->expr()->isNotNull($this->getAttributePath($query)));
            },
            false: function (QueryBuilder $query): void {
                $query->andWhere($query->expr()->isNull($this->getAttributePath($query)));
            },
        );
    }

    /**
     * The three-arm dispatcher installed as this filter's query callback: a blank
     * value takes the optional blank arm and otherwise leaves the query alone,
     * and any other value picks an arm by truthiness. Each arm is called with the
     * builder and the state; its return value is discarded, like every other
     * query callback here.
     */
    public function queries(Closure $true, Closure $false, ?Closure $blank = null): static
    {
        $this->query(function (QueryBuilder $query, array $data) use ($blank, $false, $true): void {
            if (blank($data['value'] ?? null)) {
                if ($blank instanceof Closure) {
                    $blank($query, $data);
                }

                return;
            }

            if ($data['value']) {
                $true($query, $data);

                return;
            }

            $false($query, $data);
        });

        return $this;
    }

    public function getDefaultState(): mixed
    {
        $defaultState = parent::getDefaultState();

        if (is_bool($defaultState)) {
            return $defaultState ? 1 : 0;
        }

        return $defaultState;
    }
}
