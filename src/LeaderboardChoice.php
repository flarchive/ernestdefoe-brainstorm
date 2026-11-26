<?php

namespace ErnestDefoe\Brainstorm;

use Flarum\User\User;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * `$user->quiz_private` as it read before 1.2, when it was a users column:
 * now worked out from the opt-out table, for extensions that still read it
 * (Buzz keeps an opted-out member's quiz passes off its feed with it). It is
 * never serialized (no users column carries it), and a list that reads it
 * eager-loads `quizOptOut` so all its users cost one query. Nothing
 * is ever written back: the choice is changed through the user's
 * `quizPrivate` API field.
 *
 * @deprecated 1.2.1 Read OptOut instead; remove once Buzz and Syllabus have switched.
 *
 * @implements CastsAttributes<bool, never>
 */
class LeaderboardChoice implements CastsAttributes
{
    /** @param array<string, mixed> $attributes */
    public function get(Model $model, string $key, mixed $value, array $attributes): bool
    {
        if (! $model instanceof User || ! $model->exists) {
            return false;
        }

        // A list loads the relation once for all its users (`with('quizOptOut')`).
        return $model->relationLoaded('quizOptOut')
            ? $model->getRelation('quizOptOut') !== null
            : OptOut::query()->whereKey($model->getKey())->exists();
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        return [];
    }
}
