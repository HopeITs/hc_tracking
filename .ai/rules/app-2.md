---
paths:
  - 'app/**'
---

# App 2

## Return list<> from collections via array_values(); raw-SQL ignores stay narrow
Larastan types $collection->values()->all() as array<int, T>, never list<T>, so a method documented @return list<...> returns array_values($collection->all()); pluck() ids are mixed, map them to int/string first. phpstan.neon ignoreErrors entries are one identifier + one path + exact count, each with a written reason; no baseline. Raw SQL with anything a user can type uses ? bindings; only fixed enum values, self-formatted dates or whitelisted sort directions may stay in literal-string ignores.
