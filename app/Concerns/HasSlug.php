<?php

declare(strict_types=1);

namespace App\Concerns;

use Illuminate\Support\Str;

/**
 * Gives a model a slug route key, generated once from slugSource() when the
 * row is created, so its show route reads a readable slug instead of the id.
 *
 * The slug is never regenerated after creation, even if slugSource() would
 * later produce something different — a stable URL matters more than a slug
 * that always matches the current name.
 */
trait HasSlug
{
    /**
     * Generate the slug here rather than on a `creating` listener: seeders
     * run under `WithoutModelEvents`/`Model::withoutEvents()` so activity
     * isn't recorded for seeded data (see DatabaseSeeder), which would
     * silently skip an event-based hook and leave `slug` NULL. Overriding
     * `save()` runs regardless of whether model events are suppressed.
     */
    public function save(array $options = []): bool
    {
        if (! $this->exists && blank($this->slug)) {
            $this->slug = $this->generateUniqueSlug();
        }

        return parent::save($options);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * The route key value, falling back to one targeted lookup when this
     * instance came from a query that selected specific columns and left
     * `slug` out.
     *
     * The app has many `select(['id', ...])` reads that never anticipated a
     * slug column; requiring every one of them to list it would be a huge,
     * easy-to-miss surface. Resolving it lazily here instead means only a
     * model actually used to build a link ever pays for it, and every
     * existing narrow select keeps working unchanged.
     */
    public function getRouteKey(): mixed
    {
        if (array_key_exists('slug', $this->attributes)) {
            return $this->attributes['slug'];
        }

        return static::query()->whereKey($this->getKey())->value('slug');
    }

    /**
     * The text this model's slug is built from.
     */
    abstract protected function slugSource(): string;

    protected function generateUniqueSlug(): string
    {
        $base = Str::slug($this->slugSource());

        if ($base === '') {
            $base = Str::lower(Str::random(8));
        }

        $slug = $base;
        $suffix = 2;

        while (static::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}
