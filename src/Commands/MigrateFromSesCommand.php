<?php

declare(strict_types=1);

namespace R0bdiabl0\EmailTracker\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class MigrateFromSesCommand extends Command
{
    protected $signature = 'email-tracker:migrate-from-ses
        {--force : Run without confirmation}
        {--dry-run : Preview changes without executing}
        {--update-code : Also update PHP file namespaces}
        {--skip-config : Skip config file migration}
        {--skip-database : Skip database table migration}
        {--delete-old : Delete old tables after successful migration (use with caution)}';

    protected $description = 'Migrate from juhasev/laravel-ses to r0bdiabl0/laravel-email-tracker';

    // Ordered for foreign key constraints - batches first, then sent_emails, then the rest
    protected array $tableMapping = [
        'laravel_ses_batches' => 'batches',
        'laravel_ses_sent_emails' => 'sent_emails',
        'laravel_ses_email_bounces' => 'email_bounces',
        'laravel_ses_email_complaints' => 'email_complaints',
        'laravel_ses_email_opens' => 'email_opens',
        'laravel_ses_email_links' => 'email_links',
    ];

    // Tables that should have the 'provider' column added during migration
    protected array $tablesWithProvider = [
        'sent_emails',
        'email_bounces',
        'email_complaints',
        'email_opens',
        'email_links',
    ];

    public function handle(): int
    {
        $this->info('Laravel Email Tracker - Migration from juhasev/laravel-ses');
        $this->newLine();

        // Check prerequisites
        if (! $this->checkPrerequisites()) {
            return Command::FAILURE;
        }

        // Show migration plan
        $this->showMigrationPlan();

        // Confirm (unless --force or --dry-run)
        if (! $this->option('force') && ! $this->option('dry-run')) {
            if (! $this->confirm('Proceed with migration?')) {
                $this->info('Migration cancelled.');

                return Command::FAILURE;
            }
        }

        if ($this->option('dry-run')) {
            $this->warn('DRY RUN - No changes will be made.');
            $this->newLine();
        }

        // Execute migrations
        if (! $this->option('skip-database')) {
            $success = $this->migrateTables();

            if (! $success) {
                $this->error('Migration failed. Old tables have not been modified.');

                return Command::FAILURE;
            }
        }

        if (! $this->option('skip-config')) {
            $this->migrateConfig();
        }

        if ($this->option('update-code')) {
            $this->updateCodeNamespaces();
        }

        // Show webhook URL updates
        $this->showWebhookUpdates();

        // Show cleanup instructions
        $this->showCleanupInstructions();

        if (! $this->option('dry-run')) {
            $this->newLine();
            $this->info('Migration complete!');
        }

        return Command::SUCCESS;
    }

    protected function checkPrerequisites(): bool
    {
        $hasOldTables = false;

        foreach (array_keys($this->tableMapping) as $oldTable) {
            if (Schema::hasTable($oldTable)) {
                $hasOldTables = true;

                break;
            }
        }

        if (! $hasOldTables) {
            $this->warn('No juhasev/laravel-ses tables found. Nothing to migrate.');

            return false;
        }

        return true;
    }

    protected function showMigrationPlan(): void
    {
        $this->line('<fg=cyan>Migration Plan:</>');
        $this->newLine();

        $prefix = config('email-tracker.table_prefix', '');

        $this->line('  <fg=yellow>Database Tables (data will be COPIED, old tables preserved):</>');
        foreach ($this->tableMapping as $old => $new) {
            $newName = $prefix ? "{$prefix}_{$new}" : $new;
            if (Schema::hasTable($old)) {
                $rowCount = DB::table($old)->count();
                $this->line("    {$old} ({$rowCount} rows) -> {$newName}");
            }
        }
        $this->newLine();

        $this->line('  <fg=green>Safety:</> Old tables will NOT be modified or deleted.');
        $this->line('           You can delete them manually after verifying the migration.');
        $this->newLine();

        $this->line('  <fg=yellow>Config File:</>');
        if (File::exists(config_path('laravelses.php'))) {
            $this->line('    config/laravelses.php -> config/email-tracker.php');
        } else {
            $this->line('    (No old config file found)');
        }
        $this->newLine();
    }

