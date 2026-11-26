<?php

namespace ErnestDefoe\Brainstorm\Access;

use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The rule quizzes and assessments share: nothing without "View quizzes";
 * everything for managers; otherwise published ones, plus the actor's own
 * while they may write quizzes. {@see published()} lets a subclass narrow
 * what "published" shows.
 */
abstract class ScopePublishedOrOwn
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
            $q->where(function (Builder $open) use ($actor, $table) {
                $open->where("$table.status", 'published');
                $this->published($open, $actor, $table);
            });

            if (! $actor->isGuest() && $actor->hasPermission('brainstorm.create')) {
                $q->orWhere("$table.user_id", $actor->id);
            }
        });
    }

    /**
     * Narrow the published ones this actor may see; by default, all of them.
     *
     * @param Builder<\ErnestDefoe\Brainstorm\Quiz>|Builder<\ErnestDefoe\Brainstorm\Assessment\Assessment> $query
     */
    protected function published(Builder $query, User $actor, string $table): void
    {
    }
}
