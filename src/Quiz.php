<?php

namespace ErnestDefoe\Brainstorm;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int|null $user_id
 * @property string $title
 * @property string $slug
 * @property string|null $description
 * @property string $status
 * @property int|null $time_limit
 * @property int $pass_mark
 * @property int|null $max_attempts
 * @property int|null $question_pick
 * @property bool $shuffle_questions
 * @property bool $shuffle_options
 * @property bool $partial_credit
 * @property string $reveal
 * @property bool $leaderboard
 * @property Carbon|null $opens_at
 * @property Carbon|null $closes_at
 * @property int $reward_points
 * @property int|null $reward_badge_id
 * @property int $question_count
 * @property int $attempt_count
 * @property int $pass_count
 * @property Carbon|null $published_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property User|null $user
 * @property \Illuminate\Database\Eloquent\Collection<int, Question> $questions
 */
class Quiz extends AbstractModel
{
    public const REVEALS = ['score', 'mistakes', 'answers', 'answers_always'];

    protected $table = 'quizzes';

    public $timestamps = true;

    protected $casts = [
        'user_id' => 'integer',
        'time_limit' => 'integer',
        'pass_mark' => 'integer',
        'max_attempts' => 'integer',
        'question_pick' => 'integer',
        'shuffle_questions' => 'boolean',
        'shuffle_options' => 'boolean',
        'partial_credit' => 'boolean',
        'leaderboard' => 'boolean',
        'reward_points' => 'integer',
        'reward_badge_id' => 'integer',
        'question_count' => 'integer',
        'attempt_count' => 'integer',
        'pass_count' => 'integer',
        'opens_at' => 'datetime',
        'closes_at' => 'datetime',
        'published_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Question, $this> */
    public function questions(): HasMany
    {
        return $this->hasMany(Question::class)->orderBy('position')->orderBy('id');
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }

    /** Taking attempts right now: published and inside its open window. */
    public function isOpen(): bool
    {
        $now = Carbon::now();

        return $this->isPublished()
            && $this->question_count > 0
            && (! $this->opens_at || $this->opens_at->lte($now))
            && (! $this->closes_at || $this->closes_at->gt($now));
    }

    /**
     * Published quizzes, plus the drafts this actor may edit.
     *
     * @param Builder<Quiz> $query
     */
    public function scopeVisibleTo(Builder $query, User $actor): void
    {
        if (! $actor->hasPermission('brainstorm.view')) {
            $query->whereRaw('1 = 0');

            return;
        }

        if ($actor->hasPermission('brainstorm.manage')) {
            return;
        }

        $query->where(function (Builder $q) use ($actor) {
            $q->where('status', 'published');

            if (! $actor->isGuest() && $actor->hasPermission('brainstorm.create')) {
                $q->orWhere('user_id', $actor->id);
            }
        });
    }
}
