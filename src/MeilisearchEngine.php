<?php

declare(strict_types=1);

namespace Orion\Meilisearch;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;
use InvalidArgumentException;
use LogicException;
use Meilisearch\Client;
use Meilisearch\Contracts\DocumentsQuery;
use Meilisearch\Contracts\Http;
use Meilisearch\Contracts\TasksQuery;
use Meilisearch\Endpoints\Indexes;
use Meilisearch\Exceptions\ApiException;
use Meilisearch\Http\Client as HttpClient;
use Orion\Adapter\Capability;
use Orion\Adapter\InMemory\Matcher;
use Orion\Adapter\InMemory\Shaper;
use Orion\Adapter\PendingTask;
use Orion\Adapter\QueryCompiling;
use Orion\Adapter\RequiresReindex;
use Orion\Adapter\SchemaAdapter;
use Orion\Adapter\SearchAdapter;
use Orion\Adapter\UnsupportedClause;
use Orion\Adapter\WriteAdapter;
use Orion\IndexName;
use Orion\Query\Filter;
use Orion\Query\Hit;
use Orion\Query\Query;
use Orion\Query\ResultSet;
use Orion\Schema\ChangeKind;
use Orion\Schema\Field;
use Orion\Schema\FieldType;
use Orion\Schema\IndexSchema;
use Orion\Schema\LiveSchema;
use Orion\Schema\MigrationPlan;
use Orion\Support\Assert;
use Orion\Write\BulkResult;
use Orion\Write\Document;
use Orion\Write\Tombstone;
use Orion\Write\WriteOutcome;
use Override;
use Psr\Http\Client\ClientInterface;
use RuntimeException;
use Traversable;

use function array_chunk;
use function array_flip;
use function array_intersect_key;
use function array_key_exists;
use function array_map;
use function array_slice;
use function array_values;
use function count;
use function implode;
use function in_array;
use function iterator_to_array;
use function is_array;
use function is_numeric;
use function is_scalar;
use function is_string;
use function min;
use function sprintf;
use function strlen;
use function substr;
use function uksort;

/**
 * The Meilisearch adapter (1.13 and later) on the official client. The engine is schemaless and asynchronous: the
 * neutral schema becomes index settings plus a declaration document ({@see Settings}), documents are validated
 * against it before they are sent ({@see Documents}, so an undeclared field or a wrong type fails alone, as a strict
 * mapping would), every write is a task awaited through {@see BulkResult::await()}, and versions are kept by Orion
 * (a stale write or delete is found by reading the stored version first; a delete leaves a tombstone every search
 * excludes). Aliases are core's (no {@see Capability::NativeAliases}); hybrid fusion is core's. `raw()` takes
 * `callable(): array` returning a search body.
 */
final class MeilisearchEngine implements SearchAdapter, SchemaAdapter, WriteAdapter, QueryCompiling
{
    public const string NAME = 'meilisearch';
    public const int MAX_REASON = 300;
    private const array CAPABILITIES = [Capability::Vectors, Capability::Facets, Capability::Highlighting, Capability::AsyncWrites, Capability::OptimisticConcurrency];
    private const int TASK_TIMEOUT_MS = 60000;
    private const int HTTP_TIMEOUT_S = 30;
    private const int CONNECT_TIMEOUT_S = 5;
    private const int TASKS_PER_PAGE = 1000;
    private const int POLL_MS = 50;
    private const int VERSIONS_PER_READ = 500;
    private const string EXPERIMENTAL_FEATURES = '/experimental-features';
    private const string CONTAINS_FILTER = 'containsFilter';

    private readonly Client $client;
    private readonly Http $http;
    private readonly QueryCompiler $compiler;
    /** @var array<string, int> the last write task this instance sent, by index: what the next version read waits for */
    private array $inflight = [];
    private bool $containsEnabled = false;

