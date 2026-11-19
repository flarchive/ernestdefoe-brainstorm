<?php

namespace ErnestDefoe\Brainstorm\Tests\integration\api;

/**
 * The tests are written as "send these settings, read these fields". This
 * wraps a request body into a JSON:API document for the resource the path
 * names, and flattens the response document back into `id` + attributes
 * (and a custom endpoint's `meta.rows` into `data`), so each assertion reads
 * like what it checks.
 */
trait SpeaksJsonApi
{
    /** @return array{0: string, 1: string, 2: array<string, mixed>|null} */
    private static function toJsonApi(string $method, string $path, ?array $body): array
    {
        $data = $body['data'] ?? null;
        if (is_array($data) && isset($data['type'])) {
            return [$method, $path, $body];
        }

        if ($method === 'POST' && preg_match('#^/api/quizzes/(\d+)/attempts$#', $path, $m)) {
            return ['POST', '/api/quiz-attempts', ['data' => ['type' => 'quiz-attempts', 'attributes' => ['quizId' => (int) $m[1]]]]];
        }
        if ($method === 'POST' && preg_match('#^/api/quiz-attempts/(\d+)/submit$#', $path, $m)) {
            return ['PATCH', "/api/quiz-attempts/$m[1]", ['data' => ['type' => 'quiz-attempts', 'id' => $m[1], 'attributes' => ($data ?? []) + ['submit' => true]]]];
        }
        if (preg_match('#^/api/(quizzes|quiz-attempts)(?:/(\d+))?$#', $path, $m) && in_array($method, ['POST', 'PATCH'], true) && $data !== null) {
            return [$method, $path, ['data' => ['type' => $m[1], 'attributes' => $data] + (isset($m[2]) ? ['id' => $m[2]] : [])]];
        }

        return [$method, $path === '/api/quiz-leaderboard' ? '/api/quizzes/leaderboard' : $path, $body];
    }

    /** @param array<string, mixed>|null $document */
    private static function flatten(?array $document): ?array
    {
        if (! is_array($document)) {
            return $document;
        }

        $one = fn (array $r) => isset($r['attributes']) ? ['id' => (int) $r['id']] + $r['attributes'] : $r;
        if (isset($document['data']) && is_array($document['data'])) {
            $document['data'] = array_is_list($document['data']) ? array_map($one, $document['data']) : $one($document['data']);
        }
        if (isset($document['meta']['rows'])) {
            $document['data'] = $document['meta']['rows'];
            unset($document['meta']['rows']);
        }

        return $document;
    }
}
