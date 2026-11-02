<?php

namespace ErnestDefoe\Brainstorm\Tests\integration\api;

use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

class RewardsTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('fof-badges', 'ernestdefoe-brainstorm');

        $this->prepareDatabase([
            User::class => [$this->normalUser()],
            'fof_badges' => [
                ['id' => 1, 'name' => 'Cartographer', 'slug' => 'cartographer', 'icon' => 'fas fa-map', 'is_active' => 1, 'is_visible' => 1],
                // A trigger badge on Brainstorm's own metric: one quiz passed.
                ['id' => 2, 'name' => 'Quizzer', 'slug' => 'quizzer', 'icon' => 'fas fa-star', 'is_active' => 1, 'is_visible' => 1,
                    'trigger_config' => json_encode(['conditions' => [['metric' => 'brainstorm_quizzes_passed', 'operator' => '>=', 'value' => 1]]])],
            ],
        ]);
    }

    private function send_(string $method, string $path, int $actor, ?array $body = null): array
    {
        $response = $this->send($this->request($method, $path, ['authenticatedAs' => $actor] + ($body ? ['json' => $body] : [])));

        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true)];
    }

    #[Test]
    public function a_first_pass_earns_the_quiz_badges_and_the_author_earns_nothing(): void
    {
        [$status, $quiz] = $this->send_('POST', '/api/quizzes', 1, ['data' => [
            'title' => 'Maps',
            'status' => 'published',
            'rewardBadgeId' => 1,
            'questions' => [['type' => 'boolean', 'body' => 'Is the Earth round?', 'options' => [['id' => 'true', 'correct' => true]]]],
        ]]);
        $this->assertSame(201, $status);
        $this->assertSame(1, $quiz['data']['edit']['rewardBadgeId']);
        $this->assertSame('Cartographer', $quiz['data']['rewardBadge']['name']);

        foreach ([2, 1] as $user) {
            [, $paper] = $this->send_('POST', '/api/quizzes/'.$quiz['data']['id'].'/attempts', $user);
            [, $result] = $this->send_('POST', '/api/quiz-attempts/'.$paper['data']['id'].'/submit', $user, ['data' => ['answers' => [(string) $paper['data']['questions'][0]['id'] => 'true']]]);
            $this->assertTrue($result['data']['passed']);
        }

        $held = fn (int $user, int $badge) => $this->database()->table('fof_badge_user')->where('user_id', $user)->where('badge_id', $badge)->count();

        $this->assertSame(1, $held(2, 1), 'the quiz badge');
        $this->assertSame(1, $held(2, 2), 'the trigger badge on quizzes passed');
        $this->assertSame(0, $held(1, 1) + $held(1, 2), 'an author\'s run is practice: no rewards, no metrics');
    }
}