    public function __construct(string $url, ?string $apiKey = null, ?ClientInterface $httpClient = null)
    {
        if ($url === '') {
            throw new InvalidArgumentException('Meilisearch needs the node\'s URL.');
        }
        $httpClient ??= new GuzzleClient(['timeout' => self::HTTP_TIMEOUT_S, 'connect_timeout' => self::CONNECT_TIMEOUT_S]);
        $factory = new HttpFactory();
        $this->client = new Client($url, $apiKey, $httpClient, $factory, [], $factory);
        $this->http = new HttpClient($url, $apiKey, $httpClient, $factory, [], $factory);
        $this->compiler = new QueryCompiler(self::NAME);
    }

    /**
     * An engine on the official client over Guzzle for this node (`http://host:7700`) and, where the node has keys, an
     * API key with the `indexes.*`, `documents.*`, `settings.*`, `tasks.get`, `search` and `experimental.*` actions.
     */
    public static function connect(string $url, ?string $apiKey = null): self
    {
        return new self($url, $apiKey);
    }

    #[Override]
    public function supports(Capability $capability): bool
    {
        return in_array($capability, self::CAPABILITIES, true);
    }

    /**
     * The parameters `search()` hands the client: the index and the search body.
     */
    #[Override]
    public function compile(IndexName $index, Query $query): array
    {
        return ['index' => $index->value, 'body' => $this->compiler->compile($query, $this->schema($index))];
    }

    #[Override]
    public function search(IndexName $index, Query $query): ResultSet
    {
        $schema = $this->schema($index);
        $body = $this->compiler->compile($query, $schema);

        return $this->results($this->endpoint($index), $this->call($this->endpoint($index)->rawSearch(null, $body)), $body, $schema, $query);
    }

    #[Override]
    public function raw(IndexName $index, callable $native, Filter ...$scope): ResultSet
    {
        $body = $native();
        if (!is_array($body)) {
            throw new LogicException('A Meilisearch native query is a callable returning a search body array.');
        }
        $schema = $this->schema($index);
        $scoped = $this->compiler->scope($body, $scope, $schema);

        return $this->results($this->endpoint($index), $this->call($this->endpoint($index)->rawSearch(null, $scoped)), $scoped, $schema, null);
    }

    /**
     * Creates the index with Orion's settings and declaration. An index that exists with its declaration is left as
     * it is; one without (a crash between the steps) is completed.
     */
    #[Override]
    public function ensure(IndexSchema $schema): void
    {
        $settings = Settings::create($schema);
        if ($this->declaration($schema->index) !== null) {
            return;
        }
        $this->enableContainsFilter();
        $this->await($this->client->createIndex($schema->index->value, ['primaryKey' => Documents::ID]), ['index_already_exists']);
        $this->await($this->endpoint($schema->index)->updateSettings($settings));
        $this->await($this->endpoint($schema->index)->addDocuments([Settings::declaration($schema)]));
    }

    #[Override]
    public function inspect(IndexName $index): ?LiveSchema
    {
        $declaration = $this->declaration($index);

        return $declaration === null ? null : new LiveSchema($index, Settings::fields($declaration, $this->settings($index)));
    }

    #[Override]
    public function plan(IndexSchema $desired): MigrationPlan
    {
        return MigrationPlan::between($desired, $this->inspect($desired->index));
    }

    /**
     * Adds the plan's new fields to the settings and the declaration, as tasks the returned {@see PendingTask} awaits.
     * A new vector field needs a reindex: the documents already indexed carry no embedding for it, and the engine
     * refuses a user-provided embedder its documents do not feed.
     */
    #[Override]
    public function apply(MigrationPlan $plan): PendingTask
    {
        $declaration = $this->declaration($plan->index) ?? throw new LogicException(sprintf('The index %s does not exist; ensure it first.', $plan->index->value));
        $fields = $plan->applyTo(Settings::fields($declaration, $this->settings($plan->index)));
        $added = [];
        foreach ($plan->additive() as $change) {
            if ($change->kind === ChangeKind::Added && $change->after instanceof Field) {
                $added[] = $change->after;
            }
        }
        if ($added === []) {
            return PendingTask::completed();
        }
        if (self::holdsVector($added)) {
            throw RequiresReindex::for($plan->index, 'it adds a vector field and the documents already indexed carry no embedding for it.');
        }
        $schema = self::schemaOf($plan->index, $fields);
        $owned = array_intersect_key(Settings::create($schema), array_flip(['filterableAttributes', 'sortableAttributes', 'embedders']));
        $tasks = [
            $this->taskUid($this->endpoint($plan->index)->updateSettings($owned)),
            $this->taskUid($this->endpoint($plan->index)->addDocuments([Settings::declaration($schema)])),
        ];

        return PendingTask::pending(function (float $timeout) use ($tasks): void {
            foreach ($tasks as $uid) {
                $this->awaitUid($uid, (int) ($timeout * 1000));
            }
        });
    }

