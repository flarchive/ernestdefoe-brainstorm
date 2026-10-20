<?php

namespace ErnestDefoe\Brainstorm;

use Carbon\Carbon;
use Flarum\Http\SlugManager;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;

/**
 * Everything Brainstorm sends to a browser is built here, field by field.
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
        private ConnectionInterface $db,
        private Rewards $rewards
    ) {
    }

    /**
     * Quizzes for a list, a card or a page, with the actor's own standing at
     * each. A fixed number of queries whatever the count: the actor's
     * attempts, bests and the reward badges are each loaded once for all.
     *
     * @param Collection<int, Quiz> $quizzes with `user` loaded
     * @return list<array<string, mixed>>
     */
    public function quizzes(Collection $quizzes, User $actor): array
    {
        $ids = $quizzes->pluck('id')->all();
        $used = $open = $bests = [];

        if ($ids && ! $actor->isGuest()) {
            foreach (Attempt::query()->whereIn('quiz_id', $ids)->where('user_id', $actor->id)
                ->get(['id', 'quiz_id', 'submitted_at']) as $a) {
                $used[$a->quiz_id] = ($used[$a->quiz_id] ?? 0) + 1;
                if ($a->submitted_at === null) {
                    $open[$a->quiz_id] = max($open[$a->quiz_id] ?? 0, $a->id);
                }
            }

            foreach ($this->db->table('quiz_bests')->whereIn('quiz_id', $ids)->where('user_id', $actor->id)->get() as $b) {
                $bests[$b->quiz_id] = $b;
            }
        }

        $badges = $this->badges($quizzes->pluck('reward_badge_id')->filter()->unique()->all());
        $this->rich->prime($quizzes, $quizzes->pluck('description'));

        return $quizzes->map(function (Quiz $quiz) use ($actor, $used, $open, $bests, $badges) {
            $best = $bests[$quiz->id] ?? null;
            $usedHere = $used[$quiz->id] ?? 0;

            return [
                'id' => $quiz->id,
                'slug' => $quiz->slug,
                'title' => $quiz->title,
                'descriptionHtml' => $this->rich->html($quiz->description, $quiz),
                'status' => $quiz->status,
                'timeLimit' => $quiz->time_limit,
                'passMark' => $quiz->pass_mark,
                'maxAttempts' => $quiz->max_attempts,
                'questionCount' => $quiz->question_pick ? min($quiz->question_pick, $quiz->question_count) : $quiz->question_count,
                'reveal' => $quiz->reveal,
                'leaderboard' => $quiz->leaderboard,
                'opensAt' => $quiz->opens_at?->toIso8601String(),
                'closesAt' => $quiz->closes_at?->toIso8601String(),
                'isOpen' => $quiz->isOpen(),
                'rewardPoints' => $quiz->reward_points && $this->rewards->pointsAvailable() ? $quiz->reward_points : 0,
                'rewardBadge' => $badges[$quiz->reward_badge_id] ?? null,
                'attemptCount' => $quiz->attempt_count,
                'passCount' => $quiz->pass_count,
                'publishedAt' => $quiz->published_at?->toIso8601String(),
                'author' => $quiz->user ? $this->user($quiz->user) : null,
                'canEdit' => Access::canEdit($actor, $quiz),
                'canTake' => Access::canTake($actor, $quiz),
                'me' => $actor->isGuest() ? null : [
                    'attemptsUsed' => $usedHere,
                    'attemptsLeft' => $quiz->max_attempts ? max(0, $quiz->max_attempts - $usedHere) : null,
                    'openAttemptId' => $open[$quiz->id] ?? null,
                    'best' => $best ? [
                        'attemptId' => (int) $best->attempt_id,
                        'percent' => (float) $best->percent,
                        'duration' => (int) $best->duration,
                        'passed' => (bool) $best->passed,
                    ] : null,
                ],
            ];
        })->values()->all();
    }

    /** @return array<string, mixed> */
    public function quiz(Quiz $quiz, User $actor): array
    {
        return $this->quizzes(new Collection([$quiz]), $actor)[0];
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
            $q = $questions->get((int) $slot['q']);
            if (! $q) {
                continue;
            }

            $texts = [];
            foreach ($q->options ?: [] as $o) {
                $texts[(string) $o['id']] = (string) ($o['text'] ?? '');
            }

            // The order dealt, then any option added since the attempt began;
            // none at all for a typed answer, whose "options" are the key.
            $order = $q->type === 'text' ? [] : array_values(array_unique(array_merge(
                array_values(array_filter($slot['o'], fn ($id) => isset($texts[$id]))),
                array_keys($texts)
            )));

            $slots[] = [
                'id' => $q->id,
                'type' => $q->type,
                'bodyHtml' => $this->rich->html($q->body, $quiz),
                'imageUrl' => $q->image_url,
                'points' => $q->points,
                // Only ids and labels. True/false labels are translated in the browser.
                'options' => array_map(fn ($id) => ['id' => (string) $id, 'text' => $q->type === 'boolean' ? '' : $texts[$id]], $order),
            ];
        }

        return [
            'id' => $attempt->id,
            'quizId' => $quiz->id,
            'finished' => false,
            'practice' => $attempt->practice,
            'startedAt' => $attempt->started_at->toIso8601String(),
            'deadlineAt' => $attempt->deadline_at?->toIso8601String(),
            // The player's clock may be wrong; the countdown runs from this.
            'secondsLeft' => $attempt->deadline_at ? max(0, $attempt->deadline_at->getTimestamp() - Carbon::now()->getTimestamp()) : null,
            'questions' => $slots,
            'answers' => (object) ($attempt->answers ?: []),
        ];
    }

    /**
     * A marked attempt. How much of the marking shows follows the quiz's
     * reveal setting; the answer key only once the member can no longer
     * improve by trying again (passed, or out of attempts), unless the quiz
     * reveals it every time. Anyone who may edit the quiz sees it all.
     *
     * @return array<string, mixed>
     */
    public function result(Attempt $attempt, User $actor): array
    {
        $quiz = $attempt->quiz;
        $mine = Attempt::query()->where('quiz_id', $quiz->id)->where('user_id', $attempt->user_id)->get(['id', 'submitted_at']);
        $used = $mine->count();
        $open = $mine->contains(fn (Attempt $a) => $a->submitted_at === null);
        $passedEver = (bool) $this->db->table('quiz_bests')->where('quiz_id', $quiz->id)->where('user_id', $attempt->user_id)->value('passed');
        $done = $passedEver || ($quiz->max_attempts && $used >= $quiz->max_attempts && ! $open);

        $editor = Access::canEdit($actor, $quiz);
        $level = $editor ? 'answers_always' : $quiz->reveal;
        $marks = $level !== 'score';
        // 🚨 Never while the member has an attempt open: an earlier result
        // must not hand them the key to the paper in front of them.
        $key = ($editor || ! $open) && ($level === 'answers_always' || ($level === 'answers' && $done));

        $review = null;
        if ($marks) {
            $questions = Question::query()->whereIn('id', $attempt->questionIds())->get()->keyBy('id');
            $this->rich->prime([$quiz], $questions->pluck('body')->merge($key ? $questions->pluck('explanation') : []));
            $review = [];

            foreach ($attempt->layout as $slot) {
                $q = $questions->get((int) $slot['q']);
                if (! $q) {
                    continue;
                }

                /** @var string|list<string>|null $given */
                $given = $attempt->answers[(string) $q->id] ?? null;
                $chosen = is_array($given) ? $given : (is_string($given) ? [$given] : []);
                $byId = [];
                foreach ($q->options ?: [] as $o) {
                    $byId[(string) $o['id']] = $o;
                }

                $options = [];
                foreach ($q->type === 'text' ? [] : $slot['o'] as $id) {
                    if (! isset($byId[$id])) {
                        continue;
                    }
                    $options[] = [
                        'id' => $id,
                        'text' => $q->type === 'boolean' ? '' : (string) ($byId[$id]['text'] ?? ''),
                        'chosen' => in_array($id, $chosen, true),
                    ] + ($key ? ['correct' => (bool) ($byId[$id]['correct'] ?? false)] : []);
                }

                $review[] = [
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
        }

        return [
            'id' => $attempt->id,
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
            'review' => $review,
        ];
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

    /**
     * @param list<int> $ids
     * @return array<int, array{name: string, icon: string, color: string, background: string}>
     */
    private function badges(array $ids): array
    {
        if (! $ids || ! $this->rewards->badgesAvailable()) {
            return [];
        }

        $out = [];
        foreach ($this->db->table('fof_badges')->whereIn('id', $ids)->where('is_visible', true)
            ->get(['id', 'name', 'icon', 'icon_color', 'background_color']) as $b) {
            $out[(int) $b->id] = [
                'name' => (string) $b->name,
                'icon' => (string) $b->icon,
                'color' => (string) $b->icon_color,
                'background' => (string) $b->background_color,
            ];
        }

        return $out;
    }
}
