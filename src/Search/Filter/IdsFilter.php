<?php

namespace ErnestDefoe\Brainstorm\Search\Filter;

use Flarum\Search\Database\DatabaseSearchState;
use Flarum\Search\Filter\FilterInterface;
use Flarum\Search\SearchState;
use Flarum\Search\ValidateFilterTrait;

/**
 * filter[ids]=1,2,3: the quizzes named by links in posts, at most 50, so a
 * page of posts asks once for every quiz it links to.
 *
 * @implements FilterInterface<DatabaseSearchState>
 */
class IdsFilter implements FilterInterface
{
    use ValidateFilterTrait;

    public const MAX = 50;

    public function getFilterKey(): string
    {
        return 'ids';
    }

    public function filter(SearchState $state, string|array $value, bool $negate): void
    {
        /** @var DatabaseSearchState $state */
        $ids = array_filter(explode(',', $this->asString($value)), fn ($id) => ctype_digit($id) && strlen($id) <= 10 && (int) $id <= 2147483647);
        $ids = array_slice(array_values(array_unique(array_map('intval', $ids))), 0, self::MAX);

        $state->getQuery()->{$negate ? 'whereNotIn' : 'whereIn'}('quizzes.id', $ids ?: [0]);
    }
}
