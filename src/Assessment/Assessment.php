<?php

namespace ErnestDefoe\Brainstorm\Assessment;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Flarum\Database\ScopeVisibilityTrait;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A group of existing quizzes with one overall result.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $title
 * @property string $slug
 * @property string|null $description
 * @property string $status
 * @property int $pass_mark
 * @property string $weighting
 * @property Carbon|null $published_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property User|null $user
 * @property \Illuminate\Database\Eloquent\Collection<int, Item> $items
 */
class Assessment extends AbstractModel
{
    use ScopeVisibilityTrait;

    public const WEIGHTINGS = ['equal', 'weighted'];

    /** Quizzes one assessment may hold. */
    public const MAX_ITEMS = 50;

    protected $table = 'quiz_assessments';

    public $timestamps = true;

    protected $casts = [
        'user_id' => 'integer',
        'pass_mark' => 'integer',
        'published_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * The request's actor's progress, filled for a whole page at once by
     * {@see Progress::prime()}. Never stored.
     *
     * @var array<string, mixed>|null
     */
    public ?array $viewer = null;

    /**
     * What an API request sent, gathered by the resource's fields for
     * {@see Saver::save()}. Never stored.
     *
     * @var array<string, mixed>
     */
    public array $input = [];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Item, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(Item::class)->orderBy('position');
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }
}
