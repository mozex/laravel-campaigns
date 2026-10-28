<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;

class Rows
{
    /**
     * The row matching the attributes, made from them and the values when
     * there is none. When two requests make the same row at once, the
     * loser's insert fails on the unique index and it reads the winner's
     * row with a locking read: inside a transaction MySQL's plain read
     * returns the snapshot taken before that row existed, so Laravel's own
     * createOrFirst() finds nothing there and throws.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $values
     * @return TModel
     */
    public static function firstOrCreate(Builder $query, array $attributes, array $values = []): Model
    {
        $existing = (clone $query)->where($attributes)->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            // A savepoint, so the failed insert leaves a PostgreSQL
            // transaction usable.
            return $query->getConnection()->transaction(fn (): Model => (clone $query)->create([...$attributes, ...$values]));
        } catch (UniqueConstraintViolationException $exception) {
            return (clone $query)->where($attributes)->lockForUpdate()->first() ?? throw $exception;
        }
    }
}
