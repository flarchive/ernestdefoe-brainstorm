<?php

namespace ErnestDefoe\Brainstorm\Api;

use ErnestDefoe\Brainstorm\Access;
use ErnestDefoe\Brainstorm\Attempts;
use ErnestDefoe\Brainstorm\Presenter;
use ErnestDefoe\Brainstorm\Quiz;
use Flarum\Http\RequestUtil;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/quizzes/{id}/leaderboard — best result per member at one quiz.
 * GET /api/quiz-leaderboard         — quizzes passed, across every quiz.
 *
 * 🚨 Privacy: a member who chose to stay off leaderboards never appears on
 * anybody else's, nor does a member the viewer may not see. They still see
 * their own standing, as "you", and so does nobody else.
 */
class LeaderboardController implements RequestHandlerInterface
{
    use Concerns;

    public const SIZE = 25;

    public function __construct(private ConnectionInterface $db, private Presenter $presenter, private Attempts $attempts)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $limit = max(1, min(self::SIZE, (int) ($request->getQueryParams()['limit'] ?? self::SIZE)));

        if ($this->routeId($request)) {
            return $this->quiz($this->visibleQuiz($request, $actor), $actor, $limit);
        }

        if (! $actor->hasPermission('brainstorm.view')) {
            return new JsonResponse(['data' => [], 'meta' => ['me' => null]]);
        }

        return $this->overall($actor, $limit);
    }

    private function quiz(Quiz $quiz, User $actor, int $limit): ResponseInterface
    {
        if (! $quiz->leaderboard && ! Access::canEdit($actor, $quiz)) {
            return new JsonResponse(['data' => [], 'meta' => ['me' => null, 'off' => true]]);
        }

        // Timed attempts abandoned mid-way are marked before anyone is ranked.
        $this->attempts->expireOverdue($quiz);

        $base = fn () => $this->visible($this->db->table('quiz_bests')->where('quiz_bests.quiz_id', $quiz->id), $actor);

        $rows = $base()
            ->orderByDesc('quiz_bests.percent')->orderBy('quiz_bests.duration')->orderBy('quiz_bests.achieved_at')
            ->limit($limit)
            ->get(['quiz_bests.user_id', 'quiz_bests.percent', 'quiz_bests.score', 'quiz_bests.duration', 'quiz_bests.attempts', 'quiz_bests.passed']);

        $users = $this->users($rows->pluck('user_id')->all());
        $data = [];
        foreach ($rows as $i => $row) {
            if ($user = $users[(int) $row->user_id] ?? null) {
                $data[] = [
                    'rank' => $i + 1,
                    'user' => $this->presenter->user($user),
                    'percent' => (float) $row->percent,
                    'score' => (float) $row->score,
                    'duration' => (int) $row->duration,
                    'attempts' => (int) $row->attempts,
                    'passed' => (bool) $row->passed,
                ];
            }
        }

        $me = null;
        if (! $actor->isGuest()) {
            $mine = $this->db->table('quiz_bests')->where('quiz_id', $quiz->id)->where('user_id', $actor->id)->first();

            if ($mine) {
                $ahead = $base()->where(fn (Builder $q) => $q
                    ->where('quiz_bests.percent', '>', $mine->percent)
                    ->orWhere(fn (Builder $q) => $q->where('quiz_bests.percent', $mine->percent)->where('quiz_bests.duration', '<', $mine->duration)))
                    ->count();

                $me = ['rank' => $ahead + 1, 'percent' => (float) $mine->percent, 'duration' => (int) $mine->duration, 'passed' => (bool) $mine->passed];
            }
        }

        return new JsonResponse(['data' => $data, 'meta' => ['me' => $me]]);
    }

    /**
     * Ranked by quizzes passed, then by the scores behind them. Only quizzes
     * that are published, keep a leaderboard and the viewer may see count.
     */
    private function overall(User $actor, int $limit): ResponseInterface
    {
        $grammar = $this->db->getQueryGrammar();
        $percent = $grammar->wrap('quiz_bests.percent');

        $totals = fn () => $this->visible($this->db->table('quiz_bests'), $actor)
            ->join('quizzes', 'quizzes.id', '=', 'quiz_bests.quiz_id')
            ->where('quizzes.status', 'published')
            ->where('quizzes.leaderboard', true)
            ->where('quiz_bests.passed', true)
            ->groupBy('quiz_bests.user_id')
            ->select('quiz_bests.user_id')
            ->selectRaw('COUNT(*) AS passed_count')
            ->selectRaw("SUM($percent) AS total_percent");

        $rows = $totals()->orderByDesc('passed_count')->orderByDesc('total_percent')->orderBy('quiz_bests.user_id')->limit($limit)->get();

        $users = $this->users($rows->pluck('user_id')->all());
        $data = [];
        foreach ($rows as $i => $row) {
            if ($user = $users[(int) $row->user_id] ?? null) {
                $data[] = [
                    'rank' => $i + 1,
                    'user' => $this->presenter->user($user),
                    'passed' => (int) $row->passed_count,
                    'average' => round((float) $row->total_percent / max(1, (int) $row->passed_count), 1),
                ];
            }
        }

        $me = null;
        if (! $actor->isGuest()) {
            $mine = $totals()->where('quiz_bests.user_id', $actor->id)->first();

            if ($mine) {
                $ahead = $this->db->query()->fromSub($totals(), 't')
                    ->where(fn (Builder $q) => $q
                        ->where('passed_count', '>', $mine->passed_count)
                        ->orWhere(fn (Builder $q) => $q->where('passed_count', $mine->passed_count)->where('total_percent', '>', $mine->total_percent)))
                    ->count();

                $me = ['rank' => $ahead + 1, 'passed' => (int) $mine->passed_count, 'average' => round((float) $mine->total_percent / max(1, (int) $mine->passed_count), 1)];
            }
        }

        return new JsonResponse(['data' => $data, 'meta' => ['me' => $me]]);
    }

    /** Rows of members who are on leaderboards and whom the viewer may see (the viewer always counts). */
    private function visible(Builder $query, User $actor): Builder
    {
        return $query
            ->join('users', 'users.id', '=', 'quiz_bests.user_id')
            ->where(fn (Builder $q) => $q->where('users.quiz_private', false)->orWhere('users.id', $actor->id))
            ->whereIn('quiz_bests.user_id', User::query()->whereVisibleTo($actor)->select('id')->getQuery());
    }

    /**
     * @param list<int|string> $ids
     * @return array<int, User>
     */
    private function users(array $ids): array
    {
        return $ids ? User::query()->whereIn('id', $ids)->get()->keyBy('id')->all() : [];
    }
}
