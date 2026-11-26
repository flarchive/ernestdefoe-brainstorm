<?php

namespace ErnestDefoe\Brainstorm\Api\Resource;

use Carbon\Carbon;
use ErnestDefoe\Brainstorm\Access;
use ErnestDefoe\Brainstorm\Attempts;
use ErnestDefoe\Brainstorm\Integration\Courses\CourseGate;
use ErnestDefoe\Brainstorm\Leaderboards;
use ErnestDefoe\Brainstorm\Presenter;
use ErnestDefoe\Brainstorm\Quiz;
use ErnestDefoe\Brainstorm\Results;
use ErnestDefoe\Brainstorm\Rewards;
use ErnestDefoe\Brainstorm\Saver;
use ErnestDefoe\Brainstorm\Search\Filter\IdsFilter;
use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Flarum\Api\Sort\SortColumn;
use Flarum\Foundation\ValidationException;
use Flarum\Locale\TranslatorInterface;
use Flarum\User\Exception\PermissionDeniedException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\EmptyResponse;
use Tobyz\JsonApiServer\Context as OriginalContext;

/**
 * Quizzes: listed, shown, written and deleted, plus their leaderboards and
 * results.
 *
 * 🚨 The answer key is only in `edit`, the editor's copy, which is sent to
 * people who may edit the quiz and only when one quiz is shown or saved.
 *
 * @extends AbstractDatabaseResource<Quiz>
 */
class QuizResource extends AbstractDatabaseResource
{
    /** New quizzes one member may start in an hour. */
    public const HOURLY = 20;

    /** Settings the editor sends, written by {@see Saver} beside the read ones below. */
    private const INPUTS = ['description', 'questionPick', 'shuffleQuestions', 'shuffleOptions', 'partialCredit', 'rewardBadgeId', 'courseId', 'questions'];

    public function __construct(
        private Saver $saver,
        private Presenter $presenter,
        private Attempts $attempts,
        private Leaderboards $leaderboards,
        private Results $results,
        private Rewards $rewards,
        private TranslatorInterface $translator,
        private CourseGate $courses
    ) {
    }

    public function type(): string
    {
        return 'quizzes';
    }

    public function model(): string
    {
        return Quiz::class;
    }

    public function scope(Builder $query, OriginalContext $context): void
    {
        $query->whereVisibleTo($context->getActor());
    }

    public function endpoints(): array
    {
        return [
            // Static paths before Show's /{id}: FastRoute refuses a static
            // route that an earlier variable one already matches.
            Endpoint\Endpoint::make('brainstorm.leaderboard')
                ->route('GET', '/leaderboard')
                ->action(fn (Context $context) => ['meta' => $this->leaderboards->overall($context->getActor(), $this->limit($context))]),
            Endpoint\Endpoint::make('brainstorm.editor')
                ->route('GET', '/editor')
                ->authenticated()
                ->action(fn (Context $context) => ['meta' => $this->editorOptions($context)]),
            Endpoint\Index::make()
                ->paginate(20, IdsFilter::MAX)
                ->defaultSort('-publishedAt')
                ->defaultInclude(['user'])
                ->after(fn (Context $context, iterable $quizzes) => $this->primeAll($quizzes, $context)),
            Endpoint\Show::make()
                ->defaultInclude(['user'])
                ->after(fn (Context $context, Quiz $quiz) => $this->shown($quiz, $context)),
            Endpoint\Create::make()
                ->authenticated()
                ->defaultInclude(['user'])
                ->after(fn (Context $context, Quiz $quiz) => $this->prime($quiz, $context)),
            Endpoint\Update::make()
                ->authenticated()
                ->can('update')
                ->defaultInclude(['user'])
                ->after(fn (Context $context, Quiz $quiz) => $this->prime($quiz, $context)),
            Endpoint\Delete::make()
                ->authenticated()
                ->can('delete'),
            Endpoint\Endpoint::make('brainstorm.quiz.leaderboard')
                ->route('GET', '/{id}/leaderboard')
                ->action(fn (Context $context) => ['meta' => $this->leaderboards->quiz($context->model, $context->getActor(), $this->limit($context))]),
            Endpoint\Endpoint::make('brainstorm.results')
                ->route('GET', '/{id}/results')
                ->authenticated()
                ->can('update')
                ->action(fn (Context $context) => ['meta' => $this->results->page($context->model, max(0, $this->page($context, 'offset', 0)))]),
            Endpoint\Endpoint::make('brainstorm.results.reset')
                ->route('DELETE', '/{id}/results/{user}')
                ->authenticated()
                ->can('update')
                ->action(fn (Context $context) => $this->attempts->reset($context->model, (int) Arr::get($context->request->getAttribute('routeParameters'), 'user')))
                ->response(fn () => new EmptyResponse(204)),
        ];
    }

