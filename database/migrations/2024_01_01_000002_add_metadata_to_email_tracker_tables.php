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
        // Add metadata column to email_bounces table
        if (Schema::hasTable($this->tableName('email_bounces'))) {
            if (! Schema::hasColumn($this->tableName('email_bounces'), 'metadata')) {
                Schema::table($this->tableName('email_bounces'), function (Blueprint $table) {
                    $table->json('metadata')->nullable()->after('bounced_at');
                });
            }
        }

        // Add metadata column to email_complaints table
        if (Schema::hasTable($this->tableName('email_complaints'))) {
            if (! Schema::hasColumn($this->tableName('email_complaints'), 'metadata')) {
                Schema::table($this->tableName('email_complaints'), function (Blueprint $table) {
                    $table->json('metadata')->nullable()->after('complained_at');
                });
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable($this->tableName('email_bounces'))) {
            if (Schema::hasColumn($this->tableName('email_bounces'), 'metadata')) {
                Schema::table($this->tableName('email_bounces'), function (Blueprint $table) {
                    $table->dropColumn('metadata');
                });
            }
        }

        if (Schema::hasTable($this->tableName('email_complaints'))) {
            if (Schema::hasColumn($this->tableName('email_complaints'), 'metadata')) {
                Schema::table($this->tableName('email_complaints'), function (Blueprint $table) {
                    $table->dropColumn('metadata');
                });
            }
        }
    }
};
