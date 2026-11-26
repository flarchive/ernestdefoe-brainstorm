<?php

namespace ErnestDefoe\Brainstorm;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Flarum\Database\ScopeVisibilityTrait;
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
 * @property int|null $course_id
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
    use ScopeVisibilityTrait;

    public const REVEALS = ['score', 'mistakes', 'answers', 'answers_always'];

    protected $table = 'quizzes';

    /**
     * The request's actor's standing at this quiz and its reward badge, filled
     * for a whole page of quizzes at once by {@see Presenter::prime()}. Never stored.
     *
     * @var array{me: array<string, mixed>|null, badge: array<string, string>|null}|null
     */
    public ?array $viewer = null;

    /**
     * The settings and questions an API request sent, gathered by the quiz
     * resource's fields for {@see Saver::save()} to check and write. Never stored.
     *
     * @var array<string, mixed>
     */
    public array $input = [];

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
        'course_id' => 'integer',
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
     * The quizzes this actor may see: the same as `whereVisibleTo($actor)`,
     * under the name 1.1 gave it.
     *
     * @deprecated 1.2.1 Use whereVisibleTo(); kept for Buzz and Syllabus, remove once they have switched.
     *
     * @param Builder<Quiz> $query
     */
    public function scopeVisibleTo(Builder $query, User $actor): void
    {
        $query->whereVisibleTo($actor);
    }
}
