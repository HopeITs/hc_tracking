<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Every slug of a HasSlug model, read in one query the first time a link to a
 * model loaded without its `slug` column is built, then reused for the rest of
 * the request.
 *
 * List pages load narrow `select()`s and link every row, so resolving one slug
 * at a time ran one query per link (140+ on the milestones and proposals
 * pages). Scoped in AppServiceProvider: one instance per request or job.
 */
final class RouteSlugs
{
    /**
     * @var array<class-string<Model>, array<int|string, string|null>>
     */
    private array $slugsByModel = [];

    /**
     * The slug of the given model's row, or null when the row has none.
     */
    public function for(Model $model): ?string
    {
        $key = $model->getKey();
        $slugs = $this->slugsByModel[$model::class] ??= $model->newQuery()->pluck('slug', $model->getKeyName())->all();

        if (! array_key_exists($key, $slugs)) {
            $slugs[$key] = $model->newQuery()->whereKey($key)->value('slug');
            $this->slugsByModel[$model::class][$key] = $slugs[$key];
        }

        return $slugs[$key];
    }
}
