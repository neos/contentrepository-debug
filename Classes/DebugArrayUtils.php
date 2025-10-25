<?php

namespace Neos\ContentRepository\Debug;

class DebugArrayUtils
{
    public static function pivot(array $rows, ?string $pivotBy = null): array
    {
        if ($pivotBy !== null) {
            if (!isset($rows[0][$pivotBy])) {
                throw new \InvalidArgumentException("Pivot column '{$pivotBy}' does not exist in result set.");
            }

            if (!isset($rows[0]['count'])) {
                throw new \InvalidArgumentException("Pivot requires a 'count' column in the result set.");
            }

            // Get all unique pivot values (these become columns)
            $pivotValues = array_unique(array_column($rows, $pivotBy));

            // Get all other columns (excluding pivotBy and count)
            $groupByColumns = array_diff(array_keys($rows[0]), [$pivotBy, 'count']);

            // Group and pivot
            $pivotedRows = [];
            foreach ($rows as $row) {
                // Create key from groupBy columns
                $key = implode('|', array_map(fn($col) => $row[$col], $groupByColumns));

                if (!isset($pivotedRows[$key])) {
                    $pivotedRows[$key] = [];
                    foreach ($groupByColumns as $col) {
                        $pivotedRows[$key][$col] = $row[$col];
                    }
                    // Initialize all pivot columns with empty arrays
                    foreach ($pivotValues as $pv) {
                        $pivotedRows[$key][$pv] = [];
                    }
                }

                // Add cnt to appropriate pivot column
                $pivotedRows[$key][$row[$pivotBy]][] = $row['count'];
            }

            // Convert arrays to JSON
            foreach ($pivotedRows as &$row) {
                foreach ($pivotValues as $pv) {
                    $values = $row[$pv];
                    if (empty($values)) {
                        $row[$pv] = '';
                    } elseif (count($values) === 1) {
                        $row[$pv] = $values[0];
                    } else {
                        $row[$pv] = json_encode($values);
                    }
                }
            }

            $rows = array_values($pivotedRows);
        }

        return $rows;
    }
}
