<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/** The words of each quiz's title, for an indexed quiz search (see TitleWords). */
return Migration::createTable('quiz_title_words', function (Blueprint $table) {
    $table->unsignedInteger('quiz_id');
    $table->string('word', 50);

    $table->primary(['quiz_id', 'word']);
    $table->index('word');
    $table->foreign('quiz_id')->references('id')->on('quizzes')->cascadeOnDelete();
});
