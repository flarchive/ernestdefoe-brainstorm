<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/** The quizzes in an assessment, in order, each with its weight and whether it is required. */
return Migration::createTable('quiz_assessment_items', function (Blueprint $table) {
    $table->unsignedInteger('assessment_id');
    $table->unsignedInteger('quiz_id');
    $table->unsignedSmallInteger('weight')->default(1);
    $table->boolean('required')->default(true);
    $table->unsignedSmallInteger('position')->default(0);

    $table->primary(['assessment_id', 'quiz_id']);
    $table->foreign('assessment_id')->references('id')->on('quiz_assessments')->cascadeOnDelete();
    $table->foreign('quiz_id')->references('id')->on('quizzes')->cascadeOnDelete();
    $table->index('quiz_id');
});
