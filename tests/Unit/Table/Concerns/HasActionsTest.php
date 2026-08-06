<?php

declare(strict_types=1);

use Sylament\Actions\Action;
use Sylament\Tables\Table\Concerns\HasActions;

/**
 * The flat action map through an object composing the trait alone — nothing on
 * `Table` reaches `cacheAction()`'s no-overwrite arm.
 */
function actionCache(): object
{
    return new class () {
        use HasActions;

        public function cache(Action $action, bool $shouldOverwriteExistingAction = true): void
        {
            $this->cacheAction($action, $shouldOverwriteExistingAction);
        }

        /**
         * @param array<string, Action> $actions
         */
        public function merge(array $actions): void
        {
            $this->mergeCachedFlatActions($actions);
        }
    };
}

it('answers null and false for an uncached name', function (): void {
    $cache = actionCache();

    expect($cache->getAction('edit'))->toBeNull()
        ->and($cache->hasAction('edit'))->toBeFalse()
        ->and($cache->getFlatActions())->toBe([]);
});

it('overwrites a cached action of the same name, unless told to keep the first', function (): void {
    $cache = actionCache();
    $first = Action::make('edit');

    $cache->cache($first);
    $cache->cache(Action::make('edit'), shouldOverwriteExistingAction: false);

    expect($cache->getAction('edit'))->toBe($first);

    $cache->cache($last = Action::make('edit'));

    expect($cache->getAction('edit'))->toBe($last)
        ->and($cache->getFlatActions())->toHaveCount(1);
});

it('merges a name-keyed set into the flat map', function (): void {
    $cache = actionCache();

    $cache->merge(['edit' => Action::make('edit'), 'delete' => Action::make('delete')]);

    expect(array_keys($cache->getFlatActions()))->toBe(['edit', 'delete'])
        ->and($cache->hasAction('delete'))->toBeTrue();
});
