<?php

namespace ErnestDefoe\Brainstorm;

use Flarum\Http\SlugManager;
use Flarum\User\User;
use Illuminate\Support\Collection;

/**
 * The parts of Brainstorm's API output that need more than a column: the
 * actor's standing at a page of quizzes, the editor's copy, a paper, a marked
 * result and a member as leaderboards show them.
 *
 * 🚨 The answer key (`correct`, a typed question's accepted answers, the
 * explanation) appears in exactly two places: the editor's copy, for people
 * who may edit the quiz, and a marked attempt's review once the quiz's reveal
 * setting allows it. A player's paper never carries it.
 */
class Presenter
{
    public function __construct(
        private RichText $rich,
        private SlugManager $slugs,
        private Rewards $rewards
    ) {
    }

    /**
     * The actor's standing at each of a page of quizzes, and their reward
     * badges, onto each quiz's {@see Quiz::$viewer}; mentions in their
     * descriptions are primed for rendering. A fixed number of queries
     * whatever the count: the actor's attempts, bests and the badges are each
     * loaded once for all.
     *
     * @param iterable<Quiz> $quizzes
     */
    public function prime(iterable $quizzes, User $actor): void
    {
        $quizzes = new Collection(is_array($quizzes) ? $quizzes : iterator_to_array($quizzes, false));
        $ids = $quizzes->pluck('id')->all();
        [$used, $open, $bests] = $ids && ! $actor->isGuest() ? $this->standings($ids, $actor) : [[], [], []];

        $badges = $this->rewards->badges()->visible($quizzes->pluck('reward_badge_id')->filter()->unique()->values()->all());
        $this->rich->prime($quizzes, $quizzes->pluck('description'));

        foreach ($quizzes as $quiz) {
            $best = $bests[$quiz->id] ?? null;
            $usedHere = $used[$quiz->id] ?? 0;

            $quiz->viewer = [
                'badge' => $quiz->reward_badge_id ? ($badges[$quiz->reward_badge_id] ?? null) : null,
                'me' => $actor->isGuest() ? null : [
                    'attemptsUsed' => $usedHere,
                    'attemptsLeft' => $quiz->max_attempts ? max(0, $quiz->max_attempts - $usedHere) : null,
                    'openAttemptId' => $open[$quiz->id] ?? null,
                    'best' => $best ? [
                        'attemptId' => $best->attempt_id,
                        'percent' => $best->percent,
                        'duration' => $best->duration,
                        'passed' => $best->passed,
                    ] : null,
                ],
            ];
        }
    }

    /**
     * @param list<int> $ids
     * @return array{0: array<int, int>, 1: array<int, int>, 2: array<int, Best>} attempts used, open attempt and best, by quiz
     */
    private function standings(array $ids, User $actor): array
    {
        $used = $open = [];
        foreach (Attempt::query()->whereIn('quiz_id', $ids)->where('user_id', $actor->id)->get(['id', 'quiz_id', 'submitted_at']) as $a) {
            $used[$a->quiz_id] = ($used[$a->quiz_id] ?? 0) + 1;
            if ($a->submitted_at === null) {
                $open[$a->quiz_id] = max($open[$a->quiz_id] ?? 0, $a->id);
            }
        }

        $bests = Best::query()->whereIn('quiz_id', $ids)->where('user_id', $actor->id)->get()->keyBy('quiz_id')->all();

        return [$used, $open, $bests];
    }

    /** The description as safe HTML; mentions in it were primed with the page. */
    public function descriptionHtml(Quiz $quiz): ?string
    {
        return $this->rich->html($quiz->description, $quiz);
    }

