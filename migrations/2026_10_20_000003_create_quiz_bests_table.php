<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Each member's best finished attempt at each quiz, kept up to date on every
 * submission. Leaderboards, "your best" on a card and the badge metrics read
 * this one indexed row per member instead of ranking every attempt.
 */
return Migration::createTable('quiz_bests', function (Blueprint $table) {
    $table->unsignedInteger('quiz_id');
    $table->unsignedInteger('user_id');
    $table->unsignedInteger('attempt_id');
    $table->decimal('score', 10, 2);
    $table->decimal('percent', 5, 2);
    $table->unsignedInteger('duration');
    $table->boolean('passed');
    $table->unsignedInteger('attempts')->default(1);
    $table->dateTime('first_passed_at')->nullable();
    $table->dateTime('achieved_at');

    $table->primary(['quiz_id', 'user_id']);
    $table->foreign('quiz_id')->references('id')->on('quizzes')->cascadeOnDelete();
    $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
    $table->index(['quiz_id', 'percent', 'duration']);
    $table->index(['user_id', 'passed']);
});
