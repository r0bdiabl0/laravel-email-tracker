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
        {--backup : Keep old tables as *_backup}
        {--update-code : Also update PHP file namespaces}
        {--skip-config : Skip config file migration}
        {--skip-database : Skip database table migration}';

    protected $description = 'Migrate from juhasev/laravel-ses to r0bdiabl0/laravel-email-tracker';

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
            $this->migrateTables();
        }

        if (! $this->option('skip-config')) {
            $this->migrateConfig();
        }

        if ($this->option('update-code')) {
            $this->updateCodeNamespaces();
        }

        // Show webhook URL updates
        $this->showWebhookUpdates();

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

        $this->line('  <fg=yellow>Database Tables:</>');
        foreach ($this->tableMapping as $old => $new) {
            $newName = $prefix ? "{$prefix}_{$new}" : $new;
            if (Schema::hasTable($old)) {
                $this->line("    {$old} -> {$newName}");
            }
        }
        $this->newLine();

        $this->line('  <fg=yellow>Config File:</>');
        if (File::exists(config_path('laravelses.php'))) {
            $this->line('    config/laravelses.php -> config/email-tracker.php');
        } else {
            $this->line('    (No old config file found)');
        }
        $this->newLine();

        if ($this->option('backup')) {
            $this->line('  <fg=green>Backup:</>');
            $this->line('    Old tables will be renamed to *_backup');
            $this->newLine();
        }
    }

    protected function migrateTables(): void
    {
        $this->info('Migrating database tables...');

        $prefix = config('email-tracker.table_prefix', '');

        foreach ($this->tableMapping as $old => $new) {
            $newName = $prefix ? "{$prefix}_{$new}" : $new;

            if (! Schema::hasTable($old)) {
                $this->line("  <fg=gray>Skipping {$old} (not found)</>");

                continue;
            }

            if ($this->option('dry-run')) {
                $this->line("  Would rename: {$old} -> {$newName}");

                continue;
            }

            // Check if new table already exists
            if (Schema::hasTable($newName)) {
                $this->warn("  Table {$newName} already exists, skipping...");

                continue;
            }

            // Backup if requested
            if ($this->option('backup')) {
                $backupName = "{$old}_backup";
                if (! Schema::hasTable($backupName)) {
                    Schema::rename($old, $backupName);
                    Schema::rename($backupName, $newName);
                    $this->line("  <fg=green>Renamed: {$old} -> {$newName} (backup: {$backupName})</>");
                } else {
                    Schema::rename($old, $newName);
                    $this->line("  <fg=green>Renamed: {$old} -> {$newName}</>");
                }
            } else {
                Schema::rename($old, $newName);
                $this->line("  <fg=green>Renamed: {$old} -> {$newName}</>");
            }

            // Add provider column if not exists
            if (! Schema::hasColumn($newName, 'provider')) {
                Schema::table($newName, function ($table) {
                    $table->string('provider')->default('ses')->after('id')->index();
                });
                $this->line("  <fg=green>Added provider column to: {$newName}</>");
            }
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
}
