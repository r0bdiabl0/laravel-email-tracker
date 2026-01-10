<?php

declare(strict_types=1);

namespace R0bdiabl0\EmailTracker\Models\Concerns;

trait HasConfigurableTable
{
    /**
     * Get the table name with configurable prefix.
     */
    public function getTable(): string
    {
        if (isset($this->table)) {
            return $this->table;
        }

        $prefix = config('email-tracker.table_prefix', '');
        $baseName = $this->getBaseTableName();

        return $prefix ? "{$prefix}_{$baseName}" : $baseName;
    }

    /**
     * Get the base table name without prefix.
     * Override this in child classes to specify the base table name.
     */
    abstract protected function getBaseTableName(): string;

    /**
     * Resolve a table name with prefix for use in migrations and queries.
     */
    public static function resolveTableName(string $baseName): string
    {
        $prefix = config('email-tracker.table_prefix', '');

        return $prefix ? "{$prefix}_{$baseName}" : $baseName;
    }
}