    #[Override]
    public function drop(IndexName $index): void
    {
        try {
            $this->await($this->client->deleteIndex($index->value), ['index_not_found']);
        } catch (ApiException $missing) {
            if ($missing->httpStatus !== 404) {
                throw $missing;
            }
        }
        unset($this->inflight[$index->value]);
    }

    #[Override]
    public function alias(IndexName $alias, IndexName $physical): void
    {
        throw UnsupportedClause::missing(Capability::NativeAliases, 'alias ' . Assert::show($alias->value), self::NAME);
    }

    #[Override]
    public function resolve(IndexName $alias): ?IndexName
    {
        throw UnsupportedClause::missing(Capability::NativeAliases, 'alias ' . Assert::show($alias->value), self::NAME);
    }

    #[Override]
    public function index(IndexName $index, Document ...$docs): BulkResult
    {
        $schema = $this->schema($index);
        $settled = [];
        $pending = [];
        foreach ($docs as $doc) {
            $reason = Documents::invalidId($doc->id) ?? Documents::invalid($doc->fields, $schema->fields);
            if ($reason !== null) {
                $settled[$doc->id] = WriteOutcome::failed($doc->id, $reason);
            } else {
                $pending[$doc->id] = $doc;
            }
        }
        $current = $this->versions($index, array_map(static fn(Document $doc): string => $doc->id, array_values($pending)));
        $batch = [];
        foreach ($pending as $doc) {
            if (isset($current[$doc->id]) && $doc->version <= $current[$doc->id]) {
                $settled[$doc->id] = WriteOutcome::stale($doc->id);
            } else {
                $batch[] = Documents::encode($doc, $schema);
            }
        }

        return $this->send($index, $batch, array_map(static fn(Document $doc): string => $doc->id, array_values($docs)), $settled);
    }

    #[Override]
    public function delete(IndexName $index, Tombstone ...$tombstones): BulkResult
    {
        $schema = $this->schema($index);
        $current = $this->versions($index, array_map(static fn(Tombstone $tombstone): string => $tombstone->id, array_values($tombstones)));
        $settled = [];
        $batch = [];
        foreach ($tombstones as $tombstone) {
            $reason = Documents::invalidId($tombstone->id);
            if ($reason !== null) {
                $settled[$tombstone->id] = WriteOutcome::failed($tombstone->id, $reason);
            } elseif (isset($current[$tombstone->id]) && $tombstone->version <= $current[$tombstone->id]) {
                $settled[$tombstone->id] = WriteOutcome::stale($tombstone->id);
            } else {
                $batch[] = Documents::tombstone($tombstone, $schema);
            }
        }

        return $this->send($index, $batch, array_map(static fn(Tombstone $tombstone): string => $tombstone->id, array_values($tombstones)), $settled);
    }

    /**
     * Waits for every task of the index still queued or running: what `BulkResult::await()` does for one write, for all.
     */
    #[Override]
    public function flush(IndexName $index, bool $await = false): void
    {
        if (!$await) {
            return;
        }
        $query = (new TasksQuery())->setIndexUids([$index->value])->setStatuses(['enqueued', 'processing'])->setLimit(self::TASKS_PER_PAGE);
        do {
            $tasks = $this->client->getTasks($query)->getResults();
            foreach ($tasks as $task) {
                if (is_array($task) && is_numeric($task['uid'] ?? null)) {
                    $this->awaitUid((int) $task['uid'], self::TASK_TIMEOUT_MS);
                }
            }
        } while ($tasks !== []);
    }

