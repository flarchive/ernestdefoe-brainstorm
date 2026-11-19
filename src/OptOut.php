<?php

namespace ErnestDefoe\Brainstorm;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;

/**
 * A member who chose to stay off quiz leaderboards: a row here, keyed by the
 * user, rather than a column on the core users table.
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
}
