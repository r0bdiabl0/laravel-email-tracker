<?php

declare(strict_types=1);

namespace R0bdiabl0\EmailTracker\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use R0bdiabl0\EmailTracker\Contracts\BatchContract;
use R0bdiabl0\EmailTracker\ModelResolver;
use R0bdiabl0\EmailTracker\Models\Concerns\HasConfigurableTable;

class Batch extends Model implements BatchContract
{
    use HasConfigurableTable;

    protected $guarded = [];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected function getBaseTableName(): string
    {
        return 'batches';
    }

    public function sentEmails(): HasMany
    {
        return $this->hasMany(ModelResolver::get('sent_email'));
    }

    public static function resolve(string $name): ?BatchContract
    {
        return self::where('name', $name)->first();
    }

    public function getId(): mixed
    {
        return $this->getKey();
    }
}