    /**
     * One addition task for the batch, settled when awaited: the task's failure fails every document it carried.
     *
     * @param list<array<string, mixed>> $batch
     * @param list<string> $ids every requested id, in request order
     * @param array<string, WriteOutcome> $settled outcomes known before sending
     */
    private function send(IndexName $index, array $batch, array $ids, array $settled): BulkResult
    {
        $ordered = static fn(array $outcomes): array => array_map(static fn(string $id): WriteOutcome => $settled[$id] ?? $outcomes[$id] ?? WriteOutcome::failed($id, 'The engine did not answer for this document.'), $ids);
        if ($batch === []) {
            return BulkResult::completed(...$ordered([]));
        }
        $uid = $this->taskUid($this->endpoint($index)->addDocuments($batch));
        $this->inflight[$index->value] = $uid;
        $sent = array_map(static fn(array $doc): string => (string) $doc[Documents::ORION_ID], $batch);

        return BulkResult::pending(function (float $timeout) use ($uid, $sent, $ordered): array {
            $task = $this->client->waitForTask($uid, (int) ($timeout * 1000), self::POLL_MS);
            $failed = ($task['status'] ?? null) !== 'succeeded';
            $reason = $failed ? self::reason($task['error'] ?? null) : '';
            $outcomes = [];
            foreach ($sent as $id) {
                $outcomes[$id] = $failed ? WriteOutcome::failed($id, $reason) : WriteOutcome::applied($id);
            }

            return $ordered($outcomes);
        });
    }

    /**
     * The stored version of each id, tombstones included: what a write or delete must exceed.
     *
     * @param list<string> $ids
     * @return array<string, int>
     */
    private function versions(IndexName $index, array $ids): array
    {
        // A write this instance sent and nobody awaited is still the newer state; it settles before it is read.
        if (isset($this->inflight[$index->value])) {
            $this->awaitUid($this->inflight[$index->value], self::TASK_TIMEOUT_MS, ['*']);
            unset($this->inflight[$index->value]);
        }
        $versions = [];
        foreach (array_chunk($ids, self::VERSIONS_PER_READ) as $chunk) {
            $filter = Documents::ID . ' IN [' . implode(', ', array_map(static fn(string $id): string => '"' . Documents::id($id) . '"', $chunk)) . ']';
            $query = (new DocumentsQuery())->setFilter([$filter])->setFields([Documents::ORION_ID, Documents::VERSION])->setLimit(count($chunk));
            foreach ($this->endpoint($index)->getDocuments($query)->getResults() as $stored) {
                if (is_array($stored) && is_scalar($stored[Documents::ORION_ID] ?? null)) {
                    $versions[(string) $stored[Documents::ORION_ID]] = (int) ($stored[Documents::VERSION] ?? 0);
                }
            }
        }

        return $versions;
    }

    /**
     * @param array<string, mixed> $response
     * @param array<string, mixed> $body
     */
    private function results(Indexes $endpoint, array $response, array $body, IndexSchema $schema, ?Query $query): ResultSet
    {
        $flat = Documents::flatten($schema->fields);
        $shaper = $query === null || $query->highlights === [] ? null : new Shaper(new Matcher($flat));
        $hits = [];
        foreach ((array) ($response['hits'] ?? []) as $hit) {
            if (!is_array($hit)) {
                continue;
            }
            $source = Documents::source($hit);
            $highlights = $shaper === null || $query === null ? [] : $shaper->highlights($source, $query->highlights, $query->clauses);
            $hits[] = new Hit((string) ($hit[Documents::ORION_ID] ?? ''), is_numeric($hit['_rankingScore'] ?? null) ? (float) $hit['_rankingScore'] : 1.0, $source, $highlights);
        }
        $vector = array_key_exists('vector', $body);
        if (array_key_exists('totalHits', $response)) {
            // A page past the last one answers with no hits and a total of zero; the first page still knows the total.
            $total = $hits === [] && (int) ($body['page'] ?? 1) > 1
                ? (int) ($this->call($endpoint->rawSearch(null, [...$body, 'page' => 1, 'hitsPerPage' => 1, 'attributesToRetrieve' => [Documents::ORION_ID]]))['totalHits'] ?? 0)
                : (int) $response['totalHits'];
            $estimate = false;
        } else {
            $total = (int) ($response['estimatedTotalHits'] ?? count($hits));
            $estimate = !$vector;
        }
        if ($vector) {
            // A semantic search ranks every candidate; the neighbours are the window asked for, as k is elsewhere.
            $total = min($total, (int) ($body['offset'] ?? 0) + (int) ($body['limit'] ?? 20));
        }
        $sizes = [];
        foreach ($query === null ? [] : $query->facets as $facet) {
            $sizes[Documents::shadow($facet->field)] = $facet->size;
        }
        $facets = $vector && ($body['facets'] ?? []) !== []
            ? $this->facetsWithin($endpoint, $body, $flat, $sizes)
            : self::facets(is_array($response['facetDistribution'] ?? null) ? $response['facetDistribution'] : [], $flat, $sizes);

        return new ResultSet($hits, $total, $estimate, $facets, (float) ($response['processingTimeMs'] ?? 0));
    }

