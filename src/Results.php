<?php

namespace ErnestDefoe\Brainstorm;

use Flarum\User\User;
use Illuminate\Support\Str;

/**
 * A quiz's results, for the people who may edit it: finished attempts, and
 * how each question fared. Attempts are shown whatever the members'
 * leaderboard choice: this is the quiz's own marking, not a ranking.
 */
class Results
{
    public const PAGE = 50;

    /** Question statistics are taken from at most this many recent attempts. */
    public const SAMPLE = 1000;

    public function __construct(private Presenter $presenter, private RichText $rich)
    {
    }

    /** @return array{rows: list<array<string, mixed>>, next: int|null, questions: list<array<string, mixed>>|null} */
    public function page(Quiz $quiz, int $offset): array
    {
        $page = Attempt::query()->where('quiz_id', $quiz->id)->whereNotNull('submitted_at')
            ->orderByDesc('submitted_at')->orderByDesc('id')
            ->skip($offset)->take(self::PAGE + 1)
            ->get(['id', 'user_id', 'submitted_at', 'score', 'max_score', 'percent', 'passed', 'duration', 'expired', 'practice']);

        $users = User::query()->whereIn('id', $page->pluck('user_id')->unique()->all())->get()->keyBy('id');

        $rows = $page->take(self::PAGE)->map(fn (Attempt $a) => [
            'id' => $a->id,
            'user' => ($u = $users->get($a->user_id)) ? $this->presenter->user($u) : null,
            'submittedAt' => $a->submitted_at?->toIso8601String(),
            'score' => $a->score,
            'maxScore' => $a->max_score,
            'percent' => $a->percent,
            'passed' => $a->passed,
            'duration' => $a->duration,
            'expired' => $a->expired,
            'practice' => $a->practice,
        ])->values()->all();

        return [
            'rows' => $rows,
            'next' => $page->count() > self::PAGE ? $offset + self::PAGE : null,
            'questions' => $offset ? null : $this->questionStats($quiz),
        ];
    }

    /**
     * How often each question was answered right, from recent attempts,
     * marked by the same scorer that marked them.
     *
     * @return list<array{id: int, text: string, asked: int, correct: float}>
     */
    private function questionStats(Quiz $quiz): array
    {
        $partial = $quiz->partial_credit;
        $questions = Question::query()->where('quiz_id', $quiz->id)->orderBy('position')->get()->keyBy('id');
        $this->rich->prime([$quiz], $questions->pluck('body'));
        $asked = $earned = [];

        // Raw rows, one at a time: up to SAMPLE attempts of up to 200
        // questions each would otherwise be hydrated as models all at once.
        Attempt::query()->where('quiz_id', $quiz->id)->whereNotNull('submitted_at')->where('practice', false)
            ->orderByDesc('id')->limit(self::SAMPLE)->select(['layout', 'answers'])
            ->toBase()->cursor()
            ->each(function (object $row) use ($questions, $partial, &$asked, &$earned) {
                $answers = json_decode((string) $row->answers, true) ?: [];
                foreach (json_decode((string) $row->layout, true) ?: [] as $slot) {
                    $id = (int) $slot['q'];
                    if ($q = $questions->get($id)) {
                        $asked[$id] = ($asked[$id] ?? 0) + 1;
                        $earned[$id] = ($earned[$id] ?? 0) + Scorer::fraction($q, $answers[(string) $id] ?? null, $partial);
                    }
                }
            });

        return $questions->map(fn (Question $q) => [
            'id' => $q->id,
            // The question as plain words, to label its row.
            'text' => Str::limit(trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $this->rich->html($q->body, $quiz)))), 120),
            'asked' => $asked[$q->id] ?? 0,
            'correct' => isset($asked[$q->id]) ? round($earned[$q->id] / $asked[$q->id] * 100, 1) : 0.0,
        ])->values()->all();
    }
}
