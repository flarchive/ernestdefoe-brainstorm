<?php

use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Up to 1.1.x the leaderboard choice was Brainstorm's `users.quiz_private`
 * column. On a forum upgraded from it, each member who had it on gets an
 * opt-out row and the column goes; a new install never had it, so there is
 * nothing to do.
 *
 * Down puts the forum back as 1.1.x left it: the column, filled from the
 * opt-out rows. Rolling further back, 2026_11_19_000000 drops the opt-out
 * table and 2026_10_20_000004 drops the column again.
 */
return [
    'up' => function (Builder $schema) {
        if (! $schema->hasColumn('users', 'quiz_private')) {
            return;
        }

        $db = $schema->getConnection();
        $now = Carbon::now();

        $db->table('users')->where('quiz_private', true)->orderBy('id')->select('id')
            ->chunkById(500, function ($users) use ($db, $now) {
                $db->table('quiz_leaderboard_opt_outs')->insertOrIgnore(
                    $users->map(fn ($u) => ['user_id' => $u->id, 'created_at' => $now])->all()
                );
            });

        $schema->table('users', function (Blueprint $table) {
            $table->dropColumn('quiz_private');
        });
    },
    'down' => function (Builder $schema) {
        if ($schema->hasColumn('users', 'quiz_private')) {
            return;
        }

        $schema->table('users', function (Blueprint $table) {
            $table->boolean('quiz_private')->default(false);
        });

        $db = $schema->getConnection();
        $db->table('users')
            ->whereIn('id', $db->table('quiz_leaderboard_opt_outs')->select('user_id'))
            ->update(['quiz_private' => true]);
    },
];
