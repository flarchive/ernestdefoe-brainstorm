<?php

namespace ErnestDefoe\Brainstorm;

use Illuminate\Support\Collection;
use Random\Randomizer;

/**
 * Deals an attempt its paper: which questions, in what order, and the order of
 * each question's options. Shuffling uses the secure engine, so the order can't
 * be predicted from earlier attempts.
 */
class Dealer
{
    /**
     * @param Collection<int, Question> $questions in authored order
     * @return list<array{q: int, o: list<string>}>
     */
    public static function deal(Quiz $quiz, Collection $questions): array
    {
        $random = new Randomizer();
        $list = $questions->values()->all();

        if ($quiz->question_pick && $quiz->question_pick < count($list)) {
            $keys = $random->pickArrayKeys($list, $quiz->question_pick);
            // pickArrayKeys keeps the keys in order: an unshuffled quiz
            // still asks its picked questions in the order they were written.
            $list = array_map(fn ($k) => $list[$k], $keys);
        }

        if ($quiz->shuffle_questions) {
            $list = $random->shuffleArray($list);
        }

        return array_values(array_map(function (Question $q) use ($quiz, $random) {
            $ids = array_map(fn ($o) => (string) $o['id'], $q->options ?: []);

            if ($q->type === 'text') {
                $ids = [];
            } elseif ($quiz->shuffle_options && $q->type !== 'boolean') {
                $ids = $random->shuffleArray($ids);
            }

            return ['q' => $q->id, 'o' => array_values($ids)];
        }, $list));
    }
}
