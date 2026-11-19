<?php

namespace ErnestDefoe\Brainstorm;

use Carbon\Carbon;
use ErnestDefoe\Brainstorm\Event\AttemptFinished;
use Flarum\Foundation\ValidationException;
use Flarum\Locale\TranslatorInterface;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;

/**
 * Starting, saving and marking attempts.
 *
 * 🚨 The server is the only judge. The answer key never leaves it, the
 * deadline is its own clock's, the attempt limit is counted under a lock, and
 * a submission is marked exactly once however many copies of it arrive.
 * A member has at most one attempt open at a quiz (starting is locked), so
 * their best row there is only ever written by one submission at a time.
 */
class Attempts
{
    /** The longest typed answer kept. */
    public const TEXT_MAX = 300;

    public function __construct(
        private ConnectionInterface $db,
        private Dispatcher $events,
        private TranslatorInterface $translator,
        private LoggerInterface $log,
        private Cache $cache,
        private SettingsRepositoryInterface $settings
    ) {
    }

    /** {@see Access::practises()} with this forum's setting. */
    public function practises(User $user, Quiz $quiz): bool
    {
        return Access::practises($user, $quiz, (bool) $this->settings->get('ernestdefoe-brainstorm.editors_count'));
    }

    /** Attempts the member has used at this quiz, the one in progress included. */
    public function used(Quiz $quiz, User $user): int
    {
        return Attempt::query()->where('quiz_id', $quiz->id)->where('user_id', $user->id)->count();
    }

    public function open(Quiz $quiz, User $user): ?Attempt
    {
        return Attempt::query()->where('quiz_id', $quiz->id)->where('user_id', $user->id)
            ->whereNull('submitted_at')->latest('id')->first();
    }

    /**
     * Resume the member's open attempt, or deal a new one if they have
     * attempts left. Called only once {@see Access::canTake()} has said yes.
     * A practice attempt (someone who can see the key) has no limit.
     */
    public function start(Quiz $quiz, User $user, bool $practice = false): Attempt
    {
        $this->expireOverdue($quiz, $user);

        $attempt = $this->locked($quiz, $user, fn () => $this->db->transaction(function () use ($quiz, $user, $practice) {
            if ($open = $this->open($quiz, $user)) {
                return $open;
            }

            if (! $practice && $quiz->max_attempts && $this->used($quiz, $user) >= $quiz->max_attempts) {
                throw new ValidationException(['attempt' => $this->translator->trans('ernestdefoe-brainstorm.api.no_attempts_left')]);
            }

            $layout = Dealer::deal($quiz, $quiz->questions()->get());

            if (! $layout) {
                throw new ValidationException(['attempt' => $this->translator->trans('ernestdefoe-brainstorm.api.no_questions')]);
            }

            $now = Carbon::now();
            $attempt = new Attempt();
            $attempt->quiz_id = $quiz->id;
            $attempt->user_id = $user->id;
            $attempt->started_at = $now;
            // The time limit, or the quiz closing, whichever comes first: an
            // attempt opened just before closing can't be handed in after it.
            $deadline = $quiz->time_limit ? $now->copy()->addSeconds($quiz->time_limit) : null;
            if ($quiz->closes_at && (! $deadline || $quiz->closes_at->lt($deadline))) {
                $deadline = $quiz->closes_at->copy();
            }
            $attempt->deadline_at = $deadline;
            $attempt->layout = $layout;
            $attempt->answers = [];
            $attempt->practice = $practice;
            $attempt->save();

            return $attempt;
        }));

        $attempt->setRelation('quiz', $quiz);

        return $attempt;
    }

    /** Keep the answers so far (autosave). Ignored once the clock has run out. */
    public function save(Attempt $attempt, mixed $answers): void
    {
        if ($attempt->isFinished() || $attempt->isOverdue()) {
            return;
        }

        $attempt->answers = $this->clean($attempt, $answers, $this->questions($attempt));

        // Only onto an unsubmitted row: an autosave that arrives just after
        // the attempt was handed in must not replace the answers it was marked on.
        Attempt::query()->whereKey($attempt->id)->whereNull('submitted_at')
            ->update(['answers' => json_encode($attempt->answers ?: new \stdClass())]);
    }

