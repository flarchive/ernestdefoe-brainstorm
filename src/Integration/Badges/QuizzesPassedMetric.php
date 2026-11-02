<?php

namespace ErnestDefoe\Brainstorm\Integration\Badges;

use ErnestDefoe\Brainstorm\Event\AttemptFinished;
use Flarum\User\User;
use FoF\Badges\Trigger\MetricInterface;
use Illuminate\Database\ConnectionInterface;

/** A FoF Badges trigger: "quizzes passed ≥ N". */
class QuizzesPassedMetric implements MetricInterface
{
    public function __construct(private ConnectionInterface $db)
    {
    }

    public function getType(): string
    {
        return 'brainstorm_quizzes_passed';
    }

    public function getTranslationKey(): string
    {
        return 'ernestdefoe-brainstorm.admin.badges.quizzes_passed';
    }

    public function getEventTriggers(): array
    {
        return [AttemptFinished::class => fn (AttemptFinished $e) => $e->user];
    }

    public function getValue(User $user, array $config = []): int
    {
        return $this->db->table('quiz_bests')->where('user_id', $user->id)->where('passed', true)->count();
    }

    public function isBoolean(): bool
    {
        return false;
    }

    public function getConfigFields(): array
    {
        return [];
    }

    public function getExtensionDependencies(): array
    {
        return ['ernestdefoe/brainstorm'];
    }
}
