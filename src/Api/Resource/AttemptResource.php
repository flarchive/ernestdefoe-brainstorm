<?php

namespace ErnestDefoe\Brainstorm\Api\Resource;

use Carbon\Carbon;
use ErnestDefoe\Brainstorm\Access;
use ErnestDefoe\Brainstorm\Attempt;
use ErnestDefoe\Brainstorm\Attempts;
use ErnestDefoe\Brainstorm\Presenter;
use ErnestDefoe\Brainstorm\Quiz;
use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Flarum\Foundation\ValidationException;
use Flarum\Locale\TranslatorInterface;
use Flarum\User\Exception\PermissionDeniedException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tobyz\JsonApiServer\Context as OriginalContext;

/**
 * Attempts at quizzes. POST starts one (or resumes the member's open one)
 * and returns its paper; GET shows the paper, or the result once marked;
 * PATCH saves the answers so far, and with `submit: true` hands it in.
 *
 * 🚨 Only the member taking an attempt may save or submit it. People who
 * may edit the quiz can look at a marked one. A paper never carries the
 * answer key; a result shows what the quiz's reveal setting allows.
 *
 * @extends AbstractDatabaseResource<Attempt>
 */
class AttemptResource extends AbstractDatabaseResource
{
    /** Attempts one member may start across all quizzes in ten minutes. */
    public const BURST = 30;

    /** What a paper, a result or a save receipt may carry; {@see Presenter} decides which. */
    private const SHOWN = [
        'quizId', 'finished', 'practice', 'startedAt', 'deadlineAt', 'secondsLeft', 'questions', 'answers',
        'score', 'maxScore', 'percent', 'passed', 'passMark', 'duration', 'expired', 'submittedAt',
        'attemptsUsed', 'attemptsLeft', 'answersShown', 'answersLater', 'review',
    ];

    public function __construct(private Attempts $attempts, private Presenter $presenter, private TranslatorInterface $translator)
    {
    }

    public function type(): string
    {
        return 'quiz-attempts';
    }

    public function model(): string
    {
        return Attempt::class;
    }

    public function scope(Builder $query, OriginalContext $context): void
    {
        $query->whereVisibleTo($context->getActor());
    }

    public function endpoints(): array
    {
        return [
            Endpoint\Show::make()
                ->authenticated()
                ->after(fn (Context $context, Attempt $attempt) => $this->shown($attempt, $context)),
            Endpoint\Create::make()
                ->authenticated()
                ->after(fn (Context $context, Attempt $attempt) => $this->present($attempt, $context)),
            Endpoint\Update::make()
                ->authenticated()
                ->can('update')
                ->after(fn (Context $context, Attempt $attempt) => $this->receipt($attempt, $context)),
        ];
    }

    public function fields(): array
    {
        $shown = array_map(fn (string $key) => Schema\Attribute::make($key)
            ->visible(fn (Attempt $attempt) => array_key_exists($key, $attempt->view ?? []))
            ->get(fn (Attempt $attempt) => $attempt->view[$key] ?? null), self::SHOWN);

        return [
            ...array_filter($shown, fn (Schema\Attribute $field) => ! in_array($field->name, ['quizId', 'answers'], true)),
            // The quiz to start an attempt at.
            Schema\Integer::make('quizId')
                ->writableOnCreate()
                ->requiredOnCreate()
                ->visible(fn (Attempt $attempt) => array_key_exists('quizId', $attempt->view ?? []))
                ->get(fn (Attempt $attempt) => $attempt->view['quizId'] ?? null)
                ->set(fn (Attempt $attempt, mixed $value) => $attempt->quiz_id = (int) $value),
            // The answers so far; checked against the paper by {@see Attempts}.
            Schema\Attribute::make('answers')
                ->writable(fn (Attempt $attempt) => $attempt->exists)
                ->nullable()
                ->visible(fn (Attempt $attempt) => array_key_exists('answers', $attempt->view ?? []))
                ->get(fn (Attempt $attempt) => $attempt->view['answers'] ?? null)
                ->set(fn (Attempt $attempt, mixed $value) => $attempt->input['answers'] = $value),
            // Hand it in with these answers.
            Schema\Boolean::make('submit')
                ->visible(false)
                ->writable(fn (Attempt $attempt) => $attempt->exists)
                ->set(fn (Attempt $attempt, mixed $value) => $attempt->input['submit'] = (bool) $value),
        ];
    }

    /**
     * Start an attempt at a quiz the member may take, or resume the one they
     * have open. Unlimited-attempt quizzes can't become a way to fill the table.
     *
     * @param Attempt $model
     */
    public function create(object $model, OriginalContext $context): object
    {
        $actor = $context->getActor();
        $quiz = Quiz::query()->whereVisibleTo($actor)->find($model->quiz_id);

        if (! $quiz) {
            throw new ModelNotFoundException();
        }
        if (! Access::canTake($actor, $quiz)) {
            throw new PermissionDeniedException();
        }

        if (Attempt::query()->where('user_id', $actor->id)->where('started_at', '>', Carbon::now()->subMinutes(10))->count() >= self::BURST
            && ! $this->attempts->open($quiz, $actor)) {
            throw new ValidationException(['attempt' => $this->translator->trans('ernestdefoe-brainstorm.api.slow_down')]);
        }

        return $this->attempts->start($quiz, $actor, $this->attempts->practises($actor, $quiz));
    }

    /**
     * Save the answers so far, or hand the attempt in. Nothing else about an
     * attempt is written by a request.
     *
     * @param Attempt $model
     */
    public function update(object $model, OriginalContext $context): object
    {
        $model->setRelation('user', $context->getActor());
        $answers = $model->input['answers'] ?? null;

        if (! empty($model->input['submit'])) {
            return $this->attempts->submit($model, $answers);
        }

        $this->attempts->save($model, $answers);

        return $model;
    }

    /**
     * A marked attempt shows its result; an open one its paper, to its member.
     * Its clock having run out, it is marked first.
     */
    private function shown(Attempt $attempt, Context $context): Attempt
    {
        if ($attempt->isOverdue()) {
            $this->attempts->submit($attempt);
        }

        return $this->present($attempt, $context);
    }

    /** An autosave only needs to know it landed and how long is left; a hand-in gets its result. */
    private function receipt(Attempt $attempt, Context $context): Attempt
    {
        if ($attempt->isFinished()) {
            return $this->present($attempt, $context);
        }

        $attempt->view = [
            'finished' => false,
            'secondsLeft' => $attempt->secondsLeft(),
        ];

        return $attempt;
    }

    private function present(Attempt $attempt, Context $context): Attempt
    {
        $actor = $context->getActor();
        $attempt->loadMissing('quiz');

        if ($attempt->isFinished()) {
            $attempt->view = $this->presenter->result($attempt, $actor);
        } elseif ($attempt->user_id === $actor->id) {
            $attempt->view = $this->presenter->paper($attempt);
        } else {
            // Someone else's paper in progress: only that it is not finished.
            $attempt->view = ['finished' => false];
        }

        return $attempt;
    }
}
