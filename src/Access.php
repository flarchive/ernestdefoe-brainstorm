<?php

namespace ErnestDefoe\Brainstorm;

use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;

/**
 * Who may do what to a quiz. Every controller asks here, so the rules live in
 * one place.
 */
class Access
{
    public static function canView(User $actor, Quiz $quiz): bool
    {
        return $actor->hasPermission('brainstorm.view')
            && ($quiz->isPublished() || self::canEdit($actor, $quiz));
    }

    public static function canCreate(User $actor): bool
    {
        return ! $actor->isGuest() && ($actor->hasPermission('brainstorm.create') || $actor->hasPermission('brainstorm.manage'));
    }

    /** The author while they may still write quizzes, or a manager. */
    public static function canEdit(User $actor, Quiz $quiz): bool
    {
        if ($actor->isGuest()) {
            return false;
        }

        return $actor->hasPermission('brainstorm.manage')
            || ($quiz->user_id === $actor->id && $actor->hasPermission('brainstorm.create'));
    }

    /**
     * Attaching points or a badge. Kept from authors on purpose: an author who
     * could attach rewards could write a quiz only they can pass and pay
     * themselves.
     */
    public static function canReward(User $actor): bool
    {
        return $actor->hasPermission('brainstorm.reward');
    }

    /**
     * Whether an attempt is practice: it does not count on leaderboards, use
     * up attempts or earn rewards. Editors can see the key, so theirs are,
     * unless the forum counts them (an admin who had AI write the questions
     * never saw the answers) and the quiz is open to everyone. A draft or a
     * quiz still waiting to open is always practice.
     */
    public static function practises(User $actor, Quiz $quiz): bool
    {
        return self::canEdit($actor, $quiz)
            && ! ($quiz->isOpen() && resolve(SettingsRepositoryInterface::class)->get('ernestdefoe-brainstorm.editors_count'));
    }

    /** Editors may always try their quiz, drafts included: as practice. */
    public static function canTake(User $actor, Quiz $quiz): bool
    {
        if (self::canEdit($actor, $quiz)) {
            return $quiz->question_count > 0;
        }

        return ! $actor->isGuest()
            && $actor->hasPermission('brainstorm.view')
            && $actor->hasPermission('brainstorm.take')
            && $quiz->isOpen();
    }
}
