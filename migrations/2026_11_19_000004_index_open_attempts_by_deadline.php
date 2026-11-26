<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/** The scheduled sweep finds open attempts past their deadline across every quiz. */
return [
    'up' => function (Builder $schema) {
        $schema->table('quiz_attempts', function (Blueprint $table) {
            $table->index(['submitted_at', 'deadline_at']);
        });
    },
    'down' => function (Builder $schema) {
        $schema->table('quiz_attempts', function (Blueprint $table) {
            $table->dropIndex(['submitted_at', 'deadline_at']);
        });
    },
];
