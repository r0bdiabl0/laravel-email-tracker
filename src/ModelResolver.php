<?php

declare(strict_types=1);

namespace R0bdiabl0\EmailTracker;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class ModelResolver
{
    /**
     * Get the model class for a given model type.
     *
     * @template T of Model
     *
     * @param  string  $model  The model type key (e.g., 'sent_email', 'batch')
     *
     * @throws InvalidArgumentException If the model key is not configured
     *
     * @return class-string<T>
     */
    public static function get(string $model): string
    {
        // Normalize model key (support both camelCase and snake_case)
        $key = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $model));

        $class = config("email-tracker.models.{$key}");

        if ($class === null) {
            throw new InvalidArgumentException(
                "Unknown model type '{$model}'. Available types: sent_email, email_bounce, email_complaint, email_open, email_link, batch",
            );
        }

        return $class;
    }

    /**
     * Create a new instance of the given model type.
     *
     * @template T of Model
     *
     * @param  string  $model  The model type key
     * @param  array  $attributes  Model attributes
     *
     * @return T
     */
    public static function make(string $model, array $attributes = []): Model
    {
        $class = static::get($model);

        return new $class($attributes);
    }

    /**
     * Get a query builder for the given model type.
     *
     * @param  string  $model  The model type key
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public static function query(string $model)
    {
        $class = static::get($model);

        return $class::query();
    }
}
