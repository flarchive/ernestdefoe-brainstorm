<?php

namespace ErnestDefoe\Brainstorm;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Illuminate\Database\Eloquent\Builder;

/**
 * A member who chose to stay off quiz leaderboards: a row here, keyed by the
 * user, rather than a column on the core users table.
 *
 * For other extensions: `OptOut::userIds()` is a subquery of their ids, for
 * `whereNotIn('users.id', OptOut::userIds())`; on one user, the
 * `quizOptOut` relation (eager-loadable: `with('quizOptOut')`).
 *
 * @property int $user_id
 * @property Carbon|null $created_at
 */
class OptOut extends AbstractModel
{
    protected $table = 'quiz_leaderboard_opt_outs';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $casts = [
        'user_id' => 'integer',
        'created_at' => 'datetime',
    ];

    /** @return Builder<OptOut> the ids of every member who opted out, as a subquery */
    public static function userIds(): Builder
    {
        return self::query()->select('user_id');
    }
}