    /**
     * The quiz as its editor fills in: every setting and the whole answer key.
     * Only for actors who may edit it.
     *
     * @return array<string, mixed>
     */
    public function editable(Quiz $quiz): array
    {
        return [
            'id' => $quiz->id,
            'title' => $quiz->title,
            'description' => $this->rich->source($quiz->description, $quiz),
            'status' => $quiz->status,
            'timeLimit' => $quiz->time_limit,
            'passMark' => $quiz->pass_mark,
            'maxAttempts' => $quiz->max_attempts,
            'questionPick' => $quiz->question_pick,
            'shuffleQuestions' => $quiz->shuffle_questions,
            'shuffleOptions' => $quiz->shuffle_options,
            'partialCredit' => $quiz->partial_credit,
            'reveal' => $quiz->reveal,
            'leaderboard' => $quiz->leaderboard,
            'opensAt' => $quiz->opens_at?->toIso8601String(),
            'closesAt' => $quiz->closes_at?->toIso8601String(),
            'rewardPoints' => $quiz->reward_points,
            'rewardBadgeId' => $quiz->reward_badge_id,
            'questions' => $quiz->questions->map(fn (Question $q) => [
                'id' => $q->id,
                'type' => $q->type,
                'body' => $this->rich->source($q->body, $quiz),
                'imageUrl' => $q->image_url,
                'points' => $q->points,
                'explanation' => $this->rich->source($q->explanation, $quiz),
                'options' => array_map(fn ($o) => [
                    'id' => (string) $o['id'],
                    'text' => (string) ($o['text'] ?? ''),
                    'correct' => (bool) ($o['correct'] ?? false),
                ], $q->options ?: []),
            ])->values()->all(),
        ];
    }

    /**
     * An attempt in progress: the questions as dealt, the member's saved
     * answers and the server's clock. No answer key.
     *
     * @return array<string, mixed>
     */
    public function paper(Attempt $attempt): array
    {
        $quiz = $attempt->quiz;
        $questions = Question::query()->whereIn('id', $attempt->questionIds())->get()->keyBy('id');
        $this->rich->prime([$quiz], $questions->pluck('body'));
        $slots = [];

        foreach ($attempt->layout as $slot) {
            if ($q = $questions->get((int) $slot['q'])) {
                $slots[] = $this->dealt($q, $slot['o'], $quiz);
            }
        }

        return [
            'quizId' => $quiz->id,
            'finished' => false,
            'practice' => $attempt->practice,
            'startedAt' => $attempt->started_at->toIso8601String(),
            'deadlineAt' => $attempt->deadline_at?->toIso8601String(),
            'secondsLeft' => $attempt->secondsLeft(),
            'questions' => $slots,
            'answers' => (object) ($attempt->answers ?: []),
        ];
    }

    /**
     * One question of a paper: only ids and labels, never which is right.
     *
     * @param list<string> $dealt the option ids in the order this attempt was dealt them
     * @return array<string, mixed>
     */
    private function dealt(Question $q, array $dealt, Quiz $quiz): array
    {
        $texts = [];
        foreach ($q->options ?: [] as $o) {
            $texts[(string) $o['id']] = (string) ($o['text'] ?? '');
        }

        // The order dealt, then any option added since the attempt began;
        // none at all for a typed answer, whose "options" are the key.
        $order = $q->type === 'text' ? [] : array_values(array_unique(array_merge(
            array_values(array_filter($dealt, fn ($id) => isset($texts[$id]))),
            array_keys($texts)
        )));

        return [
            'id' => $q->id,
            'type' => $q->type,
            'bodyHtml' => $this->rich->html($q->body, $quiz),
            'imageUrl' => $q->image_url,
            'points' => $q->points,
            // True/false labels are translated in the browser.
            'options' => array_map(fn ($id) => ['id' => (string) $id, 'text' => $q->type === 'boolean' ? '' : $texts[$id]], $order),
        ];
    }