    public function fields(): array
    {
        return [...$this->shownFields(), ...$this->viewerFields(), ...$this->inputFields()];
    }

    public function sorts(): array
    {
        return [
            SortColumn::make('publishedAt'),
            SortColumn::make('attemptCount'),
        ];
    }

    /** @return list<Schema\Attribute|Schema\Relationship\ToOne> what the quiz is, read from its columns; the settings among them are writable */
    private function shownFields(): array
    {
        $writable = fn (Schema\Attribute $field) => $field->writable()->nullable()
            ->set(fn (Quiz $quiz, mixed $value) => $quiz->input[$field->name] = $value);

        return [
            Schema\Str::make('slug'),
            $writable(Schema\Str::make('title')),
            $writable(Schema\Str::make('status')),
            $writable(Schema\Integer::make('timeLimit')),
            $writable(Schema\Integer::make('passMark')),
            $writable(Schema\Integer::make('maxAttempts')),
            $writable(Schema\Str::make('reveal')),
            $writable(Schema\Boolean::make('leaderboard')),
            $writable(Schema\Attribute::make('opensAt')->get(fn (Quiz $quiz) => $quiz->opens_at?->toIso8601String())),
            $writable(Schema\Attribute::make('closesAt')->get(fn (Quiz $quiz) => $quiz->closes_at?->toIso8601String())),
            $writable(Schema\Integer::make('rewardPoints')->get(fn (Quiz $quiz) => $quiz->reward_points && $this->rewards->pointsAvailable() ? $quiz->reward_points : 0)),
            Schema\Integer::make('questionCount')
                ->get(fn (Quiz $quiz) => $quiz->question_pick ? min($quiz->question_pick, $quiz->question_count) : $quiz->question_count),
            Schema\Integer::make('attemptCount'),
            Schema\Integer::make('passCount'),
            Schema\DateTime::make('publishedAt'),
            Schema\Boolean::make('isOpen')->get(fn (Quiz $quiz) => $quiz->isOpen()),
            Schema\Boolean::make('courseOnly')->get(fn (Quiz $quiz) => $quiz->course_id !== null),
            Schema\Str::make('descriptionHtml')->get(fn (Quiz $quiz) => $this->presenter->descriptionHtml($quiz)),
            Schema\Relationship\ToOne::make('user')->includable(),
        ];
    }

    /** @return list<Schema\Attribute> what the request's actor may do and has done here; filled in by {@see Presenter::prime()} */
    private function viewerFields(): array
    {
        return [
            Schema\Boolean::make('canEdit')->get(fn (Quiz $quiz, Context $context) => Access::canEdit($context->getActor(), $quiz)),
            Schema\Boolean::make('canTake')->get(fn (Quiz $quiz, Context $context) => Access::canTake($context->getActor(), $quiz)),
            Schema\Arr::make('rewardBadge')->nullable()->get(fn (Quiz $quiz) => $quiz->viewer['badge'] ?? null),
            Schema\Arr::make('me')->nullable()
                ->visible(fn (Quiz $quiz, Context $context) => ! $context->getActor()->isGuest())
                ->get(fn (Quiz $quiz) => $quiz->viewer['me'] ?? null),
            // The editor's copy, answer key included: one quiz at a time, to its editors.
            Schema\Arr::make('edit')
                ->visible(fn (Quiz $quiz, Context $context) => $this->editing($context) && Access::canEdit($context->getActor(), $quiz))
                ->get(fn (Quiz $quiz) => $this->presenter->editable($quiz)),
        ];
    }

