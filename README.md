# orion-search/meilisearch

Orion's engine adapter for Meilisearch 1.13 and later, on the official `meilisearch/meilisearch-php` client over
Guzzle.

```php
use Orion\Meilisearch\MeilisearchEngine;
use Orion\Registry\EngineRegistry;

$engines = new EngineRegistry(meilisearch: MeilisearchEngine::connect($url, $apiKey));
```

- Meilisearch is schemaless: the neutral schema becomes index settings plus a declaration document Orion keeps in
  the index (`orion-schema`, a tombstone no search returns). `inspect()` reads the declaration and keeps each field
  only where the settings still serve it; a field they no longer serve is unknown and fails a plan closed.
- Documents are validated against the declaration before they are sent, so an undeclared field, a wrong type, a
  keyword over 250 bytes or an id the engine cannot hold fails alone, as a strict mapping would; a task the engine
  refuses as a whole fails every document it carried, with the engine's reason. Every write is a task;
  `BulkResult::await()` waits for it, `flush($index, await: true)` waits for every task of the index.
- Versions are Orion's: a write or delete is compared with the stored version first (stale when not newer), after
  the engine's own queued write from this instance has settled, and a delete leaves a tombstone every search
  excludes. One writer per index, which the outbox is: two processes writing the same index at once can race.
- Lexical clauses are filters on shadow attributes (a text field's tokens as hex-encoded, letter-delimited strings
  of at most 500 bytes, chunked with an overlap longer than any phrase; a keyword's value hex-encoded; hex because
  the engine's filters are case-insensitive), never `q`: Meilisearch's `q` requires every word or drops some,
  tolerates typos and completes the last word, none of which is the portable match. `q` stays available through the
  escape hatch, with the host's own typo, synonym and ranking settings.
- Vectors are user-provided embeddings, ranked by cosine similarity (the one metric available); hybrid fusion is
  core's. Facets count within the k nearest, as on every engine, read from the window itself.
- No native aliases: core keeps the indirection. No nested documents. One geo field (`_geo`). A prefix clause on a
  number is refused.
- `IndexSchema::native('meilisearch', ['settings' => [...]])` merges over the generated settings, except what Orion
  owns (filterable, sortable and displayed attributes, embedders, pagination, faceting, distinct).
- `raw()` takes `callable(): array` returning a search body; scope filters and the tombstone exclusion are ANDed into
  its `filter`. Nothing in a search body reads past its filter, so no key is refused.
- The `containsFilter` experimental feature is turned on at `ensure()`: Orion's clauses are `CONTAINS` and
  `STARTS WITH` filters.

Proven by `Orion\Testing\EngineConformanceTestCase` against Meilisearch 1.56 and 1.13, with the tenancy isolation,
determinism, fault-injection and endpoint-fixture suites on both. The property-based query equivalence suite, which
holds a vector search to the exact neighbours within a slack, runs on the current line only: 1.13's approximate
index strays further and differently per build, so on that line a vector search returns neighbours the engine
ranks nearest, not provably the k nearest.
