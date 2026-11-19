<?php

namespace ErnestDefoe\Brainstorm\Access;

use ErnestDefoe\Brainstorm\Quiz;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * A member's own attempts, at quizzes they can still see; and, for whoever
 * may edit a quiz, everyone's attempts at it (its results). Nobody else's.
 */
class ScopeAttemptVisibility
{
    public function __invoke(User $actor, Builder $query): void
    {
        $table = $query->getModel()->getTable();

        if ($actor->isGuest()) {
            $query->whereIn("$table.id", []);

            return;
        }

        $query->whereIn("$table.quiz_id", Quiz::query()->whereVisibleTo($actor)->select('quizzes.id'));

        if ($actor->hasPermission('brainstorm.manage')) {
            return;
        }

        $query->where(function (Builder $q) use ($actor, $table) {
            $q->where("$table.user_id", $actor->id);

            if ($actor->hasPermission('brainstorm.create')) {
                $q->orWhereIn("$table.quiz_id", Quiz::query()->where('user_id', $actor->id)->select('id'));
            }
        });
    }
}
