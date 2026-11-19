<?php

namespace ErnestDefoe\Brainstorm\Integration\Badges;

use ErnestDefoe\Brainstorm\Integration\Rewards\BadgeSource;
use Flarum\Foundation\AbstractServiceProvider;
use FoF\Badges\Trigger\MetricManager;

/**
 * Brainstorm's two triggers in FoF Badges, and FoF Badges as the source of
 * quiz reward badges. Registered from extend.php only when fof-badges is
 * enabled, beside {@see EvaluateBadges}.
 */
class BadgesProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->bind(BadgeSource::class, FofBadges::class);

        $this->container->extend(MetricManager::class, function (MetricManager $manager) {
            $manager->register($this->container->make(QuizzesPassedMetric::class));
            $manager->register($this->container->make(PerfectScoresMetric::class));

            return $manager;
        });
    }
}