    /**
     * Mark the attempt, in place. Answers sent after the deadline (and its
     * grace) are not taken: an overdue attempt is marked on what was saved in time.
     */
    public function submit(Attempt $attempt, mixed $answers = null): Attempt
    {
        if ($attempt->isFinished()) {
            return $attempt;
        }

        $quiz = $attempt->quiz;
        $user = $attempt->relationLoaded('user') ? $attempt->user : User::query()->findOrFail($attempt->user_id);
        $questions = $this->questions($attempt);
        $overdue = $attempt->isOverdue();
        // Someone who could see the key by now (made an editor mid-attempt)
        // is practising, whatever they were when they started.
        $practice = $attempt->practice || $this->practises($user, $quiz);

        if (! $overdue && $answers !== null) {
            $attempt->answers = $this->clean($attempt, $answers, $questions);
        }

        $marked = $this->mark($attempt, $quiz, $questions, $overdue) + ['practice' => $practice];
        $firstPass = $this->db->transaction(function () use ($attempt, $quiz, $marked) {
            // Marked once: the update only lands on an unsubmitted row, so a
            // double click or a retried request can't mark it twice.
            $won = Attempt::query()->whereKey($attempt->id)->whereNull('submitted_at')->update([
                'answers' => json_encode($attempt->answers ?: new \stdClass()),
                'marks' => json_encode($marked['marks'] ?: new \stdClass()),
            ] + array_diff_key($marked, ['marks' => true]));

            return $won && ! $marked['practice'] ? $this->recordBest($attempt, $quiz, $marked) : null;
        });

        $attempt->refresh();
        $attempt->setRelation('quiz', $quiz);

        if ($firstPass !== null) {
            $quiz->increment('attempt_count');
            if ($firstPass) {
                $quiz->increment('pass_count');
            }

            $this->events->dispatch(new AttemptFinished($attempt, $user, $firstPass));
        }

        return $attempt;
    }

    /**
     * Each question's mark (kept with the attempt: the review shows what was
     * marked, even if the quiz is edited afterwards), the totals, and when
     * the attempt counts as finished.
     *
     * @param Collection<int, Question> $questions
     * @return array{submitted_at: Carbon, score: float, max_score: float, percent: float, passed: bool, duration: int, expired: bool, marks: array<int|string, float>}
     */
    private function mark(Attempt $attempt, Quiz $quiz, Collection $questions, bool $overdue): array
    {
        $score = 0.0;
        $max = 0.0;
        $marks = [];
        foreach ($attempt->questionIds() as $id) {
            if ($question = $questions->get($id)) {
                $max += $question->points;
                $marks[(string) $id] = round($question->points * Scorer::fraction($question, $attempt->answers[(string) $id] ?? null, $quiz->partial_credit), 2);
                $score += $marks[(string) $id];
            }
        }

        $now = Carbon::now();
        $finishedAt = $overdue || ($attempt->deadline_at && $attempt->deadline_at->lt($now)) ? $attempt->deadline_at->copy() : $now;
        $duration = max(0, $finishedAt->getTimestamp() - $attempt->started_at->getTimestamp());
        $score = round($score, 2);

        return [
            'submitted_at' => $finishedAt,
            'score' => $score,
            'max_score' => $max,
            'percent' => $max > 0 ? floor($score / $max * 10000) / 100 : 0.0,
            // Compared unrounded, so 69.995% never passes a 70% mark.
            'passed' => $max > 0 && $score * 100 >= $quiz->pass_mark * $max - 1e-9,
            'duration' => $quiz->time_limit ? min($duration, $quiz->time_limit) : $duration,
            'expired' => $overdue,
            'marks' => $marks,
        ];
    }

    /**
     * Mark this member's attempts at a quiz whose clock ran out while they
     * were away, before they see the quiz or start again. Cheap when there
     * are none: one select on the (quiz, user) index.
     */
    public function expireOverdue(Quiz $quiz, User $user): void
    {
        if (! $quiz->time_limit && ! $quiz->closes_at) {
            return;
        }

        $this->expire(Attempt::query()->where('quiz_id', $quiz->id)->where('user_id', $user->id), $quiz);
    }

    /**
     * Mark up to $max overdue attempts across every quiz (the scheduled
     * sweep). Returns how many were marked.
     */
    public function expireAll(int $max): int
    {
        return $this->expire(Attempt::query()->with(['quiz', 'user'])->limit($max), null);
    }

    /**
     * Mark the open attempts this query finds whose deadline (and grace) is
     * past. One that fails is logged and skipped, so one bad attempt can't
     * hold the rest up.
     *
     * @param Builder<Attempt> $query
     */
    private function expire(Builder $query, ?Quiz $quiz): int
    {
        $marked = 0;
        $overdue = $query->whereNull('submitted_at')
            ->where('deadline_at', '<', Carbon::now()->subSeconds(Attempt::GRACE))
            ->orderBy('id')
            ->get();

        foreach ($overdue as $attempt) {
            if ($quiz) {
                $attempt->setRelation('quiz', $quiz);
            }

            try {
                $this->submit($attempt);
                $marked++;
            } catch (\Throwable $e) {
                $this->log->error('[brainstorm] could not mark overdue attempt '.$attempt->id.': '.$e->getMessage());
            }
        }

        return $marked;
    }

