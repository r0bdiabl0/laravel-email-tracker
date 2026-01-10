<?php

declare(strict_types=1);

namespace R0bdiabl0\EmailTracker\Commands;

use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'email-tracker:install
        {--force : Overwrite existing configuration}';

    protected $description = 'Install the Email Tracker package';

    public function handle(): int
    {
        $this->info('Installing Email Tracker...');
        $this->newLine();

        // Publish config
        $this->info('Publishing configuration...');
        $this->call('vendor:publish', [
            '--tag' => 'email-tracker-config',
            '--force' => $this->option('force'),
        ]);

        // Publish migrations
        $this->info('Publishing migrations...');
        $this->call('vendor:publish', [
            '--tag' => 'email-tracker-migrations',
        ]);

        // Run migrations
        if ($this->confirm('Run migrations now?', true)) {
            $this->info('Running migrations...');
            $this->call('migrate');
        }

        $this->newLine();
        $this->info('Email Tracker installed successfully!');
        $this->newLine();

        $this->showNextSteps();

        return Command::SUCCESS;
    }

    protected function showNextSteps(): void
    {
        $this->line('<fg=cyan>Next steps:</>');
        $this->newLine();

        $this->line('  1. Configure your providers in <fg=yellow>config/email-tracker.php</>');
        $this->line('  2. Set up webhook URLs in your email provider dashboard:');
        $this->newLine();

        $baseUrl = config('app.url', 'https://your-app.com');
        $prefix = config('email-tracker.routes.prefix', 'email-tracker');

        $this->line('     <fg=green>SES (AWS SNS):</>');
        $this->line("       Bounce:    {$baseUrl}/{$prefix}/webhook/ses/bounce");
        $this->line("       Complaint: {$baseUrl}/{$prefix}/webhook/ses/complaint");
        $this->line("       Delivery:  {$baseUrl}/{$prefix}/webhook/ses/delivery");
        $this->newLine();

        $this->line('     <fg=green>Other Providers:</>');
        $this->line("       Resend:    {$baseUrl}/{$prefix}/webhook/resend");
        $this->line("       Postal:    {$baseUrl}/{$prefix}/webhook/postal");
        $this->line("       Mailgun:   {$baseUrl}/{$prefix}/webhook/mailgun");
        $this->line("       SendGrid:  {$baseUrl}/{$prefix}/webhook/sendgrid");
        $this->line("       Postmark:  {$baseUrl}/{$prefix}/webhook/postmark");
        $this->newLine();

        $this->line('  3. Start tracking emails:');
        $this->newLine();
        $this->line('     <fg=yellow>use R0bdiabl0\EmailTracker\Facades\EmailTracker;</>');
        $this->newLine();
        $this->line('     <fg=yellow>EmailTracker::enableAllTracking()</>');
        $this->line('         <fg=yellow>->to($email)</>');
        $this->line('         <fg=yellow>->send(new YourMailable());</>');
        $this->newLine();

        $this->line('  4. Documentation: <fg=blue>https://github.com/r0bdiabl0/laravel-email-tracker</>');
        $this->newLine();
    }
}
