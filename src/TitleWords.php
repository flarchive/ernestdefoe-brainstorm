<?php

namespace ErnestDefoe\Brainstorm;

use Flarum\Database\AbstractModel;

/**
 * Each word of a quiz's title, lower-cased, one row per word: the quiz
 * search matches what is typed against the start of these words, which an
 * index answers, rather than scanning every title for a substring.
 *
 * @property int $quiz_id
 * @property string $word
 */
class TitleWords extends AbstractModel
{
    /** Longest word kept; a longer one is cut (and still found by its start). */
    public const LENGTH = 50;

    /** Words kept per title. */
    public const MAX = 40;

    protected $table = 'quiz_title_words';

    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = null;

    /**
     * The searchable words of a text: letters and digits in any script,
     * lower-cased, each once.
     *
     * @return list<string>
     */
    public static function of(string $text): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_slice(array_values(array_unique(array_map(fn ($w) => mb_substr($w, 0, self::LENGTH), $words))), 0, self::MAX);
    }

    /** Replace a quiz's words with those of its title. */
    public static function index(Quiz $quiz): void
    {
        self::query()->where('quiz_id', $quiz->id)->delete();
        // Ignoring a clash: a database whose collation ignores accents or case
        // (MySQL's default) counts "cafe" and "café" as one word.
        self::query()->insertOrIgnore(array_map(fn ($word) => ['quiz_id' => $quiz->id, 'word' => $word], self::of($quiz->title)));
    }
}
