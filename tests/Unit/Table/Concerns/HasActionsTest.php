<?php

declare(strict_types=1);

use Sylament\Actions\Action;
use Sylament\Actions\BulkAction;
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

        /**
         * @param array<Action> $actions
         */
        public function remove(array $actions): void
        {
            $this->removeCachedActions($actions);
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

it('sorts a cached action into the bulk map by class, not by the bulk flag', function (): void {
    $cache = actionCache();

    $cache->cache($bulk = BulkAction::make('archive'));
    $cache->cache($flagged = Action::make('publish')->bulk());

    expect($cache->getBulkAction('archive'))->toBe($bulk)
        ->and($cache->hasBulkAction('archive'))->toBeTrue()
        ->and($cache->getAction('archive'))->toBeNull()
        ->and($cache->getBulkAction('publish'))->toBeNull()
        ->and($cache->hasBulkAction('publish'))->toBeFalse()
        ->and($cache->getAction('publish'))->toBe($flagged)
        ->and(array_keys($cache->getFlatBulkActions()))->toBe(['archive']);
});

it('keeps the first bulk action of a name when told not to overwrite', function (): void {
    $cache = actionCache();

    $cache->cache($first = BulkAction::make('archive'));
    $cache->cache(BulkAction::make('archive'), shouldOverwriteExistingAction: false);

    expect($cache->getBulkAction('archive'))->toBe($first);

    $cache->cache($last = BulkAction::make('archive'));

    expect($cache->getBulkAction('archive'))->toBe($last)
        ->and($cache->getFlatBulkActions())->toHaveCount(1);
});

it('removes cached actions by identity, from both maps, leaving a same-named twin alone', function (): void {
    $cache = actionCache();

    $cache->cache($edit = Action::make('edit'));
    $cache->cache($archive = BulkAction::make('archive'));

    $cache->remove([Action::make('edit'), BulkAction::make('archive')]);

    expect($cache->getAction('edit'))->toBe($edit)
        ->and($cache->getBulkAction('archive'))->toBe($archive);

    $cache->remove([$edit, $archive]);

    expect($cache->getFlatActions())->toBe([])
        ->and($cache->getFlatBulkActions())->toBe([]);
});
