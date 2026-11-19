<?php

use Illuminate\Database\Schema\Builder;

/**
 * The words of the titles of quizzes written before the search table
 * existed. The same split as TitleWords::of(), written out here so the
 * migration does not depend on code that may change after it.
 */
return [
    'up' => function (Builder $schema) {
        $db = $schema->getConnection();

        $db->table('quizzes')->select(['id', 'title'])->orderBy('id')->chunkById(200, function ($quizzes) use ($db) {
            $rows = [];
            foreach ($quizzes as $quiz) {
                $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower((string) $quiz->title), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                foreach (array_slice(array_unique(array_map(fn ($w) => mb_substr($w, 0, 50), $words)), 0, 40) as $word) {
                    $rows[] = ['quiz_id' => $quiz->id, 'word' => $word];
                }
            }
            $db->table('quiz_title_words')->insertOrIgnore($rows);
        });
    },
    'down' => function (Builder $schema) {
        $schema->getConnection()->table('quiz_title_words')->delete();
    },
];
