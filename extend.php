<?php

/*
 * Brainstorm — quizzes and knowledge tests for Flarum.
 *
 * The shape of the extension: a quiz is written in the forum, dealt to each
 * player as a paper with no answer key on it, marked on the server against
 * the server's own clock, and remembered as one best result per member, which
 * is what leaderboards, badges, points and Onramp read.
 */

use ErnestDefoe\Brainstorm\Access;
use ErnestDefoe\Brainstorm\Api;
use ErnestDefoe\Brainstorm\Console;
use ErnestDefoe\Brainstorm\Attempt;
use ErnestDefoe\Brainstorm\Content\QuizDocument;
use ErnestDefoe\Brainstorm\Event\AttemptFinished;
use ErnestDefoe\Brainstorm\Integration\Badges\BadgesProvider;
use ErnestDefoe\Brainstorm\Integration\Badges\EvaluateBadges;
use ErnestDefoe\Brainstorm\Integration\Gamification\GamificationProvider;
use ErnestDefoe\Brainstorm\Integration\Onramp\PassQuizzesStep;
use ErnestDefoe\Brainstorm\Integration\Rewards\RewardsProvider;
use ErnestDefoe\Brainstorm\Integration\Tags\TagMentionsProvider;
use ErnestDefoe\Brainstorm\LeaderboardChoice;
use ErnestDefoe\Brainstorm\Listener\PayRewards;
use ErnestDefoe\Brainstorm\OptOut;
use ErnestDefoe\Brainstorm\Quiz;
use ErnestDefoe\Brainstorm\Search;
use ErnestDefoe\Brainstorm\Widget\QuizzesBlock;
use Flarum\Api\Resource\ForumResource;
use Flarum\Api\Resource\UserResource;
use Flarum\Extend;
use Flarum\Search\Database\DatabaseSearchDriver;
use Flarum\User\User;
use Illuminate\Console\Scheduling\Event;

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

    new Extend\ApiResource(Api\Resource\QuizResource::class),
    new Extend\ApiResource(Api\Resource\AttemptResource::class),

    (new Extend\SearchDriver(DatabaseSearchDriver::class))
        ->addSearcher(Quiz::class, Search\QuizSearcher::class)
        ->addFilter(Search\QuizSearcher::class, Search\Filter\IdsFilter::class)
        ->addFilter(Search\QuizSearcher::class, Search\Filter\MineFilter::class)
        ->addFilter(Search\QuizSearcher::class, Search\Filter\StatusFilter::class)
        ->setFulltext(Search\QuizSearcher::class, Search\QuizFulltext::class),

    (new Extend\ModelVisibility(Quiz::class))
        ->scope(Access\ScopeQuizVisibility::class),
    (new Extend\ModelVisibility(Attempt::class))
        ->scope(Access\ScopeAttemptVisibility::class),

    (new Extend\Policy())
        ->modelPolicy(Quiz::class, Access\QuizPolicy::class)
        ->modelPolicy(Attempt::class, Access\AttemptPolicy::class),

    (new Extend\Event())
        ->listen(AttemptFinished::class, PayRewards::class),

    // Abandoned timed attempts are marked in the background, not by whoever views a leaderboard.
    (new Extend\Console())
        ->command(Console\ExpireAttempts::class)
        ->schedule(Console\ExpireAttempts::class, function (Event $event): void {
            $event->everyFiveMinutes()->withoutOverlapping();
        }),

    (new Extend\Settings())
        ->default('ernestdefoe-brainstorm.show_nav', true)
        ->default('ernestdefoe-brainstorm.editors_count', false)
        ->serializeToForum('brainstormShowNav', 'ernestdefoe-brainstorm.show_nav', 'boolval'),

    (new Extend\ApiResource(ForumResource::class))
        ->fields(Api\Fields\ForumFields::class),

    (new Extend\ApiResource(UserResource::class))
        ->fields(Api\Fields\UserFields::class),

    // 1.1's `users.quiz_private`, read-only, for extensions that still read it.
    (new Extend\Model(User::class))
        ->hasOne('quizOptOut', OptOut::class, 'user_id')
        ->cast('quiz_private', LeaderboardChoice::class),

    // No rewards unless FoF Badges or FoF Gamification bind theirs below.
    (new Extend\ServiceProvider())
        ->register(RewardsProvider::class),

    (new Extend\Conditional())
        ->whenExtensionEnabled('fof-badges', fn () => [
            (new Extend\ServiceProvider())->register(BadgesProvider::class),
            (new Extend\Event())->listen(AttemptFinished::class, EvaluateBadges::class),
        ])
        ->whenExtensionEnabled('fof-gamification', fn () => [
            (new Extend\ServiceProvider())->register(GamificationProvider::class),
        ])
        ->whenExtensionEnabled('flarum-tags', fn () => [
            (new Extend\ServiceProvider())->register(TagMentionsProvider::class),
        ])
        ->whenExtensionEnabled('ernestdefoe-onramp', fn () => [
            (new \ErnestDefoe\Onramp\Extend\StepTypes())->add(PassQuizzesStep::class),
        ])
        ->whenExtensionEnabled('ernestdefoe-page-builder', fn () => [
            new \Ernestdefoe\PageBuilder\Extend\PageBuilderBlock(QuizzesBlock::class),
        ]),
];
