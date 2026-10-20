<?php

namespace ErnestDefoe\Brainstorm\Api;

use ErnestDefoe\Brainstorm\Access;
use ErnestDefoe\Brainstorm\Presenter;
use ErnestDefoe\Brainstorm\Quiz;
use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/quizzes — the quizzes the actor may see, newest first.
 *
 * `filter[ids]=1,2,3` narrows it to the quizzes named by links in posts: one
 * request for every quiz link on a page. `filter[mine]=1` is the actor's own,
 * drafts included. `filter[q]` searches titles. `sort=popular` orders by
 * finished attempts.
 */
class ListQuizzesController implements RequestHandlerInterface
{
    public const PAGE = 20;

    public function __construct(private Presenter $presenter)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $params = $request->getQueryParams();
        $filter = (array) Arr::get($params, 'filter', []);

        $query = Quiz::query()->visibleTo($actor)->with('user');

        if (is_string($ids = $filter['ids'] ?? null)) {
            $ids = array_slice(array_values(array_unique(array_filter(explode(',', $ids), fn ($id) => ctype_digit($id) && strlen($id) <= 10 && (int) $id <= 2147483647))), 0, 50);
            $query->whereIn('id', $ids ?: [0]);
        } else {
            if (! empty($filter['mine']) && ! $actor->isGuest()) {
                $query->where('user_id', $actor->id);
            } else {
                $query->where('status', 'published');
            }

            if (is_string($q = $filter['q'] ?? null) && trim($q) !== '') {
                // Bound, without LIKE's wildcards (escaping them differs on
                // SQLite), and lower-cased on both sides for PostgreSQL.
                $words = mb_strtolower(str_replace(['%', '_', '\\'], ' ', mb_substr(trim($q), 0, 100)));
                $query->whereRaw('LOWER('.$query->getQuery()->getGrammar()->wrap('title').') LIKE ?', ['%'.trim($words).'%']);
            }
        }

        if (($params['sort'] ?? null) === 'popular') {
            $query->orderByDesc('attempt_count');
        }
        $query->orderByDesc('published_at')->orderByDesc('id');

        $offset = max(0, (int) Arr::get($params, 'page.offset', 0));
        $quizzes = $query->skip($offset)->take(self::PAGE + 1)->get();
        $more = $quizzes->count() > self::PAGE;

        return new JsonResponse([
            'data' => $this->presenter->quizzes($quizzes->take(self::PAGE), $actor),
            'meta' => [
                'canCreate' => Access::canCreate($actor),
                'next' => $more ? $offset + self::PAGE : null,
            ],
        ]);
    }
}
