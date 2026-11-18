---
paths:
  - app/Concerns/HasTree.php
---

# Concerns

## HasTree: unsaved nodes must short-circuit, and cycle checks walk up
Result uses App\Concerns\HasTree for adjacency-list traversal through parent_id. Traversal is plain Eloquent, one query per level.

Two traps the trait deliberately handles:

descendants() returns early when getKey() is null. Without that, childrenRecursive queries `where parent_id is null` and hands back every ROOT row in the table as a "descendant" of an unsaved node — which would make the cycle guard reject perfectly valid saves.

wouldCycleUnder() walks UP from the candidate parent rather than down from the node. That is one query per level instead of one per subtree node, and it reads stored rows rather than any childrenRecursive loaded earlier, so a stale relation cannot wave a cycle through. Do not "optimise" it into a descendants()->contains() check.

## HasTree sibling ordering goes through childrenOrderColumn()
Do not override children() to add an orderBy. children() comes from the HasTree trait, not a parent class, so `parent::children()` does not resolve and re-declaring it in the model silently drops the trait version.

Order siblings by overriding the static childrenOrderColumn() hook. Result has no ordering column and keeps insertion order.

Because childrenRecursive() is built on children(), the hook orders the entire subtree, so descendants() comes back ordered too — not just the first level.
