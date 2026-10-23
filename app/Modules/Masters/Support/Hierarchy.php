<?php

namespace App\Modules\Masters\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Helpers for parent_id (adjacency list) hierarchies such as the chart of accounts and item groups.
 * They work on a flat collection loaded once per company, which keeps tree operations to a single query.
 */
final class Hierarchy
{
    /** Safety valve against corrupted data; real charts are a handful of levels deep. */
    private const MAX_DEPTH = 50;

    /**
     * Nest a flat collection: each node is ['model' => Model, 'depth' => int, 'children' => list<node>].
     * Siblings keep the order of the input. A node whose parent is missing is treated as a root.
     *
     * @template TModel of Model
     *
     * @param  Collection<int, TModel>  $models
     * @return list<array{model: Model, depth: int, children: list<array<string, mixed>>}>
     */
    public static function tree(Collection $models): array
    {
        $byParent = $models->groupBy(fn (Model $m) => (int) ($m->getAttribute('parent_id') ?? 0));
        $ids = $models->pluck('id')->flip();

        $build = function (int $parentKey, int $depth) use (&$build, $byParent): array {
            if ($depth > self::MAX_DEPTH) {
                return [];
            }

            return ($byParent->get($parentKey) ?? collect())->map(fn (Model $model) => [
                'model' => $model,
                'depth' => $depth,
                'children' => $build((int) $model->getKey(), $depth + 1),
            ])->values()->all();
        };

        $roots = $build(0, 0);

        // Orphans (parent deleted or hidden): surface them as roots rather than losing them.
        foreach ($models as $model) {
            $parent = $model->getAttribute('parent_id');
            if ($parent !== null && ! $ids->has($parent)) {
                $roots[] = ['model' => $model, 'depth' => 0, 'children' => $build((int) $model->getKey(), 1)];
            }
        }

        return $roots;
    }

    /**
     * Ids of every node below $id.
     *
     * @template TModel of Model
     *
     * @param  Collection<int, TModel>  $models
     * @return list<int>
     */
    public static function descendantIds(Collection $models, int $id): array
    {
        $childrenOf = $models->groupBy(fn (Model $m) => (int) ($m->getAttribute('parent_id') ?? 0));
        $found = [];
        $queue = [$id];

        while ($queue !== [] && count($found) <= $models->count()) {
            $current = array_shift($queue);

            foreach ($childrenOf->get($current) ?? [] as $child) {
                $childId = (int) $child->getKey();

                if (! in_array($childId, $found, true)) {
                    $found[] = $childId;
                    $queue[] = $childId;
                }
            }
        }

        return $found;
    }

    /**
     * Would making $newParentId the parent of $id create a loop (itself or one of its own descendants)?
     *
     * @template TModel of Model
     *
     * @param  Collection<int, TModel>  $models
     */
    public static function wouldCreateCycle(Collection $models, int $id, ?int $newParentId): bool
    {
        if ($newParentId === null) {
            return false;
        }

        return $newParentId === $id || in_array($newParentId, self::descendantIds($models, $id), true);
    }

    /**
     * Ids of the ancestors of $id, nearest first.
     *
     * @template TModel of Model
     *
     * @param  Collection<int, TModel>  $models
     * @return list<int>
     */
    public static function ancestorIds(Collection $models, int $id): array
    {
        $byId = $models->keyBy(fn (Model $m) => (int) $m->getKey());
        $path = [];
        $current = $byId->get($id)?->getAttribute('parent_id');

        while ($current !== null && count($path) < self::MAX_DEPTH) {
            $path[] = (int) $current;
            $current = $byId->get((int) $current)?->getAttribute('parent_id');
        }

        return $path;
    }
}