    protected function migrateTables(): bool
    {
        $this->info('Migrating database tables...');
        $this->newLine();

        $prefix = config('email-tracker.table_prefix', '');

        // Step 1: Check which tables to skip (user declined merge)
        $this->line('  <fg=cyan>Step 1: Checking target tables...</>');
        $tablesToSkip = [];

        foreach ($this->tableMapping as $old => $new) {
            $newName = $prefix ? "{$prefix}_{$new}" : $new;

            if (! Schema::hasTable($old)) {
                continue;
            }

            if (! Schema::hasTable($newName)) {
                $this->error("    New table {$newName} does not exist. Run migrations first:");
                $this->line('    php artisan migrate');

                return false;
            }

            $existingCount = DB::table($newName)->count();
            if ($existingCount > 0) {
                $this->warn("    Table {$newName} already has {$existingCount} rows.");

                if (! $this->option('force') && ! $this->option('dry-run')) {
                    if (! $this->confirm("    Merge data into existing {$newName} table?")) {
                        $tablesToSkip[] = $old;
                        $this->line("    Will skip {$old}.");
                    }
                }
            }
        }

        // Step 2: Copy data from old tables to new tables
        $this->newLine();
        $this->line('  <fg=cyan>Step 2: Copying data to new tables...</>');

        foreach ($this->tableMapping as $old => $new) {
            $newName = $prefix ? "{$prefix}_{$new}" : $new;

            if (in_array($old, $tablesToSkip, true)) {
                $this->line("    <fg=gray>Skipping {$old} (user declined)</>");

                continue;
            }

            if (! Schema::hasTable($old)) {
                $this->line("    <fg=gray>Skipping {$old} (not found)</>");

                continue;
            }

            if ($this->option('dry-run')) {
                $rowCount = DB::table($old)->count();
                $this->line("    Would copy {$rowCount} rows: {$old} -> {$newName}");

                continue;
            }

            try {
                $this->copyTableData($old, $newName, $new);
            } catch (\Exception $e) {
                $this->error("    Failed to copy {$old}: {$e->getMessage()}");

                return false;
            }
        }

        // Step 3: Optionally delete old tables
        if ($this->option('delete-old') && ! $this->option('dry-run')) {
            $this->newLine();
            $this->line('  <fg=cyan>Step 3: Deleting old tables...</>');

            foreach (array_keys($this->tableMapping) as $old) {
                if (Schema::hasTable($old)) {
                    Schema::dropIfExists($old);
                    $this->line("    <fg=red>Deleted: {$old}</>");
                }
            }
        }

        return true;
    }

    protected function copyTableData(string $oldTable, string $newTable, string $baseTableName): void
    {
        $rowCount = DB::table($oldTable)->count();

        if ($rowCount === 0) {
            $this->line("    <fg=gray>Skipping {$oldTable} (empty)</>");

            return;
        }

        // Get columns from old table
        $oldColumns = Schema::getColumnListing($oldTable);
        $newColumns = Schema::getColumnListing($newTable);

        // Find common columns (provider will be added separately if needed)
        $commonColumns = array_intersect($oldColumns, $newColumns);

        // Check if this table should have the provider column
        $shouldAddProvider = in_array($baseTableName, $this->tablesWithProvider, true)
            && in_array('provider', $newColumns, true)
            && ! in_array('provider', $oldColumns, true);

        // Copy data in chunks to handle large tables
        $chunkSize = 1000;
        $copied = 0;
        $skipped = 0;

        DB::table($oldTable)->orderBy('id')->chunk($chunkSize, function ($rows) use ($newTable, $commonColumns, $shouldAddProvider, &$copied, &$skipped) {
            $insertData = [];

            foreach ($rows as $row) {
                $rowData = [];
                foreach ($commonColumns as $column) {
                    $rowData[$column] = $row->{$column};
                }

                // Add provider column with default 'ses' only for tables that need it
                if ($shouldAddProvider) {
                    $rowData['provider'] = 'ses';
                }

                $insertData[] = $rowData;
            }

            // Use insertOrIgnore to skip duplicates (if re-running migration)
            $inserted = DB::table($newTable)->insertOrIgnore($insertData);
            $copied += $inserted;
            $skipped += count($insertData) - $inserted;
        });

        if ($skipped > 0) {
            $this->line("    <fg=green>Copied {$copied} rows, skipped {$skipped} duplicates: {$oldTable} -> {$newTable}</>");
        } else {
            $this->line("    <fg=green>Copied {$copied} rows: {$oldTable} -> {$newTable}</>");
        }
    }

