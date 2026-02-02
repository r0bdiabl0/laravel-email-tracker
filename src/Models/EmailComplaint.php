<?php

declare(strict_types=1);

namespace R0bdiabl0\EmailTracker\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use R0bdiabl0\EmailTracker\Contracts\EmailComplaintContract;
use R0bdiabl0\EmailTracker\ModelResolver;
use R0bdiabl0\EmailTracker\Models\Concerns\HasConfigurableTable;

class EmailComplaint extends Model implements EmailComplaintContract
{
    use HasConfigurableTable;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'sent_email_id' => 'integer',
        'complained_at' => 'datetime',
        'metadata' => 'array',
    ];

    protected function getBaseTableName(): string
    {
        return 'email_complaints';
    }

    protected static function booted(): void
    {
        static::creating(function (EmailComplaint $complaint) {
            $complaint->provider ??= config('email-tracker.default_provider', 'ses');
        });
    }

    // Relationships

    public function sentEmail(): BelongsTo
    {
        return $this->belongsTo(ModelResolver::get('sent_email'));
    }

    // Scopes

    public function scopeForProvider(Builder $query, string $provider): Builder
    {
        return $query->where('provider', $provider);
    }

    public function scopeForEmail(Builder $query, string $email): Builder
    {
        return $query->where('email', $email);
    }

    // Contract methods

    public function getId(): mixed
    {
        return $this->getKey();
    }
}
