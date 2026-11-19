<?php

namespace ErnestDefoe\Brainstorm\Access;

use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;

/** Published quizzes for anyone who may view quizzes, plus the drafts this actor may edit. */
class ScopeQuizVisibility
{
    public function __invoke(User $actor, Builder $query): void
    {
        $table = $query->getModel()->getTable();

        if (! $actor->hasPermission('brainstorm.view')) {
            $query->whereIn("$table.id", []);

            return;
        }

        if ($actor->hasPermission('brainstorm.manage')) {
            return;
        }

        $query->where(function (Builder $q) use ($actor, $table) {
            $q->where("$table.status", 'published');

            if (! $actor->isGuest() && $actor->hasPermission('brainstorm.create')) {
                $q->orWhere("$table.user_id", $actor->id);
            }
        });
    }
}
