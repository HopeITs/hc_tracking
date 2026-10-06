<?php

declare(strict_types=1);

namespace App\Concerns;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Adjacency-list tree behaviour for a model that references itself through a
 * single nullable foreign key.
 *
 * The using model declares the column with parentKeyName(). Traversal is plain
 * Eloquent rather than a recursive CTE: the trees here are shallow — three
 * levels for a logframe, two for milestones — so one query per level is
 * cheaper to own than driver-specific SQL.
 *
 * Integrity is enforced by an observer per model, not here; this trait only
 * supplies the cycle test the observers ask.
 */
trait HasTree
{
    /**
     * The foreign key column pointing at the parent row.
     */
    abstract public static function parentKeyName(): string;

    /**
     * The node this one is nested under.
     *
     * @return BelongsTo<static, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(static::class, static::parentKeyName());
    }

    /**
     * The column siblings are ordered by, or null to leave them unordered.
     *
     * Overridden by models that carry an explicit ordering column. Because
     * childrenRecursive() builds on children(), overriding this orders the
     * whole subtree, not just the first level.
     */
    public static function childrenOrderColumn(): ?string
    {
        return null;
    }

    /**
     * The nodes nested directly under this one.
     *
     * @return HasMany<static, $this>
     */
    public function children(): HasMany
    {
        $children = $this->hasMany(static::class, static::parentKeyName());
        $orderColumn = static::childrenOrderColumn();

        return $orderColumn === null
            ? $children
            : $children->orderBy($orderColumn);
    }

    /**
     * Children that carry their own children, all the way down.
     *
     * Costs one query per level of the tree, so eager-load it once at the root
     * rather than touching it per node:
     * Result::roots()->with('childrenRecursive')->get()
     *
     * @return HasMany<static, $this>
     */
    public function childrenRecursive(): HasMany
    {
        return $this->children()->with('childrenRecursive');
    }

    /**
     * The parent that carries its own parent, all the way up.
     *
     * @return BelongsTo<static, $this>
     */
    public function parentRecursive(): BelongsTo
    {
        return $this->parent()->with('parentRecursive');
    }

    /**
     * Every node above this one, nearest parent first, root last.
     *
     * Costs one query per level. To build breadcrumbs for many nodes at once,
     * eager-load parentRecursive instead of calling this in a loop.
     *
     * @return Collection<int, self>
     */
    public function ancestors(): Collection
    {
        $ancestors = new Collection;
        $node = $this;

        while (($node = $node->parent) !== null) {
            $ancestors->push($node);
        }

        return $ancestors;
    }

    /**
     * Every node in this one's subtree, in no guaranteed order.
     *
     * Reads childrenRecursive, so a subtree already eager-loaded costs no
     * further queries. An unsaved node short-circuits: querying the parent
     * column for NULL would otherwise return every root in the table.
     *
     * @return Collection<int, self>
     */
    public function descendants(): Collection
    {
        $descendants = new Collection;

        if ($this->getKey() === null) {
            return $descendants;
        }

        foreach ($this->childrenRecursive as $child) {
            $descendants->push($child);

            foreach ($child->descendants() as $descendant) {
                $descendants->push($descendant);
            }
        }

        return $descendants;
    }

    /**
     * How many levels sit between this node and its root. Zero at a root.
     */
    public function depth(): int
    {
        return $this->ancestors()->count();
    }

    /**
     * Whether adopting the given node as parent would close a loop.
     *
     * Walks UP from the candidate parent rather than down from this node: that
     * is one query per level instead of one per subtree node, and it reads the
     * stored rows rather than any relation loaded earlier, so a stale
     * childrenRecursive cannot wave a cycle through.
     */
    public function wouldCycleUnder(int|string $parentKey): bool
    {
        if ($this->getKey() === null) {
            return false;
        }

        $node = static::query()->find($parentKey);

        while ($node !== null) {
            if ($node->getKey() === $this->getKey()) {
                return true;
            }

            $node = $node->parent;
        }

        return false;
    }

    /**
     * Limit the query to nodes at the top of their tree.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function roots(Builder $query): void
    {
        $query->whereNull(static::parentKeyName());
    }
}
