<?php

namespace ErnestDefoe\Brainstorm\Search;

use ErnestDefoe\Brainstorm\Quiz;
use Flarum\Search\Database\AbstractSearcher;
use Flarum\Search\SearchCriteria;
use Flarum\Search\SearchResults;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The quizzes the actor may see. A list asked for without `ids` or `mine`
 * shows published quizzes only: drafts appear on their writer's own list and
 * where a post links to them, never among everyone's.
 */
class QuizSearcher extends AbstractSearcher
{
    /** @return Builder<Quiz> */
    public function getQuery(User $actor): Builder
    {
        return Quiz::query()->select('quizzes.*')->whereVisibleTo($actor);
    }

    public function search(SearchCriteria $criteria): SearchResults
    {
        if (! isset($criteria->filters['ids']) && ! isset($criteria->filters['mine'])) {
            $criteria->filters['status'] = 'published';
        }

        return parent::search($criteria);
    }
}
