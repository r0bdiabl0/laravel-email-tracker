<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Get table name with configured prefix.
     */
    protected function tableName(string $name): string
    {
        $prefix = config('email-tracker.table_prefix', '');

        return $prefix ? "{$prefix}_{$name}" : $name;
    }

    public function up(): void
    {
        // Batches table
        Schema::create($this->tableName('batches'), function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique()->nullable();
            $table->timestamps();
        });

        // Sent emails table
        Schema::create($this->tableName('sent_emails'), function (Blueprint $table) {
            $table->id();
            $table->string('provider')->default('ses')->index();
            $table->foreignId('batch_id')
                ->nullable()
                ->constrained($this->tableName('batches'))
                ->nullOnDelete();
            $table->string('message_id')->index();
            $table->string('email')->index();
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('delivered_at')->nullable();
            $table->boolean('complaint_tracking')->default(false);
            $table->boolean('delivery_tracking')->default(false);
            $table->boolean('bounce_tracking')->default(false);
            $table->timestamps();

            // Composite indexes for common queries
            $table->index(['provider', 'email']);
            $table->index(['provider', 'sent_at']);
        });

        // Email opens table
        Schema::create($this->tableName('email_opens'), function (Blueprint $table) {
            $table->id();
            $table->foreignId('sent_email_id')
                ->constrained($this->tableName('sent_emails'))
                ->cascadeOnDelete();
            $table->string('beacon_identifier')->index();
            $table->dateTime('opened_at')->nullable();
        });

        // Email bounces table
        Schema::create($this->tableName('email_bounces'), function (Blueprint $table) {
            $table->id();
            $table->string('provider')->default('ses')->index();
            $table->foreignId('sent_email_id')
                ->constrained($this->tableName('sent_emails'))
                ->cascadeOnDelete();
            $table->string('type')->nullable(); // Permanent, Transient, Undetermined
            $table->string('email')->index();
            $table->dateTime('bounced_at')->nullable();

            // Index for email validation queries
            $table->index(['provider', 'email']);
        });

        // Email complaints table
        Schema::create($this->tableName('email_complaints'), function (Blueprint $table) {
            $table->id();
            $table->string('provider')->default('ses')->index();
            $table->foreignId('sent_email_id')
                ->constrained($this->tableName('sent_emails'))
                ->cascadeOnDelete();
            $table->string('type')->nullable(); // abuse, auth-failure, fraud, etc.
            $table->string('email')->index();
            $table->dateTime('complained_at')->nullable();

            // Index for email validation queries
            $table->index(['provider', 'email']);
        });

        // Email links table
        Schema::create($this->tableName('email_links'), function (Blueprint $table) {
            $table->id();
            $table->foreignId('sent_email_id')
                ->constrained($this->tableName('sent_emails'))
                ->cascadeOnDelete();
            $table->string('link_identifier')->index();
            $table->text('original_url');
            $table->boolean('clicked')->default(false);
            $table->unsignedInteger('click_count')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->tableName('email_links'));
        Schema::dropIfExists($this->tableName('email_complaints'));
        Schema::dropIfExists($this->tableName('email_bounces'));
        Schema::dropIfExists($this->tableName('email_opens'));
        Schema::dropIfExists($this->tableName('sent_emails'));
        Schema::dropIfExists($this->tableName('batches'));
    }
};