    /** Forget a member's attempts at a quiz (a manager's reset). Rewards already paid stay paid. */
    public function reset(Quiz $quiz, int $userId): void
    {
        $this->db->transaction(function () use ($quiz, $userId) {
            Attempt::query()->where('quiz_id', $quiz->id)->where('user_id', $userId)->delete();
            Best::query()->where('quiz_id', $quiz->id)->where('user_id', $userId)->delete();

            $quiz->attempt_count = Attempt::query()->where('quiz_id', $quiz->id)->whereNotNull('submitted_at')->where('practice', false)->count();
            $quiz->pass_count = Best::query()->where('quiz_id', $quiz->id)->where('passed', true)->count();
            $quiz->save();
        });
    }

    /**
     * Keep the member's best attempt (higher score, then faster) and count
     * the attempt. Returns whether this was their first pass.
     *
     * @param array{submitted_at: Carbon, score: float, percent: float, passed: bool, duration: int} $marked
     */
    private function recordBest(Attempt $attempt, Quiz $quiz, array $marked): bool
    {
        $key = ['quiz_id' => $quiz->id, 'user_id' => $attempt->user_id];
        $best = Best::query()->where($key)->first();
        $passed = $marked['passed'];
        $at = $marked['submitted_at'];

        $row = [
            'attempt_id' => $attempt->id,
            'score' => $marked['score'],
            'percent' => $marked['percent'],
            'duration' => $marked['duration'],
            'achieved_at' => $at,
        ];

        if (! $best) {
            Best::query()->insert($key + $row + [
                'passed' => $passed,
                'attempts' => 1,
                'first_passed_at' => $passed ? $at : null,
            ]);

            return $passed;
        }

        // Once the member has been shown the answer key — every result shows
        // it on an `answers_always` quiz, and an `answers` quiz shows it once
        // passed — a retake can't climb the leaderboard with it.
        $keySeen = $quiz->reveal === 'answers_always' || ($quiz->reveal === 'answers' && $best->passed);
        $better = ! $keySeen && ($row['percent'] > $best->percent
            || ($row['percent'] == $best->percent && $row['duration'] < $best->duration));

        Best::query()->where($key)->update(($better ? $row : []) + [
            'attempts' => $best->attempts + 1,
            // "Has passed", never taken back by a weaker later attempt.
            'passed' => $passed || $best->passed,
            'first_passed_at' => $best->first_passed_at ?? ($passed ? $at : null),
        ]);

        return $passed && $best->first_passed_at === null;
    }

    /**
     * Run a member's start of an attempt alone, so two "start" clicks can't
     * both slip under the attempt limit or open two attempts. Never waits:
     * a second click while the first is being dealt is told to try again.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    private function locked(Quiz $quiz, User $user, callable $work): mixed
    {
        // Every cache store Flarum ships (file, array, redis) can lock.
        $store = $this->cache->getStore();
        if (! $store instanceof LockProvider) {
            return $work();
        }

        $lock = $store->lock("ernestdefoe-brainstorm.start.$quiz->id.$user->id", 10);
        if (! $lock->get()) {
            throw new ValidationException(['attempt' => $this->translator->trans('ernestdefoe-brainstorm.api.busy')]);
        }

        try {
            return $work();
        } finally {
            $lock->release();
        }
    }

    /** @return Collection<int, Question> this attempt's questions that still exist, by id */
    private function questions(Attempt $attempt): Collection
    {
        return Question::query()->whereIn('id', $attempt->questionIds())->get()->keyBy('id');
    }

    /**
     * Only answers to this attempt's questions, in the shape each type takes,
     * naming only options the question has.
     *
     * Keyed by question id (PHP turns the numeric keys into ints; JSON keeps
     * them an object, as no question has id 0).
     *
     * @param Collection<int, Question> $questions
     * @return array<int|string, string|list<string>>
     */
    private function clean(Attempt $attempt, mixed $answers, Collection $questions): array
    {
        if (! is_array($answers)) {
            return [];
        }

        $clean = [];
        foreach ($attempt->layout as $slot) {
            $id = (string) $slot['q'];
            $question = $questions->get((int) $id);
            $given = $answers[$id] ?? null;

            if (! $question || $given === null) {
                continue;
            }

            $valid = array_map(fn ($o) => (string) $o['id'], $question->options ?: []);

            if ($question->type === 'text') {
                if (is_string($given) && trim($given) !== '') {
                    $clean[$id] = mb_substr(trim($given), 0, self::TEXT_MAX);
                }
            } elseif ($question->type === 'multiple') {
                if (is_array($given)) {
                    $picked = array_values(array_intersect($valid, array_filter($given, 'is_string')));
                    if ($picked) {
                        $clean[$id] = $picked;
                    }
                }
            } elseif (is_string($given) && in_array($given, $valid, true)) {
                $clean[$id] = $given;
            }
        }

        return $clean;
    }
}
