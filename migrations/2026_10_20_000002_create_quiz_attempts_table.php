<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * One go at a quiz. `layout` is what this attempt was dealt — the questions
 * and the order of their options — fixed when it starts, so a reload or a
 * resume shows the same paper and the scorer marks exactly that paper.
 * `deadline_at` is set by the server from the quiz's time limit; the
 * player's clock is only a display.
 */
return Migration::createTable('quiz_attempts', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('quiz_id');
    $table->unsignedInteger('user_id');
    $table->dateTime('started_at');
    $table->dateTime('deadline_at')->nullable();
    $table->dateTime('submitted_at')->nullable();
    $table->text('layout');
    $table->text('answers')->nullable();
    // Each question's mark when it was marked, by question id.
    $table->text('marks')->nullable();
    $table->decimal('score', 10, 2)->nullable();
    $table->decimal('max_score', 10, 2)->nullable();
    $table->decimal('percent', 5, 2)->nullable();
    $table->boolean('passed')->nullable();
    // Seconds from start to submission, capped at the time limit.
    $table->unsignedInteger('duration')->nullable();
    // Finished by the clock rather than by the player.
    $table->boolean('expired')->default(false);
    // Taken by someone who could see the answer key (the author, a manager):
    // a preview, kept off leaderboards and paying no rewards.
    $table->boolean('practice')->default(false);

    $table->foreign('quiz_id')->references('id')->on('quizzes')->cascadeOnDelete();
    $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
    $table->index(['quiz_id', 'user_id']);
    // Finding attempts whose clock ran out.
    $table->index(['quiz_id', 'submitted_at', 'deadline_at']);
    $table->index(['user_id', 'started_at']);
});
