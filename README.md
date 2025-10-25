# Content Repository Debugger (Neos.ContentRepository.Debug)

Tools to explore and debug the Neos Content Repository event store.

Features:

- Create a **temporary Content Repository** next to your real one
- Copy a **filtered subset of events** into that debug Content Repository
- **Analyze the event log** via SQL
- **Performance**: The debugger works with many millions of events, and some operations like `copyEvents` will only re-run if needed, allowing for quick iteration.

> ⚠️ **WARNING: Development Only**
>
> Never run this against production. Always work with a local database copy.

## Quick start: run a debug script

Example script `DebugEx1.php`:

```php
<?php

/** @var $dbg \Neos\ContentRepository\Debug\ContentRepositoryDebugger */
/** @var $cr  \Neos\ContentRepository\Core\ContentRepository */

use Neos\ContentRepository\Core\Feature\NodeModification\Event\NodePropertiesWereSet;
use Neos\ContentRepository\Core\Feature\NodeReferencing\Event\NodeReferencesWereSet;
use Neos\ContentRepository\Debug\EventFilter\EventFilter;

$debugCr = $dbg->setupCr('dbg');
$dbg->copyEvents(
    target: $debugCr,
    filter: EventFilter::create()->skipEventTypes(NodePropertiesWereSet::class, NodeReferencesWereSet::class)
);
// following commands will use the debug CR instead of the default one by default
$dbg->use($debugCr);

// $dbg->printTable($dbg->queryEvents()->groupByMonth()->groupByType()->count()->execute(), pivotBy: 'type');
$dbg->printTable(
    $dbg->queryEvents()
        ->groupByMonth()
        ->groupByType()
        ->count()
        ->execute()
);
```

Execute the above script with:

```bash
./flow cr:debug DebugEx1.php
```

Inside your debug script (`DebugEx1.php`) you automatically get two variables:

- $dbg: `Neos\ContentRepository\Debug\ContentRepositoryDebugger` - see below for API details
- $cr:  `Neos\ContentRepository\Core\ContentRepository` (the source Content Repository you passed, or `default` if none was specified)

## API quick reference

- `setupCr(string $targetId, prune: false)`: creates a new content repository (if it does not exist yet) matching the productive Content Repository configuration. When `prune: true` it also empties the target Content Repository completely on subsequent runs.
- `copyEvents($target, $filter, force: false)` truncates the target CR's event table and inserts events from the source CR matching the filter. This is idempotent: If unchanged, copy is skipped unless force = true.
- `use(cr)` switches the active CR inside the debugger.
- `queryEvents(cr = null)` returns a query builder specialized for the event log table of the given (or current) CR.
- `printTable(result, pivotBy = null)` pretty‑prints a Doctrine DBAL Result as a table. Optional pivotBy will pivot the array by the given column.

## Event copying examples

1) Copy everything from the source to a new debug CR:

```php
$debugCr = $dbg->setupCr('debug');
$dbg->copyEvents(target: $debugCr);
```

This is helpful if you later want to replay your production projections step by step.

2) Copy while skipping noisy event types:

```php
use Neos\ContentRepository\Core\Feature\NodeModification\Event\NodePropertiesWereSet;
use Neos\ContentRepository\Core\Feature\NodeReferencing\Event\NodeReferencesWereSet;
use Neos\ContentRepository\Debug\EventFilter\EventFilter;

$debugCr = $dbg->setupCr('dbg');
$dbg->copyEvents(
    target: $debugCr,
    filter: EventFilter::create()->skipEventTypes(NodePropertiesWereSet::class, NodeReferencesWereSet::class)
);
```

3) Re‑run copying EVERYTIME (should not be needed, as it automatically detects whether the copy needs to be updated):

```php
$dbg->copyEvents(target: $debugCr, force: true);
```


## Switching the active Content Repository

After you populated your debug Content Repository, you can make all subsequent queries run against it by default:

```php
$dbg->use($debugCr);
// all $dbg->... invocations will now run against $debugCr.
```

You can switch back by calling `$dbg->use($cr)` later.


## Querying the event log

Use `$dbg->queryEvents()->....->execute()` for quick aggregations. All queries operate against the current CR unless you pass a CR explicitly.

**Available operations**

WHERE for filtering:

- `whereRecordedAtBetween(from, to)`: filter events recorded between the two timestamps (inclusive). Use `YYYY-MM-DD` or full `YYYY-MM-DD HH:MM:SS` formats supported by your DB.
- `whereStreamNotLike(pattern)`: exclude events whose stream matches the given SQL LIKE pattern (e.g. `whereStreamNotLike('Workspace:%')` skips workspace streams).
- `whereType(...$eventTypes)`: filter by event type. Accepts FQCNs and/or short event names; FQCNs are normalized automatically.

GROUP BY for aggregations:

- `groupByMonth()`: group events based on recording time, aggregated to month level.
- `groupByDay()`: group events based on recording time, aggregated to day level.
- `groupByType()`: group by event type.
- `groupByStream()`: group by event stream name.

AGGREGATION functions in combination with GROUP BY:

- `count()`: adds COUNT(*). Usually only relevant when grouping.
- `recordedAtMinMax()`: add `MIN(recordedat)`, `MAX(recordedat)` and their difference as columns.
- `sequenceNumberMinMax()`: add `MIN(sequencenumber)`, `MAX(sequencenumber)` and their difference as columns.

Examples:

1) Count events per month:

```php
$result = $dbg->queryEvents()->groupByMonth()->count()->execute();
$dbg->printTable($result);
```

2) Count events per type:

```php
$result = $dbg->queryEvents()->groupByType()->count()->execute();
$dbg->printTable($result);
```

3) Count events per month and type, printed as a pivot table by type:

```php
$result = $dbg->queryEvents()->groupByMonth()->groupByType()->count()->execute();
$dbg->printTable($result, pivotBy: 'type');
```

4) Query a different CR without switching the global context:

```php
$result = $dbg->queryEvents($debugCr)->groupByMonth()->count()->execute();
$dbg->printTable($result);
```


## Arbitrary queries via `$dbg->db`

For anything beyond the `EventLogQueryBuilder`, you can run arbitrary SQL via Doctrine DBAL using the debugger's connection at `$dbg->db` and still reuse printTable for quick inspection.

```php
// Run a custom SQL against the current database
$result = $dbg->db->executeQuery(
    'SELECT type, COUNT(*) AS cnt FROM cr_default_events GROUP BY type ORDER BY cnt DESC'
);
$dbg->printTable($result);
```

Prefer parameterized queries when injecting values:

```php
$sql = 'SELECT * FROM ' . \Neos\ContentRepositoryRegistry\Factory\EventStore\DoctrineEventStoreFactory::databaseTableName($cr->id) . ' WHERE recordedat >= :from';
$result = $dbg->db->executeQuery($sql, ['from' => '2024-01-01']);
$dbg->printTable($result);
```

