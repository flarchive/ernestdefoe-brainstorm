<?php

namespace ErnestDefoe\Brainstorm\Tests\integration\api;

use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

/** The quiz list costs the same number of queries for one quiz or many. */
class QueryCountTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-mentions', 'ernestdefoe-brainstorm');
        $this->prepareDatabase([User::class => [$this->normalUser()]]);
    }

    private function queriesFor(int $quizzes): int
    {
        $ids = $this->database()->table('quizzes')->orderBy('id')->limit($quizzes)->pluck('id')->implode(',');
        $queries = 0;
        $this->database()->listen(function () use (&$queries) {
            $queries++;
        });
        $request = $this->request('GET', '/api/quizzes', ['authenticatedAs' => 2])->withQueryParams(['filter' => ['ids' => $ids]]);
        $this->send($request); // warm
        $queries = 0;
        $response = $this->send($request);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount($quizzes, json_decode((string) $response->getBody(), true)['data']);

        return $queries;
    }

    #[Test]
    public function mentions_in_many_quizzes_are_looked_up_once(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $response = $this->send($this->request('POST', '/api/quizzes', ['authenticatedAs' => 1, 'json' => ['data' => [
                'title' => "Quiz $i",
                // The form the composer writes: display name and id.
                'description' => "Thanks @\"normal\"#2 and @\"admin\"#1 ($i)",
                'status' => 'published',
                'questions' => [['type' => 'boolean', 'body' => 'Yes?', 'options' => [['id' => 'true', 'correct' => true]]]],
            ]]]));
            $this->assertSame(201, $response->getStatusCode(), substr((string) $response->getBody(), 0, 1500));
        }

        $this->assertStringContainsString('USERMENTION', (string) $this->database()->table('quizzes')->value('description'));
        $this->assertSame($this->queriesFor(1), $this->queriesFor(5));
    }
}
