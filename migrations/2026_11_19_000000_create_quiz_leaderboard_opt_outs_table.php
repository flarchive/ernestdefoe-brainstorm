<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Members who keep themselves off quiz leaderboards: one row each. A
 * companion table keyed on the user, so Brainstorm adds nothing to `users`.
 */
return Migration::createTable('quiz_leaderboard_opt_outs', function (Blueprint $table) {
    $table->unsignedInteger('user_id')->primary();
    $table->dateTime('created_at')->nullable();

    $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
});
