<?php

namespace ErnestDefoe\Brainstorm\Integration\Onramp;

use ErnestDefoe\Brainstorm\Event\AttemptFinished;
use ErnestDefoe\Onramp\StepType\StepType;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;

/**
 * An Onramp step: "pass N quizzes". Counted from the members' bests, so a
 * journey started today already counts quizzes passed last month.
 *
 * Only registered when Onramp is enabled (see extend.php).
 */
class PassQuizzesStep extends StepType
{
    public function __construct(private ConnectionInterface $db)
    {
    }

    public function key(): string
    {
        return 'brainstorm.passed';
    }

    public function icon(): string
    {
        return 'fas fa-graduation-cap';
    }

    public function events(): array
    {
        return [AttemptFinished::class => fn (AttemptFinished $e) => $e->user];
    }

    public function progress(User $user, array $config): int
    {
        return $this->db->table('quiz_bests')->where('user_id', $user->id)->where('passed', true)->count();
    }

    public function extension(): ?string
    {
        return 'ernestdefoe-brainstorm';
    }

    public function fields(): array
    {
        return ['count'];
    }
}