    /** @return list<Schema\Attribute> settings only the editor sends; read back through `edit` */
    private function inputFields(): array
    {
        return array_map(fn (string $name) => Schema\Attribute::make($name)
            ->visible(false)
            ->writable()
            ->nullable()
            ->set(fn (Quiz $quiz, mixed $value) => $quiz->input[$name] = $value), self::INPUTS);
    }

    /**
     * A member may write quizzes, and only so many an hour: a manager has no limit.
     *
     * @param Quiz $model
     */
    public function creating(object $model, OriginalContext $context): ?object
    {
        $actor = $context->getActor();

        if (! Access::canCreate($actor)) {
            throw new PermissionDeniedException();
        }

        if (! $actor->hasPermission('brainstorm.manage')
            && Quiz::query()->where('user_id', $actor->id)->where('created_at', '>', Carbon::now()->subHour())->count() >= self::HOURLY) {
            throw new ValidationException(['title' => $this->translator->trans('ernestdefoe-brainstorm.api.slow_down')]);
        }

        return $model;
    }

    /**
     * The quiz and its questions are checked and written together, in one
     * transaction, by the Saver: its rules span fields (dates, a published
     * quiz needs questions, a rewarded quiz's marking is locked).
     *
     * @param Quiz $model
     */
    public function create(object $model, OriginalContext $context): object
    {
        return $this->saver->save($model, $model->input, $context->getActor());
    }

    /** @param Quiz $model */
    public function update(object $model, OriginalContext $context): object
    {
        return $this->saver->save($model, $model->input, $context->getActor());
    }

    /** A timed attempt left running is marked before the quiz is shown. */
    private function shown(Quiz $quiz, Context $context): Quiz
    {
        if (! $context->getActor()->isGuest()) {
            $this->attempts->expireOverdue($quiz, $context->getActor());
        }

        return $this->prime($quiz, $context);
    }

    /**
     * @param iterable<Quiz> $quizzes
     * @return iterable<Quiz>
     */
    private function primeAll(iterable $quizzes, Context $context): iterable
    {
        $this->presenter->prime($quizzes, $context->getActor());

        return $quizzes;
    }

    private function prime(Quiz $quiz, Context $context): Quiz
    {
        $this->presenter->prime([$quiz], $context->getActor());

        return $quiz;
    }

    /** Only one quiz at a time carries the editor's copy: when shown with ?withEdit=1, or just saved. */
    private function editing(Context $context): bool
    {
        return $context->endpoint instanceof Endpoint\Create
            || $context->endpoint instanceof Endpoint\Update
            || ($context->endpoint instanceof Endpoint\Show && $context->queryParam('withEdit'));
    }

    /** @return array<string, mixed> */
    private function editorOptions(Context $context): array
    {
        if (! Access::canCreate($context->getActor())) {
            throw new PermissionDeniedException();
        }

        // The courses a quiz may be limited to; null while Syllabus isn't enabled.
        return $this->rewards->editorOptions($context->getActor())
            + ['courses' => $this->courses->available() ? $this->courses->attachable($context->getActor()) : null];
    }

    private function limit(Context $context): int
    {
        return max(1, min(Leaderboards::SIZE, $this->page($context, 'limit', Leaderboards::SIZE)));
    }

    /** page[limit] / page[offset], the JSON:API way to ask for part of a list. */
    private function page(Context $context, string $key, int $default): int
    {
        $page = $context->queryParam('page');

        return is_array($page) && isset($page[$key]) && is_numeric($page[$key]) ? (int) $page[$key] : $default;
    }
}