    /**
     * A marked attempt. How much of the marking shows follows the quiz's
     * reveal setting; the answer key only once the member can no longer
     * improve by trying again (passed, or out of attempts), unless the quiz
     * reveals it every time. Anyone who may edit the quiz sees it all on
     * other members' attempts; on their own runs they get exactly what a
     * member would, so a test run shows what the reveal setting does.
     *
     * @return array<string, mixed>
     */
    public function result(Attempt $attempt, User $actor): array
    {
        $quiz = $attempt->quiz;
        $mine = Attempt::query()->where('quiz_id', $quiz->id)->where('user_id', $attempt->user_id)->get(['id', 'submitted_at']);
        $used = $mine->count();
        $open = $mine->contains(fn (Attempt $a) => $a->submitted_at === null);
        $passedEver = (bool) Best::query()->where('quiz_id', $quiz->id)->where('user_id', $attempt->user_id)->value('passed');
        // A practice run never reaches quiz_bests, so its own pass counts.
        $done = $passedEver || ($attempt->practice && $attempt->passed) || ($quiz->max_attempts && $used >= $quiz->max_attempts && ! $open);

        $editor = (int) $attempt->user_id !== (int) $actor->id && Access::canEdit($actor, $quiz);
        $level = $editor ? 'answers_always' : $quiz->reveal;
        // 🚨 Never while the member has an attempt open: an earlier result
        // must not hand them the key to the paper in front of them.
        $key = ($editor || ! $open) && ($level === 'answers_always' || ($level === 'answers' && $done));

        return [
            'quizId' => $quiz->id,
            'finished' => true,
            'score' => $attempt->score,
            'maxScore' => $attempt->max_score,
            'percent' => $attempt->percent,
            'passed' => $attempt->passed,
            'passMark' => $quiz->pass_mark,
            'duration' => $attempt->duration,
            'expired' => $attempt->expired,
            'practice' => $attempt->practice,
            'submittedAt' => $attempt->submitted_at?->toIso8601String(),
            'attemptsUsed' => $used,
            'attemptsLeft' => $quiz->max_attempts ? max(0, $quiz->max_attempts - $used) : null,
            'answersShown' => $key,
            // Whether the key is still to come for this member, so the page can say so.
            'answersLater' => ! $key && $level === 'answers' && ! $done,
            'review' => $level === 'score' ? null : $this->review($attempt, $key),
        ];
    }

    /**
     * Each question of a marked attempt as it was dealt: what was chosen and
     * what it earned, and with $key the right answers and explanation.
     *
     * @return list<array<string, mixed>>
     */
    private function review(Attempt $attempt, bool $key): array
    {
        $quiz = $attempt->quiz;
        $questions = Question::query()->whereIn('id', $attempt->questionIds())->get()->keyBy('id');
        $this->rich->prime([$quiz], $questions->pluck('body')->merge($key ? $questions->pluck('explanation') : []));
        $review = [];

        foreach ($attempt->layout as $slot) {
            if ($q = $questions->get((int) $slot['q'])) {
                $review[] = $this->reviewed($attempt, $q, $slot['o'], $key);
            }
        }

        return $review;
    }

    /**
     * @param list<string> $order the option ids as dealt
     * @return array<string, mixed>
     */
    private function reviewed(Attempt $attempt, Question $q, array $order, bool $key): array
    {
        $quiz = $attempt->quiz;
        /** @var string|list<string>|null $given */
        $given = $attempt->answers[(string) $q->id] ?? null;
        $chosen = is_array($given) ? $given : (is_string($given) ? [$given] : []);
        $byId = [];
        foreach ($q->options ?: [] as $o) {
            $byId[(string) $o['id']] = $o;
        }

        $options = [];
        foreach ($q->type === 'text' ? [] : array_filter($order, fn ($id) => isset($byId[$id])) as $id) {
            $options[] = [
                'id' => $id,
                'text' => $q->type === 'boolean' ? '' : (string) ($byId[$id]['text'] ?? ''),
                'chosen' => in_array($id, $chosen, true),
            ] + ($key ? ['correct' => (bool) ($byId[$id]['correct'] ?? false)] : []);
        }

        return [
            'id' => $q->id,
            'type' => $q->type,
            'bodyHtml' => $this->rich->html($q->body, $quiz),
            'imageUrl' => $q->image_url,
            'points' => $q->points,
            'earned' => (float) ($attempt->marks[(string) $q->id] ?? round($q->points * Scorer::fraction($q, $given, $quiz->partial_credit), 2)),
            'options' => $options,
            'typed' => $q->type === 'text' && is_string($given) ? $given : null,
        ] + ($key ? [
            'accepted' => $q->type === 'text' ? array_map(fn ($o) => (string) ($o['text'] ?? ''), $q->options ?: []) : null,
            'explanationHtml' => $this->rich->html($q->explanation, $quiz),
        ] : []);
    }

    /** @return array<string, mixed> */
    public function user(User $user): array
    {
        return [
            'id' => $user->id,
            'username' => $user->username,
            'displayName' => $user->display_name,
            'avatarUrl' => $user->avatar_url,
            'slug' => $this->slugs->forResource(User::class)->toSlug($user),
        ];
    }
}
