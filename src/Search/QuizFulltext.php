<?php

namespace ErnestDefoe\Brainstorm\Search;

use ErnestDefoe\Brainstorm\TitleWords;
use Flarum\Search\AbstractFulltextFilter;
use Flarum\Search\Database\DatabaseSearchState;
use Flarum\Search\SearchState;

/**
 * filter[q]: quizzes with a title word starting with each word typed, in any
 * case: "euro cap" finds "European capitals". Matched on the indexed title
 * words, start of word only, so the index answers it.
 *
 * @extends AbstractFulltextFilter<DatabaseSearchState>
 */
class QuizFulltext extends AbstractFulltextFilter
{
    /** Words of a search that count. */
    public const WORDS = 5;

    public function search(SearchState $state, string $value): void
    {
        /** @var DatabaseSearchState $state */
        $query = $state->getQuery();

        // Letters and digits only, so nothing typed is a LIKE wildcard.
        foreach (array_slice(TitleWords::of(mb_substr($value, 0, 100)), 0, self::WORDS) as $word) {
            $query->whereIn('quizzes.id', TitleWords::query()->where('word', 'like', $word.'%')->select('quiz_id'));
        }
    }
}
