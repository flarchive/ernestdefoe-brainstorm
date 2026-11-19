<?php

namespace ErnestDefoe\Brainstorm\Search\Filter;

use Flarum\Search\Database\DatabaseSearchState;
use Flarum\Search\Filter\FilterInterface;
use Flarum\Search\SearchState;

/**
 * filter[mine]=1: the actor's own quizzes, drafts included. A guest has none.
 *
 * @implements FilterInterface<DatabaseSearchState>
 */
class MineFilter implements FilterInterface
{
    public function getFilterKey(): string
    {
        return 'mine';
    }

    public function filter(SearchState $state, string|array $value, bool $negate): void
    {
        /** @var DatabaseSearchState $state */
        $actor = $state->getActor();

        $state->getQuery()->where('quizzes.user_id', $negate ? '!=' : '=', $actor->isGuest() ? 0 : $actor->id);
    }
}
