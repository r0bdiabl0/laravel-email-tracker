<?php

declare(strict_types=1);

namespace R0bdiabl0\EmailTracker\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class RollbackMigrationCommand extends Command
{
    protected $signature = 'email-tracker:rollback-migration
        {--force : Run without confirmation}';

    protected $description = 'Rollback migration from juhasev/laravel-ses (restore backup tables)';

    protected array $tableMapping = [
        'laravel_ses_batches' => 'batches',
        'laravel_ses_sent_emails' => 'sent_emails',
        'laravel_ses_email_bounces' => 'email_bounces',
        'laravel_ses_email_complaints' => 'email_complaints',
        'laravel_ses_email_opens' => 'email_opens',
        'laravel_ses_email_links' => 'email_links',
    ];

    public function handle(): int
    {
        $this->info('Rolling back Email Tracker migration...');
        $this->newLine();

        // Check for backup tables
        $hasBackups = false;
        foreach (array_keys($this->tableMapping) as $oldTable) {
            if (Schema::hasTable("{$oldTable}_backup")) {
                $hasBackups = true;
                break;
            }
        }

        if (! $hasBackups) {
            $this->warn('No backup tables found. Cannot rollback.');
            $this->line('Backup tables are only created when using --backup flag during migration.');

            return Command::FAILURE;
        }

        // Confirm
        if (! $this->option('force')) {
            $this->warn('This will restore the original juhasev/laravel-ses tables from backups.');
            $this->warn('Current email-tracker tables will be dropped.');

            if (! $this->confirm('Are you sure you want to rollback?')) {
                $this->info('Rollback cancelled.');

                return Command::FAILURE;
            }
        }

        $prefix = config('email-tracker.table_prefix', '');

        foreach ($this->tableMapping as $old => $new) {
            $backupTable = "{$old}_backup";
            $newName = $prefix ? "{$prefix}_{$new}" : $new;

            if (! Schema::hasTable($backupTable)) {
                $this->line("  <fg=gray>No backup for {$old}, skipping...</>");

                continue;
            }

            // Drop new table if exists
            if (Schema::hasTable($newName)) {
                Schema::dropIfExists($newName);
                $this->line("  <fg=yellow>Dropped: {$newName}</>");
            }

            // Restore backup to original name
            Schema::rename($backupTable, $old);
            $this->line("  <fg=green>Restored: {$backupTable} -> {$old}</>");
        }

        $this->newLine();
        $this->info('Rollback complete!');
        $this->newLine();

        $this->line('Remember to:');
        $this->line('  1. Update your composer.json to use juhasev/laravel-ses');
        $this->line('  2. Update your code namespaces back to Juhasev\\LaravelSes');
        $this->line('  3. Restore your old config/laravelses.php');

        return Command::SUCCESS;
    }
}