    /**
     * Facet counts as the engine gives them, each decoded to its field's values, the most frequent first, cut to the
     * size asked for.
     *
     * @param array<array-key, mixed> $distribution
     * @param array<string, Field> $flat
     * @param array<string, int> $sizes by shadow attribute
     * @return array<string, array<string, int>>
     */
    private static function facets(array $distribution, array $flat, array $sizes): array
    {
        $facets = [];
        foreach ($flat as $path => $field) {
            $shadow = Documents::shadow($path);
            if (!is_array($distribution[$shadow] ?? null)) {
                continue;
            }
            $counts = Documents::facet($distribution[$shadow], $field);
            uksort($counts, static fn(string $a, string $b): int => ($counts[$b] <=> $counts[$a]) ?: ($a <=> $b));
            $facets[$path] = isset($sizes[$shadow]) ? array_slice($counts, 0, $sizes[$shadow], true) : $counts;
        }

        return $facets;
    }

    /**
     * A semantic search's facets count every candidate; the neighbours' counts come from reading the window itself.
     *
     * @param array<string, mixed> $body
     * @param array<string, Field> $flat
     * @param array<string, int> $sizes
     * @return array<string, array<string, int>>
     */
    private function facetsWithin(Indexes $endpoint, array $body, array $flat, array $sizes): array
    {
        $window = [...$body, 'offset' => 0, 'limit' => (int) ($body['offset'] ?? 0) + (int) ($body['limit'] ?? 20), 'attributesToRetrieve' => [Documents::SOURCE]];
        unset($window['facets']);
        $matcher = new Matcher($flat);
        $sources = array_map(static fn(mixed $hit): array => Documents::source(is_array($hit) ? $hit : []), (array) ($this->call($endpoint->rawSearch(null, $window))['hits'] ?? []));
        $facets = [];
        foreach ((array) $body['facets'] as $shadow) {
            foreach ($flat as $path => $field) {
                if (Documents::shadow($path) !== $shadow) {
                    continue;
                }
                $counts = [];
                foreach ($sources as $source) {
                    foreach ($matcher->values($source, $path) as $value) {
                        $key = Documents::facetKey($field, $value);
                        $counts[$key] = ($counts[$key] ?? 0) + 1;
                    }
                }
                uksort($counts, static fn(string $a, string $b): int => ($counts[$b] <=> $counts[$a]) ?: ($a <=> $b));
                $facets[$path] = isset($sizes[$shadow]) ? array_slice($counts, 0, $sizes[$shadow], true) : $counts;
            }
        }

        return $facets;
    }

    /**
     * The declared schema of the index, for compiling queries and encoding documents: read for every call, so a
     * change another process applied is seen at once (one read per search or write, which the engine answers fast).
     */
    private function schema(IndexName $index): IndexSchema
    {
        return self::schemaOf(
            $index,
            Settings::declared($this->declaration($index) ?? throw new InvalidArgumentException(sprintf('The index %s does not exist; ensure it first.', $index->value))),
        );
    }

    /**
     * The index's settings as plain arrays: the client wraps some of them (embedders, faceting) in iterable objects.
     *
     * @return array<string, mixed>
     */
    private function settings(IndexName $index): array
    {
        return array_map(static fn(mixed $value): mixed => $value instanceof Traversable ? iterator_to_array($value) : $value, $this->call($this->endpoint($index)->getSettings()));
    }

