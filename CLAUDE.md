# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

This is a **Neos Flow package** (`neos/contentrepository-debug`) that provides debugging tools for the Neos Content Repository event store. It is a development-only tool — never run against production.

## Running Debug Scripts

```bash
# Run a debug script (from the Neos application root, not this package root)
./flow cr:debug path/to/DebugScript.php

# Target a specific content repository (default: 'default')
./flow cr:debug path/to/DebugScript.php --content-repository=myContentRepository

# Set up SQL debug views in the database
./flow cr:setupDebugViews
./flow cr:setupDebugViews --content-repository=myContentRepository
```

Debug scripts in `DebugScripts/` are executed as PHP includes and receive two pre-configured variables:
- `$dbg` — `ContentRepositoryDebugger` (the main API)
- `$cr` — `ContentRepository` (the source CR, defaults to `default`)

## Architecture

### Entry Point
`Classes/Command/CrCommandController.php` — Neos Flow CLI controller exposing `cr:debug` and `cr:setupDebugViews` commands.

### Core Class
`Classes/ContentRepositoryDebugger.php` — The main API class injected as `$dbg` into scripts. Key methods:
- `setupCr(string $targetId, bool $prune)` — creates a new CR by cloning settings from the source CR (uses reflection to access private `ContentRepositoryRegistry::$settings`)
- `copyEvents(ContentRepository $target, ?EventFilter $filter, bool $force)` — truncates target and bulk-copies events; uses a hash stored as a MySQL table comment for idempotency detection
- `use(ContentRepository $cr)` — switches the active CR for subsequent calls
- `queryEvents(?ContentRepository $cr)` — returns an `EventLogQueryBuilder` for the current or given CR
- `printTable(Result, ?string $pivotBy)` — renders a Doctrine DBAL result as a CLI table
- `printRecords(Result, ?string $pivotBy)` — renders each row as a vertical key/value table (pretty-prints JSON values)
- `setupDebugViews(ContentRepositoryId)` — creates SQL VIEWs for easier DB exploration
- `contentStreamStatus()` / `contentStreamRemoveDangling()` — wraps `ContentStreamPruner`

### Query Builder
`Classes/Query/EventLogQueryBuilder.php` — Fluent builder wrapping Doctrine DBAL's `QueryBuilder` for the CR events table (`cr_{id}_events`). Methods are chainable: `whereXxx()` for filtering, `groupByXxx()` for aggregation dimensions, `count()`/`recordedAtMinMax()`/`sequenceNumberMinMax()` for aggregate columns, `execute()` to run.

### Event Filtering
`Classes/EventFilter/EventFilter.php` — Immutable value object building parameterized SQL WHERE clauses for use in `copyEvents()`. Accepts both FQCNs and short event names (strips namespace automatically, matching `EventNormalizer` logic).

### Debug Views
`Classes/DebugView/DebugViewCreator.php` — Creates MySQL VIEWs (`cr_{id}_dbg_allNodesInLive`, `cr_{id}_dbg_allDocumentNodesInLive`) that join the projection tables for easier SQL exploration.

### Internal Services
`Classes/InternalServices/` — Low-level helpers:
- `EventStoreDebuggingInternals` / `EventStoreDebuggingInternalsFactory` — accesses private event store internals (max sequence number) for idempotency checks
- `LowLevelDatabaseUtil` — gets/sets MySQL table comments (used as idempotency markers on event tables)

## Key Conventions

- **Table naming**: `DoctrineEventStoreFactory::databaseTableName($cr->id)` returns `cr_{id}_events`
- **Event type short names**: the DB stores short class names (e.g. `NodePropertiesWereSet`), not FQCNs — `EventFilter::eventClassNameToShortName()` normalizes these
- **Idempotency**: `copyEvents` hashes `(maxSequenceNumber, filterConfig)` and stores it as a MySQL table comment; subsequent calls skip the copy if the hash matches
- **MySQL-specific SQL**: `DATE_FORMAT()` is used in groupBy methods — this package assumes MySQL/MariaDB
- **Raw `$dbg->db`**: Scripts can access the raw Doctrine DBAL `Connection` directly for arbitrary SQL

## Example Script Structure

```php
<?php
/** @var $dbg \Neos\ContentRepository\Debug\ContentRepositoryDebugger */
/** @var $cr  \Neos\ContentRepository\Core\ContentRepository */

use Neos\ContentRepository\Debug\EventFilter\EventFilter;

// Set up a filtered debug CR
$debugCr = $dbg->setupCr('dbg');
$dbg->copyEvents(target: $debugCr, filter: EventFilter::create()->skipEventTypes(...));
$dbg->use($debugCr);

// Query and display
$dbg->printTable(
    $dbg->queryEvents()->groupByMonth()->groupByType()->count()->execute(),
    pivotBy: 'type'
);

// Raw SQL
$dbg->printTable($dbg->db->executeQuery('SELECT ...'));
```
