<?php

namespace ErnestDefoe\Brainstorm;

use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The best result per member at one quiz, and quizzes passed across all.
 *
 * 🚨 Privacy: a member who chose to stay off leaderboards never appears on
 * anybody else's, nor does a member the viewer may not see. They still see
 * their own standing, as "you", and so does nobody else.
 */
class Leaderboards
{
    public const SIZE = 25;

    public function __construct(private Presenter $presenter)
    {
    }

    /** @return array{rows: list<array<string, mixed>>, me: array<string, mixed>|null, off?: bool} */
    public function quiz(Quiz $quiz, User $actor, int $limit): array
    {
        if (! $quiz->leaderboard && ! Access::canEdit($actor, $quiz)) {
            return ['rows' => [], 'me' => null, 'off' => true];
        }

        $base = fn () => $this->visible(Best::query()->where('quiz_bests.quiz_id', $quiz->id), $actor);

        $rows = $base()
            ->orderByDesc('quiz_bests.percent')->orderBy('quiz_bests.duration')->orderBy('quiz_bests.achieved_at')
            ->limit($limit)
            ->get(['quiz_bests.user_id', 'quiz_bests.percent', 'quiz_bests.score', 'quiz_bests.duration', 'quiz_bests.attempts', 'quiz_bests.passed']);

        $out = $this->ranked($rows->all(), fn (Best $row) => [
            'percent' => $row->percent,
            'score' => $row->score,
            'duration' => $row->duration,
            'attempts' => $row->attempts,
            'passed' => $row->passed,
        ]);

        $mine = $actor->isGuest() ? null : Best::query()->where('quiz_id', $quiz->id)->where('user_id', $actor->id)->first();
        $me = null;

        if ($mine) {
            $ahead = $base()->where(fn (Builder $q) => $q
                ->where('quiz_bests.percent', '>', $mine->percent)
                ->orWhere(fn (Builder $q) => $q->where('quiz_bests.percent', $mine->percent)->where('quiz_bests.duration', '<', $mine->duration)))
                ->count();

            $me = ['rank' => $ahead + 1, 'percent' => $mine->percent, 'duration' => $mine->duration, 'passed' => $mine->passed];
        }

        return ['rows' => $out, 'me' => $me];
    }

    /**
     * Ranked by quizzes passed, then by the scores behind them. Only quizzes
     * that are published, keep a leaderboard and the viewer may see count.
     *
     * @return array{rows: list<array<string, mixed>>, me: array<string, mixed>|null}
     */
    public function overall(User $actor, int $limit): array
    {
        if (! $actor->hasPermission('brainstorm.view')) {
            return ['rows' => [], 'me' => null];
        }

        $rows = $this->totals($actor)->orderByDesc('passed_count')->orderByDesc('total_percent')->orderBy('quiz_bests.user_id')->limit($limit)->get();

        $out = $this->ranked($rows->all(), fn (Best $row) => [
            'passed' => (int) $row->getAttribute('passed_count'),
            'average' => $this->average($row),
        ]);

        $mine = $actor->isGuest() ? null : $this->totals($actor)->where('quiz_bests.user_id', $actor->id)->first();
        $me = null;

        if ($mine) {
            $passed = (int) $mine->getAttribute('passed_count');
            $total = (float) $mine->getAttribute('total_percent');
            $ahead = Best::query()->fromSub($this->totals($actor), 't')
                ->where(fn (Builder $q) => $q
                    ->where('passed_count', '>', $passed)
                    ->orWhere(fn (Builder $q) => $q->where('passed_count', $passed)->where('total_percent', '>', $total)))
                ->count();

            $me = ['rank' => $ahead + 1, 'passed' => $passed, 'average' => $this->average($mine)];
        }

        return ['rows' => $out, 'me' => $me];
    }

    /** @return Builder<Best> each counted member's passes and their summed percentages */
    private function totals(User $actor): Builder
    {
        $percent = Best::query()->getQuery()->getGrammar()->wrap('quiz_bests.percent');

        return $this->visible(Best::query(), $actor)
            ->join('quizzes', 'quizzes.id', '=', 'quiz_bests.quiz_id')
            ->where('quizzes.status', 'published')
            ->where('quizzes.leaderboard', true)
            // Course-only quizzes count only for those who may see them.
            ->whereIn('quiz_bests.quiz_id', Quiz::query()->whereVisibleTo($actor)->select('quizzes.id'))
            ->where('quiz_bests.passed', true)
            ->groupBy('quiz_bests.user_id')
            ->select('quiz_bests.user_id')
            // Aggregates the builder has no method for alongside a group:
            // the column is wrapped by the grammar, so the table prefix applies.
            ->selectRaw('COUNT(*) AS passed_count')
            ->selectRaw("SUM($percent) AS total_percent");
    }

    private function average(Best $row): float
    {
        return round((float) $row->getAttribute('total_percent') / max(1, (int) $row->getAttribute('passed_count')), 1);
    }

    /**
     * Rows of members who are on leaderboards and whom the viewer may see
     * (the viewer always counts).
     *
     * @param Builder<Best> $query
     * @return Builder<Best>
     */
    private function visible(Builder $query, User $actor): Builder
    {
        return $query
            ->whereNotIn('quiz_bests.user_id', OptOut::userIds()->where('user_id', '!=', $actor->id))
            ->whereIn('quiz_bests.user_id', User::query()->whereVisibleTo($actor)->select('users.id'));
    }

    /**
     * Each row with its rank and member, the members loaded in one query.
     * A row whose member is gone is skipped.
     *
     * @param list<Best> $rows
     * @param callable(Best): array<string, mixed> $fields
     * @return list<array<string, mixed>>
     */
    private function ranked(array $rows, callable $fields): array
    {
        $ids = array_map(fn (Best $row) => $row->user_id, $rows);
        $users = $ids ? User::query()->whereIn('id', $ids)->get()->keyBy('id') : collect();
        $out = [];

        foreach ($rows as $i => $row) {
            if ($user = $users->get($row->user_id)) {
                $out[] = ['rank' => $i + 1, 'user' => $this->presenter->user($user)] + $fields($row);
            }
        }

        return $out;
    }
}
