<?php

declare(strict_types=1);

namespace R0bdiabl0\EmailTracker\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use R0bdiabl0\EmailTracker\Contracts\SentEmailContract;
use R0bdiabl0\EmailTracker\ModelResolver;
use R0bdiabl0\EmailTracker\Models\Concerns\HasConfigurableTable;

class SentEmail extends Model implements SentEmailContract
{
    use HasConfigurableTable;

    protected $guarded = [];

    protected $casts = [
        'batch_id' => 'integer',
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'complaint_tracking' => 'boolean',
        'delivery_tracking' => 'boolean',
        'bounce_tracking' => 'boolean',
    ];

    protected $hidden = [
        'complaint_tracking',
        'delivery_tracking',
        'bounce_tracking',
    ];

    protected function getBaseTableName(): string
    {
        return 'sent_emails';
    }

    protected static function booted(): void
    {
        static::creating(function (SentEmail $email) {
            $email->provider ??= config('email-tracker.default_provider', 'ses');
        });
    }

    // Relationships

    public function emailOpen(): HasOne
    {
        return $this->hasOne(ModelResolver::get('email_open'));
    }

    public function emailLinks(): HasMany
    {
        return $this->hasMany(ModelResolver::get('email_link'));
    }

    public function emailBounce(): HasOne
    {
        return $this->hasOne(ModelResolver::get('email_bounce'));
    }

    public function emailComplaint(): HasOne
    {
        return $this->hasOne(ModelResolver::get('email_complaint'));
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ModelResolver::get('batch'));
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

    /**
     * @param  Builder<SentEmail>  $query
     * @return Builder<SentEmail>
     */
    public function scopeDelivered(Builder $query): Builder
    {
        /** @var Builder<SentEmail> */
        return $query->whereNotNull('delivered_at');
    }

    /**
     * @param  Builder<SentEmail>  $query
     * @return Builder<SentEmail>
     */
    public function scopeNotDelivered(Builder $query): Builder
    {
        /** @var Builder<SentEmail> */
        return $query->whereNull('delivered_at');
    }

    public function scopeBounced(Builder $query): Builder
    {
        return $query->whereHas('emailBounce');
    }

    public function scopeComplained(Builder $query): Builder
    {
        return $query->whereHas('emailComplaint');
    }

    // Contract methods

    public function setDeliveredAt(DateTimeInterface $time): self
    {
        $this->update(['delivered_at' => $time]);

        return $this;
    }

    public function setMessageId(string $messageId): self
    {
        $this->update(['message_id' => $messageId]);

        return $this;
    }

    public function getId(): mixed
    {
        return $this->getKey();
    }

    public function getMessageId(): string
    {
        return $this->message_id;
    }

    // Helper methods

    public function wasBounced(): bool
    {
        return $this->emailBounce()->exists();
    }

    public function wasComplained(): bool
    {
        return $this->emailComplaint()->exists();
    }

    public function wasOpened(): bool
    {
        return $this->emailOpen()->exists();
    }

    public function wasDelivered(): bool
    {
        return $this->delivered_at !== null;
    }
}