    protected function migrateConfig(): void
    {
        $oldConfigPath = config_path('laravelses.php');

        if (! File::exists($oldConfigPath)) {
            $this->line('  <fg=gray>No old config file found, skipping...</>');

            return;
        }

        if ($this->option('dry-run')) {
            $this->line('  Would migrate config file');

            return;
        }

        $this->info('Migrating configuration...');

        // The user should use vendor:publish for the new config
        // Here we just notify them

        $this->line('  <fg=yellow>Please review your old config/laravelses.php settings</>');
        $this->line('  <fg=yellow>and transfer them to the new config/email-tracker.php</>');
        $this->newLine();

        $this->line('  Run: php artisan vendor:publish --tag=email-tracker-config');
    }

    protected function updateCodeNamespaces(): void
    {
        $this->info('Updating code namespaces...');

        if ($this->option('dry-run')) {
            $this->line('  Would update namespaces in PHP files');

            return;
        }

        $replacements = [
            'use Juhasev\\LaravelSes\\' => 'use R0bdiabl0\\EmailTracker\\',
            'Juhasev\\LaravelSes\\' => 'R0bdiabl0\\EmailTracker\\',
            'SesMail::' => 'EmailTracker::',
        ];

        $appPath = app_path();
        $count = 0;

        $files = File::allFiles($appPath);

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $content = File::get($file->getPathname());
            $originalContent = $content;

            foreach ($replacements as $search => $replace) {
                $content = str_replace($search, $replace, $content);
            }

            if ($content !== $originalContent) {
                File::put($file->getPathname(), $content);
                $count++;
                $this->line("  <fg=green>Updated: {$file->getRelativePathname()}</>");
            }
        }

        $this->line("  <fg=cyan>Updated {$count} files</>");
    }

    protected function showWebhookUpdates(): void
    {
        $this->newLine();
        $this->warn('Important: Update your webhook URLs in AWS SNS!');
        $this->newLine();

        $baseUrl = config('app.url', 'https://your-app.com');
        $prefix = config('email-tracker.routes.prefix', 'email-tracker');

        $this->line('  <fg=cyan>New webhook URLs:</>');
        $this->newLine();

        $webhooks = [
            'Bounce' => "{$baseUrl}/{$prefix}/webhook/ses/bounce",
            'Complaint' => "{$baseUrl}/{$prefix}/webhook/ses/complaint",
            'Delivery' => "{$baseUrl}/{$prefix}/webhook/ses/delivery",
        ];

        foreach ($webhooks as $type => $url) {
            $this->line("    <fg=yellow>{$type}:</> {$url}");
        }

        $this->newLine();
        $this->line('  <fg=gray>Old URLs (if using legacy routes):</>');
        $this->line("    {$baseUrl}/ses/notification/bounce");
        $this->line("    {$baseUrl}/ses/notification/complaint");
        $this->line("    {$baseUrl}/ses/notification/delivery");
        $this->newLine();

        $this->line('  <fg=gray>To enable legacy routes, set EMAIL_TRACKER_LEGACY_ROUTES=true</>');
    }

    protected function showCleanupInstructions(): void
    {
        if ($this->option('delete-old') || $this->option('dry-run')) {
            return;
        }

        $this->newLine();
        $this->line('<fg=cyan>Old Tables Cleanup:</>');
        $this->newLine();
        $this->line('  Your old tables have been preserved. After verifying the migration');
        $this->line('  works correctly, you can delete them manually:');
        $this->newLine();

        foreach (array_keys($this->tableMapping) as $old) {
            if (Schema::hasTable($old)) {
                $this->line("    DROP TABLE {$old};");
            }
        }

        $this->newLine();
        $this->line('  Or re-run with --delete-old flag:');
        $this->line('    php artisan email-tracker:migrate-from-ses --delete-old');
    }
}
