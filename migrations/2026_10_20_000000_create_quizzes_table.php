<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * A quiz: its settings and two running totals for the list and card.
 *
 * `description` holds the forum formatter's XML, like a post. The reward
 * columns name a FoF Badges badge by id without a foreign key: fof/badges is
 * optional, and a badge deleted there just pays nothing.
 */
return Migration::createTable('quizzes', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('user_id')->nullable();
    $table->string('title', 200);
    $table->string('slug', 200);
    $table->mediumText('description')->nullable();
    // draft | published
    $table->string('status', 16)->default('draft');
    // Whole-quiz limit in seconds; null = untimed.
    $table->unsignedInteger('time_limit')->nullable();
    $table->unsignedTinyInteger('pass_mark')->default(70);
    $table->unsignedSmallInteger('max_attempts')->nullable();
    // Questions drawn per attempt from the pool; null = all of them.
    $table->unsignedSmallInteger('question_pick')->nullable();
    $table->boolean('shuffle_questions')->default(false);
    $table->boolean('shuffle_options')->default(true);
    $table->boolean('partial_credit')->default(false);
    // score | mistakes | answers | answers_always
    $table->string('reveal', 16)->default('answers');
    $table->boolean('leaderboard')->default(true);
    $table->dateTime('opens_at')->nullable();
    $table->dateTime('closes_at')->nullable();
    $table->integer('reward_points')->default(0);
    $table->unsignedInteger('reward_badge_id')->nullable();
    $table->unsignedSmallInteger('question_count')->default(0);
    $table->unsignedInteger('attempt_count')->default(0);
    $table->unsignedInteger('pass_count')->default(0);
    $table->dateTime('published_at')->nullable();
    $table->timestamps();

    $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
    $table->index(['status', 'published_at']);
    $table->index('user_id');
});
