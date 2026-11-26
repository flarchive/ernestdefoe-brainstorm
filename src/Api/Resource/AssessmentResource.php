<?php

namespace ErnestDefoe\Brainstorm\Api\Resource;

use ErnestDefoe\Brainstorm\Access;
use ErnestDefoe\Brainstorm\Assessment\Assessment;
use ErnestDefoe\Brainstorm\Assessment\Item;
use ErnestDefoe\Brainstorm\Assessment\Progress;
use ErnestDefoe\Brainstorm\Assessment\Saver;
use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Flarum\Api\Sort\SortColumn;
use Flarum\User\Exception\PermissionDeniedException;
use Illuminate\Database\Eloquent\Builder;
use Tobyz\JsonApiServer\Context as OriginalContext;

/**
 * Assessments: groups of quizzes with one overall result. Each viewer gets
 * their own progress (`me`) and the quizzes as they may see them (`quizzes`:
 * one they can't see is only "locked"). The editor's copy (`items`) goes to
 * people who may edit it, one assessment at a time.
 *
 * @extends AbstractDatabaseResource<Assessment>
 */
class AssessmentResource extends AbstractDatabaseResource
{
    public function __construct(private Saver $saver, private Progress $progress)
    {
    }

    public function type(): string
    {
        return 'quiz-assessments';
    }

    public function model(): string
    {
        return Assessment::class;
    }

    public function scope(Builder $query, OriginalContext $context): void
    {
        $query->whereVisibleTo($context->getActor());
    }

    public function endpoints(): array
    {
        $one = function (Context $context, Assessment $assessment): Assessment {
            $this->progress->prime([$assessment], $context->getActor());

            return $assessment;
        };

        return [
            Endpoint\Index::make()
                ->paginate(20, 50)
                ->defaultSort('-publishedAt')
                ->defaultInclude(['user'])
                ->after(fn (Context $context, iterable $assessments) => $this->primeAll($assessments, $context)),
            Endpoint\Show::make()->defaultInclude(['user'])->after($one),
            Endpoint\Create::make()->authenticated()->defaultInclude(['user'])->after($one),
            Endpoint\Update::make()->authenticated()->can('update')->defaultInclude(['user'])->after($one),
            Endpoint\Delete::make()->authenticated()->can('delete'),
        ];
    }

    public function fields(): array
    {
        $writable = fn (Schema\Attribute $field) => $field->writable()->nullable()
            ->set(fn (Assessment $assessment, mixed $value) => $assessment->input[$field->name] = $value);

        return [
            $writable(Schema\Str::make('title')),
            $writable(Schema\Str::make('description')),
            $writable(Schema\Str::make('status')),
            $writable(Schema\Integer::make('passMark')),
            $writable(Schema\Str::make('weighting')),
            Schema\Str::make('slug'),
            Schema\DateTime::make('publishedAt'),
            Schema\Boolean::make('canEdit')->get(fn (Assessment $a, Context $context) => Access::canEditAssessment($context->getActor(), $a)),
            Schema\Arr::make('quizzes')->get(fn (Assessment $a) => $a->viewer['quizzes'] ?? []),
            Schema\Arr::make('me')->nullable()
                ->visible(fn (Assessment $a, Context $context) => ! $context->getActor()->isGuest())
                ->get(fn (Assessment $a) => $a->viewer['me'] ?? null),
            // The editor's copy: which quizzes, their weights and whether each is required.
            $writable(Schema\Arr::make('items'))
                ->visible(fn (Assessment $a, Context $context) => $this->editing($context) && Access::canEditAssessment($context->getActor(), $a))
                ->get(fn (Assessment $a) => $a->items->map(fn (Item $i) => ['quizId' => $i->quiz_id, 'weight' => $i->weight, 'required' => $i->required])->values()->all()),
            Schema\Relationship\ToOne::make('user')->includable(),
        ];
    }

    public function sorts(): array
    {
        return [SortColumn::make('publishedAt'), SortColumn::make('createdAt')];
    }

    /** @param Assessment $model */
    public function creating(object $model, OriginalContext $context): ?object
    {
        if (! Access::canCreate($context->getActor())) {
            throw new PermissionDeniedException();
        }

        return $model;
    }

    /** @param Assessment $model */
    public function create(object $model, OriginalContext $context): object
    {
        return $this->saver->save($model, $model->input, $context->getActor());
    }

    /** @param Assessment $model */
    public function update(object $model, OriginalContext $context): object
    {
        return $this->saver->save($model, $model->input, $context->getActor());
    }

    /**
     * The page as it came, so an empty one is still handed back (core refuses an empty after-hook result).
     *
     * @param iterable<Assessment> $assessments
     * @return iterable<Assessment>
     */
    private function primeAll(iterable $assessments, Context $context): iterable
    {
        $this->progress->prime($assessments, $context->getActor());

        return $assessments;
    }

    /** The editor's copy goes with one assessment at a time: shown, or just saved. */
    private function editing(Context $context): bool
    {
        return $context->endpoint instanceof Endpoint\Show
            || $context->endpoint instanceof Endpoint\Create
            || $context->endpoint instanceof Endpoint\Update;
    }
}
