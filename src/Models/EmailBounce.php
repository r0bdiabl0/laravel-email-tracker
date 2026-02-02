<?php

declare(strict_types=1);

namespace R0bdiabl0\EmailTracker\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use R0bdiabl0\EmailTracker\Contracts\EmailBounceContract;
use R0bdiabl0\EmailTracker\Enums\BounceType;
use R0bdiabl0\EmailTracker\ModelResolver;
use R0bdiabl0\EmailTracker\Models\Concerns\HasConfigurableTable;

class EmailBounce extends Model implements EmailBounceContract
{
    use HasConfigurableTable;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'sent_email_id' => 'integer',
        'bounced_at' => 'datetime',
        'metadata' => 'array',
    ];

    protected function getBaseTableName(): string
    {
        return 'email_bounces';
    }

    protected static function booted(): void
    {
        static::creating(function (EmailBounce $bounce) {
            $bounce->provider ??= config('email-tracker.default_provider', 'ses');
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

    public function scopePermanent(Builder $query): Builder
    {
        return $query->where('type', BounceType::Permanent->value);
    }

    public function scopeTransient(Builder $query): Builder
    {
        return $query->where('type', BounceType::Transient->value);
    }

    // Contract methods

    public function getId(): mixed
    {
        return $this->getKey();
    }

    // Helper methods

    public function isPermanent(): bool
    {
        return $this->type === BounceType::Permanent->value;
    }

    public function isTransient(): bool
    {
        return $this->type === BounceType::Transient->value;
    }

    public function getBounceType(): ?BounceType
    {
        if ($this->type === null) {
            return null;
        }

        return BounceType::tryFrom($this->type);
    }
}
