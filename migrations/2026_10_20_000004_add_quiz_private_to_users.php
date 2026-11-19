<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * 1.1.x added a `users.quiz_private` column here. 1.2 keeps that choice in its
 * own table (2026_11_19_000000), so a new install adds nothing to `users`;
 * 2026_11_19_000001 moves an upgraded forum's choices there and drops the
 * column.
 *
 * The file stays, under its old name, because forums that ran it have it on
 * record and Flarum rolls back by that record. Its down removes the column
 * wherever a rollback of 000001 put it back, as 1.1's own down did.
 */
return [
    'up' => function (Builder $schema) {
        // Nothing to add: see above.
    },
    'down' => function (Builder $schema) {
        if ($schema->hasColumn('users', 'quiz_private')) {
            $schema->table('users', function (Blueprint $table) {
                $table->dropColumn('quiz_private');
            });
        }
    },
];
