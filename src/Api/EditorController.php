<?php

namespace ErnestDefoe\Brainstorm\Api;

use ErnestDefoe\Brainstorm\Access;
use ErnestDefoe\Brainstorm\Rewards;
use Flarum\Http\RequestUtil;
use Flarum\User\Exception\PermissionDeniedException;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/quiz-editor — what the quiz editor needs to know about this forum:
 * whether the actor may attach rewards, and if so which rewards exist.
 */
class EditorController implements RequestHandlerInterface
{
    public function __construct(private Rewards $rewards, private ConnectionInterface $db)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);

        if (! Access::canCreate($actor)) {
            throw new PermissionDeniedException();
        }

        $canReward = Access::canReward($actor);
        $badges = $canReward && $this->rewards->badgesAvailable()
            ? $this->db->table('fof_badges')->orderBy('name')->limit(500)->get(['id', 'name', 'icon', 'icon_color', 'background_color'])
                ->map(fn ($b) => ['id' => (int) $b->id, 'name' => (string) $b->name, 'icon' => (string) $b->icon, 'color' => (string) $b->icon_color, 'background' => (string) $b->background_color])->all()
            : [];

        return new JsonResponse(['data' => [
            'canReward' => $canReward,
            'points' => $canReward && $this->rewards->pointsAvailable(),
            'badges' => $canReward && $this->rewards->badgesAvailable(),
            'badgeList' => $badges,
        ]]);
    }
}
