<?php

use Flarum\Database\Migration;

/**
 * A member's "keep me off quiz leaderboards" choice. A column, not a
 * preference, because the leaderboard query filters on it in SQL.
 */
return Migration::addColumns('users', [
    'quiz_private' => ['boolean', 'default' => false],
]);
