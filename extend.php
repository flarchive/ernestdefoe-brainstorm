<?php

/*
 * Brainstorm — quizzes and knowledge tests for Flarum.
 *
 * The shape of the extension: a quiz is written in the forum, dealt to each
 * player as a paper with no answer key on it, marked on the server against
 * the server's own clock, and remembered as one best result per member, which
 * is what leaderboards, badges, points and Onramp read.
 */

use ErnestDefoe\Brainstorm\Api;
use ErnestDefoe\Brainstorm\Content\QuizDocument;
use ErnestDefoe\Brainstorm\Event\AttemptFinished;
use ErnestDefoe\Brainstorm\Integration\Badges\BadgesProvider;
use ErnestDefoe\Brainstorm\Integration\Onramp\PassQuizzesStep;
use ErnestDefoe\Brainstorm\Listener\PayRewards;
use ErnestDefoe\Brainstorm\Widget\QuizzesBlock;
use ErnestDefoe\Brainstorm\Access;
use Flarum\Api\Context;
use Flarum\Api\Resource\ForumResource;
use Flarum\Api\Resource\UserResource;
use Flarum\Api\Schema;
use Flarum\Extend;
use Flarum\User\User;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        // The pages, the player and the editor are their own chunks.
        ->jsDirectory(__DIR__.'/js/dist/forum')
        ->css(__DIR__.'/less/forum.less')
        // Static paths before {id}: FastRoute refuses a static route that an
        // earlier variable one already matches.
        ->route('/quizzes', 'brainstorm.index')
        ->route('/quizzes/new', 'brainstorm.new')
        ->route('/quizzes/leaderboard', 'brainstorm.leaderboard')
        ->route('/quizzes/{id:\d+(?:-[^/]*)?}', 'brainstorm.quiz', QuizDocument::class)
        ->route('/quizzes/{id:\d+}/edit', 'brainstorm.edit'),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js'),

    new Extend\Locales(__DIR__.'/locale'),

    (new Extend\Routes('api'))
        ->get('/quizzes', 'brainstorm.quizzes.index', Api\ListQuizzesController::class)
        ->post('/quizzes', 'brainstorm.quizzes.create', Api\SaveQuizController::class)
        ->get('/quiz-editor', 'brainstorm.editor', Api\EditorController::class)
        ->get('/quiz-leaderboard', 'brainstorm.leaderboard', Api\LeaderboardController::class)
        ->get('/quizzes/{id:[0-9]+}', 'brainstorm.quizzes.show', Api\ShowQuizController::class)
        ->patch('/quizzes/{id:[0-9]+}', 'brainstorm.quizzes.update', Api\SaveQuizController::class)
        ->delete('/quizzes/{id:[0-9]+}', 'brainstorm.quizzes.delete', Api\DeleteQuizController::class)
        ->post('/quizzes/{id:[0-9]+}/attempts', 'brainstorm.attempts.start', Api\StartAttemptController::class)
        ->get('/quizzes/{id:[0-9]+}/leaderboard', 'brainstorm.quizzes.leaderboard', Api\LeaderboardController::class)
        ->get('/quizzes/{id:[0-9]+}/results', 'brainstorm.quizzes.results', Api\ResultsController::class)
        ->delete('/quizzes/{id:[0-9]+}/results/{user:[0-9]+}', 'brainstorm.quizzes.reset', Api\ResultsController::class)
        ->get('/quiz-attempts/{id:[0-9]+}', 'brainstorm.attempts.show', Api\AttemptController::class)
        ->patch('/quiz-attempts/{id:[0-9]+}', 'brainstorm.attempts.save', Api\AttemptController::class)
        ->post('/quiz-attempts/{id:[0-9]+}/submit', 'brainstorm.attempts.submit', Api\AttemptController::class),

    (new Extend\Event())
        ->listen(AttemptFinished::class, PayRewards::class),

    (new Extend\Settings())
        ->default('ernestdefoe-brainstorm.show_nav', true)
        ->default('ernestdefoe-brainstorm.editors_count', false)
        ->serializeToForum('brainstormShowNav', 'ernestdefoe-brainstorm.show_nav', 'boolval'),

    // Both are permissions the actor's groups were already loaded for: no query.
    (new Extend\ApiResource(ForumResource::class))
        ->fields(fn () => [
            Schema\Boolean::make('canViewQuizzes')
                ->get(fn ($forum, Context $context) => $context->getActor()->hasPermission('brainstorm.view')),
            Schema\Boolean::make('canCreateQuizzes')
                ->get(fn ($forum, Context $context) => Access::canCreate($context->getActor())),
        ]),

    (new Extend\Model(User::class))
        ->cast('quiz_private', 'boolean'),

    // The member's own "keep me off quiz leaderboards" choice, on their own
    // user only: read and written by them, invisible to everyone else.
    (new Extend\ApiResource(UserResource::class))
        ->fields(fn () => [
            Schema\Boolean::make('quizPrivate')
                ->property('quiz_private')
                ->visible(fn (User $user, Context $context) => $context->getActor()->id === $user->id)
                ->writable(fn (User $user, Context $context) => $context->getActor()->id === $user->id),
        ]),

    (new Extend\Conditional())
        ->whenExtensionEnabled('fof-badges', fn () => [
            (new Extend\ServiceProvider())->register(BadgesProvider::class),
        ])
        ->whenExtensionEnabled('ernestdefoe-onramp', fn () => [
            (new \ErnestDefoe\Onramp\Extend\StepTypes())->add(PassQuizzesStep::class),
        ])
        ->whenExtensionEnabled('ernestdefoe-page-builder', fn () => [
            new \Ernestdefoe\PageBuilder\Extend\PageBuilderBlock(QuizzesBlock::class),
        ]),
];
