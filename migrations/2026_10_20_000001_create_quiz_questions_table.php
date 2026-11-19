<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * A question. `options` is JSON: [{id, text, correct}] — for a typed-answer
 * question, the accepted answers. 🚨 It holds the answer key, so it is only
 * ever read on the server; what a player is sent is built field by field in
 * the presenter.
 */
return Migration::createTable('quiz_questions', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('quiz_id');
    $table->unsignedSmallInteger('position')->default(0);
    // single | multiple | boolean | text
    $table->string('type', 16);
    $table->mediumText('body');
    $table->string('image_url', 500)->nullable();
    $table->text('options');
    $table->mediumText('explanation')->nullable();
    $table->unsignedSmallInteger('points')->default(1);

    $table->foreign('quiz_id')->references('id')->on('quizzes')->cascadeOnDelete();
    $table->index(['quiz_id', 'position']);
});
