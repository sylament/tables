<?php

declare(strict_types=1);

namespace Sylament\Tables\Table\Concerns;

use BackedEnum;
use Closure;
use Sylament\Actions\Action;
use Sylament\Support\Components\Concerns\HasReorderAnimationDuration;
use Sylament\Support\Icons\IconManager;
use Sylament\Tables\Twig\TablesIconAlias;
use Symfony\Contracts\Service\Attribute\Required;
use Twig\Markup;

use function Sylament\Support\filled;

trait CanReorderRecords
{
    use HasReorderAnimationDuration;

    protected bool|Closure $isReorderable = true;

    protected bool|Closure $isReorderAuthorized = true;

    protected string|Closure|null $reorderColumn = null;

    protected string|Closure|null $reorderDirection = null;

    protected Action|Closure|null $modifyReorderRecordsTriggerActionUsing = null;

    protected ?Closure $beforeReorderingCallback = null;

    protected ?Closure $afterReorderingCallback = null;

    private ?IconManager $iconManager = null;

    #[Required]
    public function setIconManager(IconManager $iconManager): void
    {
        $this->iconManager = $iconManager;
    }

    /**
     * The column is what switches reordering on, so `$isReorderable`'s `true`
     * default stays inert until one is named. Only the condition is assigned
     * conditionally: a later `reorderable('sort')` keeps a `false` set before
     * it, while a bare `reorderable()` clears both the column and the direction.
     */
    public function reorderable(string|Closure|null $column = null, bool|Closure|null $condition = null, string|Closure|null $direction = null): static
    {
        $this->reorderColumn = $column;

        if ($condition !== null) {
            $this->isReorderable = $condition;
        }

        $this->reorderDirection = $direction;

        return $this;
    }

    public function authorizeReorder(bool|Closure $condition = true): static
    {
        $this->isReorderAuthorized = $condition;

        return $this;
    }

    public function reorderRecordsTriggerAction(?Closure $callback): static
    {
        $this->modifyReorderRecordsTriggerActionUsing = $callback;

        return $this;
    }

    public function beforeReordering(?Closure $callback): static
    {
        $this->beforeReorderingCallback = $callback;

        return $this;
    }

    public function afterReordering(?Closure $callback): static
    {
        $this->afterReorderingCallback = $callback;

        return $this;
    }

    public function getReorderColumn(): ?string
    {
        return $this->evaluate($this->reorderColumn);
    }

    public function getReorderDirection(): string
    {
        return $this->evaluate($this->reorderDirection) ?? 'asc';
    }

    public function isReorderable(): bool
    {
        return filled($this->getReorderColumn())
            && (bool) $this->evaluate($this->isReorderable)
            && $this->isReorderAuthorized();
    }

    public function isReorderAuthorized(): bool
    {
        return (bool) $this->evaluate($this->isReorderAuthorized);
    }

    public function isReordering(): bool
    {
        return $this->getLiveComponent()->isTableReordering();
    }

    public function getReorderRecordsTriggerAction(bool $isReordering): Action
    {
        $action = Action::make('reorderRecords')
            ->label($isReordering ? 'Finish reordering records' : 'Reorder records')
            ->iconButton()
            ->icon($isReordering
                ? $this->resolveReorderIcon(TablesIconAlias::ACTIONS_DISABLE_REORDERING, 'heroicons:check')
                : $this->resolveReorderIcon(TablesIconAlias::ACTIONS_ENABLE_REORDERING, 'heroicons:arrows-up-down'))
            ->color('gray')
            ->action('toggleTableReordering')
            ->table($this);

        return $this->evaluate($this->modifyReorderRecordsTriggerActionUsing, [
            'action' => $action,
            'isReordering' => $isReordering,
        ]) ?? $action;
    }

    /**
     * @param list<string> $order the record keys in their new order
     */
    public function callBeforeReordering(array $order): mixed
    {
        return $this->evaluate($this->beforeReorderingCallback, ['order' => $order]);
    }

    /**
     * @param list<string> $order the record keys in their new order
     */
    public function callAfterReordering(array $order): mixed
    {
        return $this->evaluate($this->afterReorderingCallback, ['order' => $order]);
    }

    private function resolveReorderIcon(string $alias, string $fallback): string|BackedEnum|Markup
    {
        return ($this->iconManager ??= new IconManager())->resolve($alias) ?? $fallback;
    }
}
