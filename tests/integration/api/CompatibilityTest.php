<?php

namespace ErnestDefoe\Brainstorm\Tests\integration\api;

use ErnestDefoe\Brainstorm\Quiz;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

/** What other extensions (Buzz, Syllabus) read from 1.1 keeps working in 1.2. */
class CompatibilityTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-brainstorm');
        $this->prepareDatabase([
            User::class => [$this->normalUser(), ['id' => 3, 'username' => 'carol', 'email' => 'carol@machine.local', 'is_email_confirmed' => 1]],
            'quizzes' => [
                ['id' => 1, 'user_id' => 1, 'title' => 'Open', 'slug' => 'open', 'status' => 'published', 'reveal' => 'answers'],
                ['id' => 2, 'user_id' => 1, 'title' => 'Draft', 'slug' => 'draft', 'status' => 'draft', 'reveal' => 'answers'],
            ],
            'quiz_leaderboard_opt_outs' => [['user_id' => 3]],
        ]);
    }

    #[Test]
    public function the_old_visible_to_scope_still_answers_like_where_visible_to(): void
    {
        $this->app();
        $member = User::query()->find(2);
        $admin = User::query()->find(1);

        $this->assertSame(['Open'], Quiz::query()->visibleTo($member)->pluck('title')->all());
        $this->assertSame(Quiz::query()->whereVisibleTo($admin)->orderBy('id')->pluck('id')->all(), Quiz::query()->visibleTo($admin)->orderBy('id')->pluck('id')->all());
    }

    #[Test]
    public function a_users_old_quiz_private_attribute_reads_the_opt_out_and_never_writes(): void
    {
        $this->app();
        $carol = User::query()->find(3);
        $normal = User::query()->find(2);

        $this->assertTrue($carol->getAttribute('quiz_private'));
        $this->assertFalse($normal->getAttribute('quiz_private'));

        // Read, then saved: nothing is written to a users column that no longer exists.
        $carol->username = 'carol_quizzes';
        $carol->save();
        $this->assertSame('carol_quizzes', User::query()->find(3)->username);
    }

    #[Test]
    public function a_list_reads_quiz_private_in_one_query_and_never_serializes_it(): void
    {
        $this->app();
        $users = User::query()->with('quizOptOut')->whereIn('id', [1, 2, 3])->orderBy('id')->get();

        $queries = 0;
        $this->database()->listen(function () use (&$queries) {
            $queries++;
        });
        $private = $users->map(fn (User $user) => $user->getAttribute('quiz_private'))->all();

        $this->assertSame([false, false, true], $private);
        $this->assertSame(0, $queries, 'the eager-loaded relation answers every user');
        $this->assertArrayNotHasKey('quiz_private', $users[2]->toArray());
        $this->assertSame([3], User::query()->whereIn('id', \ErnestDefoe\Brainstorm\OptOut::userIds())->pluck('id')->map(fn ($id) => (int) $id)->all());
    }

    #[Test]
    public function the_api_never_sends_quiz_private_on_users(): void
    {
        $response = $this->send($this->request('GET', '/api/users', ['authenticatedAs' => 1]));
        $body = (string) $response->getBody();

        $this->assertSame(200, $response->getStatusCode(), $body);
        $this->assertStringNotContainsString('quiz_private', $body);
    }
}
