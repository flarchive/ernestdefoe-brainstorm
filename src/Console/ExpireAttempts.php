<?php

namespace ErnestDefoe\Brainstorm\Console;

use ErnestDefoe\Brainstorm\Attempts;
use Flarum\Console\AbstractCommand;

/**
 * Mark timed attempts whose clock ran out while nobody was looking — the
 * tab was closed, the battery died — so leaderboards, results and rewards
 * see them. Scheduled every five minutes; a member who comes back to the
 * quiz before then has theirs marked on the spot.
 */
class ExpireAttempts extends AbstractCommand
{
    /** At most this many a run, so a backlog is worked through over a few runs. */
    public const BATCH = 500;

    public function __construct(private Attempts $attempts)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('brainstorm:expire-attempts')
            ->setDescription('Mark quiz attempts whose time ran out.');
    }

    protected function fire(): int
    {
        $this->info($this->attempts->expireAll(self::BATCH).' attempt(s) marked.');

        return 0;
    }
}
