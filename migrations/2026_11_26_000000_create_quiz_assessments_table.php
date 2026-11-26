<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Assessments: a group of existing quizzes (one per chapter, say) with an
 * overall result, the equal or weighted average of a member's best score at
 * each, and its own pass mark.
 */
return Migration::createTable('quiz_assessments', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('user_id')->nullable();
    $table->string('title', 200);
    $table->string('slug', 200);
    $table->text('description')->nullable();
    $table->string('status', 20)->default('draft');
    $table->unsignedTinyInteger('pass_mark')->default(70);
    // equal: every quiz counts the same; weighted: each by its weight.
    $table->string('weighting', 20)->default('equal');
    $table->dateTime('published_at')->nullable();
    $table->timestamps();

    $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
    $table->index(['status', 'published_at']);
});
