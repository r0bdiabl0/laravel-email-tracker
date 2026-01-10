<?php

declare(strict_types=1);

namespace R0bdiabl0\EmailTracker\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use R0bdiabl0\EmailTracker\Contracts\EmailOpenContract;
use R0bdiabl0\EmailTracker\ModelResolver;
use R0bdiabl0\EmailTracker\Models\Concerns\HasConfigurableTable;

class EmailOpen extends Model implements EmailOpenContract
{
    use HasConfigurableTable;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'sent_email_id' => 'integer',
        'opened_at' => 'datetime',
    ];

    protected function getBaseTableName(): string
    {
        return 'email_opens';
    }

    // Relationships

    public function sentEmail(): BelongsTo
    {
        return $this->belongsTo(ModelResolver::get('sent_email'));
    }

    // Scopes

    public function scopeForProvider(Builder $query, string $provider): Builder
    {
        return $query->whereHas('sentEmail', function (Builder $q) use ($provider) {
            $q->where('provider', $provider);
        });
    }

    // Contract methods

    public function getId(): mixed
    {
        return $this->getKey();
    }
}