    /**
     * @return array<string, mixed>|null the declaration document, or null when the index or the document is missing
     */
    private function declaration(IndexName $index): ?array
    {
        try {
            $document = $this->endpoint($index)->getDocument(Settings::DECLARATION_ID);
        } catch (ApiException $missing) {
            if ($missing->httpStatus === 404) {
                return null;
            }
            throw $missing;
        }

        /** @var array<string, mixed> */
        return is_array($document) ? $document : [];
    }

    /**
     * @param list<Field> $fields
     */
    private static function schemaOf(IndexName $index, array $fields): IndexSchema
    {
        $schema = IndexSchema::for($index->value);
        foreach ($fields as $field) {
            $schema = $schema->with($field);
        }

        return $schema;
    }

    /**
     * Orion's lexical clauses are `CONTAINS` and `STARTS WITH` filters, an experimental feature the node must have on.
     */
    private function enableContainsFilter(): void
    {
        if ($this->containsEnabled) {
            return;
        }
        try {
            $features = $this->http->get(self::EXPERIMENTAL_FEATURES);
            if (!is_array($features) || ($features[self::CONTAINS_FILTER] ?? false) !== true) {
                $this->http->patch(self::EXPERIMENTAL_FEATURES, [self::CONTAINS_FILTER => true]);
            }
        } catch (ApiException $refused) {
            throw new RuntimeException(sprintf('Meilisearch has the %s experimental feature off and refused to turn it on (an API key with the experimental.* actions can: PATCH /experimental-features {"%s": true}); Orion\'s lexical queries need it.', self::CONTAINS_FILTER, self::CONTAINS_FILTER), 0, $refused);
        }
        $this->containsEnabled = true;
    }

    private function endpoint(IndexName $index): Indexes
    {
        return $this->client->index($index->value);
    }

    /**
     * @param array<string, mixed> $task the task the client returned for a request
     * @param list<string> $tolerated error codes that leave the index as wanted
     * @return array<string, mixed> the finished task
     */
    private function await(array $task, array $tolerated = []): array
    {
        return $this->awaitUid($this->taskUid($task), self::TASK_TIMEOUT_MS, $tolerated);
    }

    /**
     * @param list<string> $tolerated
     * @return array<string, mixed>
     */
    private function awaitUid(int $uid, int $timeoutMs, array $tolerated = []): array
    {
        $done = $this->client->waitForTask($uid, $timeoutMs, self::POLL_MS);
        if (($done['status'] ?? null) === 'failed') {
            $error = is_array($done['error'] ?? null) ? $done['error'] : [];
            if (!in_array('*', $tolerated, true) && !in_array($error['code'] ?? '', $tolerated, true)) {
                throw new RuntimeException(sprintf('Meilisearch refused %s: %s', is_string($done['type'] ?? null) ? $done['type'] : 'the task', self::reason($error)));
            }
        }

        /** @var array<string, mixed> */
        return $done;
    }

    /**
     * @param array<array-key, mixed> $task
     */
    private function taskUid(array $task): int
    {
        return is_numeric($task['taskUid'] ?? null) ? (int) $task['taskUid'] : throw new LogicException('Meilisearch answered without a task uid.');
    }

    private static function reason(mixed $error): string
    {
        $text = is_array($error) && is_scalar($error['message'] ?? null) ? (string) $error['message'] : 'The task failed.';

        return strlen($text) > self::MAX_REASON ? substr($text, 0, self::MAX_REASON) . '…' : $text;
    }

    /**
     * @param list<Field> $fields
     */
    private static function holdsVector(array $fields): bool
    {
        foreach ($fields as $field) {
            if ($field->type === FieldType::Vector || self::holdsVector($field->fields)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The client answers with the decoded body; anything else is a transport the adapter does not speak.
     *
     * @return array<string, mixed>
     */
    private function call(mixed $response): array
    {
        if (!is_array($response)) {
            throw new LogicException('The Meilisearch client must answer with a decoded JSON body.');
        }
        /** @var array<string, mixed> */
        return $response;
    }
}
