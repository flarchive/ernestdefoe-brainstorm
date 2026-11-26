<?php

namespace ErnestDefoe\Brainstorm\Search\Filter;

use Flarum\Search\Database\DatabaseSearchState;
use Flarum\Search\Filter\FilterInterface;
use Flarum\Search\SearchState;
use Flarum\Search\ValidateFilterTrait;

/**
 * filter[status]=published|draft, within what the actor may see anyway.
 *
 * @implements FilterInterface<DatabaseSearchState>
 */
class StatusFilter implements FilterInterface
{
    use ValidateFilterTrait;

    public function getFilterKey(): string
    {
        return 'status';
    }

    public function filter(SearchState $state, string|array $value, bool $negate): void
    {
        /** @var DatabaseSearchState $state */
        $status = $this->asString($value) === 'draft' ? 'draft' : 'published';

        $state->getQuery()->where('quizzes.status', $negate ? '!=' : '=', $status);
    }
}
