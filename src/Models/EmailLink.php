<?php

declare(strict_types=1);

namespace R0bdiabl0\EmailTracker\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use R0bdiabl0\EmailTracker\Contracts\EmailLinkContract;
use R0bdiabl0\EmailTracker\ModelResolver;
use R0bdiabl0\EmailTracker\Models\Concerns\HasConfigurableTable;

class EmailLink extends Model implements EmailLinkContract
{
    use HasConfigurableTable;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'sent_email_id' => 'integer',
        'clicked' => 'boolean',
        'click_count' => 'integer',
    ];

    protected function getBaseTableName(): string
    {
        return 'email_links';
    }

    // Relationships

    public function sentEmail(): BelongsTo
    {
        return $this->belongsTo(ModelResolver::get('sent_email'));
    }

    // Scopes

    public function scopeClicked(Builder $query): Builder
    {
        return $query->where('clicked', true);
    }

    public function scopeNotClicked(Builder $query): Builder
    {
        return $query->where('clicked', false);
    }

    public function scopeForProvider(Builder $query, string $provider): Builder
    {
        return $query->whereHas('sentEmail', function (Builder $q) use ($provider) {
            $q->where('provider', $provider);
        });
    }

    // Contract methods

    public function setClicked(bool $clicked): self
    {
        $this->update(['clicked' => $clicked]);

        return $this;
    }

    public function incrementClickCount(): self
    {
        $this->increment('click_count');

        return $this;
    }

    public function getId(): mixed
    {
        return $this->getKey();
    }

    public function originalUrl(): string
    {
        return $this->original_url;
    }
}
