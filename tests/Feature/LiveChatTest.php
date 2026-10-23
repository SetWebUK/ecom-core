<?php

namespace Pine\Commerce\Tests\Feature;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Pine\Commerce\Commerce;
use Pine\Commerce\Mail\ContactSubmitted;
use Pine\Commerce\Mail\LiveChatHandoff;
use Pine\Commerce\Models\ChatConversation;
use Pine\Commerce\Models\FormSubmission;
use Pine\Commerce\Models\Setting;
use Pine\Commerce\Services\LiveChat\ChatSettings;
use Pine\Commerce\Services\LiveChat\ChatTools;
use Pine\Commerce\Services\LiveChat\LiveChat;
use Pine\Commerce\Services\LiveChat\Providers\AnthropicProvider;
use Pine\Commerce\Services\LiveChat\Providers\ChatProvider;
use Pine\Commerce\Services\LiveChat\Providers\OpenAiProvider;
use Pine\Commerce\Tests\Concerns\InstallsNeutralStore;
use Pine\Commerce\Tests\TestCase;

/**
 * Live chat (feature "live_chat"): the widget, the assistant with its catalogue tools (fake provider), hand-over to
 * staff, the email fallback, the admin pages, and the two providers' wire formats (no real API calls).
 */
class LiveChatTest extends TestCase
{
    use InstallsNeutralStore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpNeutralStore(['commerce.features.live_chat' => true]);
        Cache::forget('live_chat.staff_seen');
    }

    protected function tearDown(): void
    {
        $this->tearDownNeutralStore();
        parent::tearDown();
    }

    /** A provider that runs $script(transcript, runTool) and records what it was given. */
    private function fakeProvider(callable $script): object
    {
        $provider = new class($script) implements ChatProvider
        {
            public array $calls = [];

            public function __construct(private $script) {}

            public function respond(string $system, array $transcript, array $tools, callable $runTool): array
            {
                $this->calls[] = compact('system', 'transcript', 'tools');

                return ['entries' => ($this->script)($transcript, $runTool), 'usage' => ['input' => 1, 'output' => 1, 'cache_read' => 0], 'model' => 'fake-model'];
            }
        };
        $this->app->instance(ChatProvider::class, $provider);
        Setting::set('live_chat.provider', 'anthropic', 'live_chat');
        Setting::set('live_chat.api_key', \Illuminate\Support\Facades\Crypt::encryptString('sk-test'), 'live_chat');

        return $provider;
    }

    private function send(string $body, ?string $token = null): \Illuminate\Testing\TestResponse
    {
        return $this->postJson(route('live-chat.send'), ['body' => $body, 'token' => $token, 'page_url' => 'https://shop.example.test/shop/']);
    }

    private function staff(string $role = 'manager'): \Pine\Commerce\Models\User
    {
        return Commerce::userModel()::forceCreate(['name' => 'Sam Staff', 'first_name' => 'Sam', 'email' => $role.'@shop.example.test',
            'password' => Hash::make('Staff-pass-123'), 'role' => $role, 'is_active' => true]);
    }

    public function test_the_widget_is_on_storefront_pages_only_while_the_feature_is_on(): void
    {
        $this->get('/')->assertOk()->assertSee('data-live-chat', false)->assertSee('live-chat\\/send', false);

        config(['commerce.features.live_chat' => false]);
        $this->get('/')->assertOk()->assertDontSee('data-live-chat', false);
        $this->postJson(route('live-chat.send'), ['body' => 'Hello'])->assertNotFound();
    }

    public function test_without_an_ai_provider_a_chat_goes_to_staff_and_asks_for_an_email_when_nobody_is_online(): void
    {
        $response = $this->send('Do you have this in blue?')->assertOk()
            ->assertJsonPath('status', 'waiting')->assertJsonPath('ask_email', true)->assertJsonPath('can_handoff', false);
        $this->assertSame(['customer', 'notice'], array_column($response->json('messages'), 'role'));
        $this->assertStringContainsString('isn’t online', $response->json('messages.1.body'));
        Mail::assertSent(LiveChatHandoff::class, 1);

        // a second message: still one staff email
        $this->send('Hello?', $response->json('token'))->assertOk();
        Mail::assertSent(LiveChatHandoff::class, 1);
    }

    public function test_the_assistant_answers_from_the_catalogue(): void
    {
        [$product] = $this->catalogue();
        $provider = $this->fakeProvider(function (array $transcript, callable $runTool) {
            $found = json_decode($runTool('search_products', ['query' => 'linen shirt']), true);
            $detail = json_decode($runTool('get_product', ['product_id' => $found['products'][0]['id']]), true);

            return [
                ['role' => 'assistant', 'text' => '', 'tool_calls' => [['id' => 't1', 'name' => 'search_products', 'input' => ['query' => 'linen shirt']]]],
                ['role' => 'tool', 'results' => [['id' => 't1', 'content' => json_encode($found)]]],
                ['role' => 'assistant', 'text' => $detail['name'].' is '.$detail['price'].' (was '.$detail['was'].'), '.$detail['specifications']['Material'].': '.$detail['url'], 'tool_calls' => []],
            ];
        });

        $response = $this->send('Have you got a linen shirt?')->assertOk()->assertJsonPath('status', 'ai')->assertJsonPath('can_handoff', true);
        $messages = collect($response->json('messages'));
        $this->assertSame(['customer', 'assistant'], $messages->pluck('role')->all(), 'tool rows and empty assistant turns stay hidden');
        $this->assertStringContainsString('Classic Linen Shirt is £30.00 (was £40.00), 100% linen', $messages->last()['body']);
        $this->assertStringContainsString($product->url, $messages->last()['body']);

        $conversation = ChatConversation::firstOrFail();
        $this->assertSame(1, $conversation->ai_replies);
        $this->assertSame([['role' => 'user', 'text' => 'Have you got a linen shirt?']], $provider->calls[0]['transcript']);
        $this->assertSame(['search_products', 'get_product', 'request_human'], array_column($provider->calls[0]['tools'], 'name'));
        $this->assertStringContainsString('Acme Store', $provider->calls[0]['system']);

        // the next message carries the stored history (tool call + result) in neutral form
        $this->send('Thanks', $response->json('token'))->assertOk();
        $this->assertSame(['user', 'assistant', 'tool', 'assistant', 'user'], array_column($provider->calls[1]['transcript'], 'role'));
        Mail::assertNothingSent();
    }

    public function test_the_assistant_hands_over_and_staff_take_the_chat(): void
    {
        $this->fakeProvider(function (array $transcript, callable $runTool) {
            $result = json_decode($runTool('request_human', ['reason' => 'Wants to change an order']), true);

            return [['role' => 'assistant', 'text' => $result['tell_customer'], 'tool_calls' => []]];
        });
        LiveChat::markStaffSeen(); // someone has Admin › Live chat open

        $token = $this->send('Can I change my order?')->assertOk()
            ->assertJsonPath('status', 'waiting')->assertJsonPath('ask_email', false)->json('token');
        Mail::assertSent(LiveChatHandoff::class, fn ($mail) => $mail->reason === 'Wants to change an order');

        $conversation = ChatConversation::firstOrFail();
        $staff = $this->staff();
        $this->actingAs($staff)->postJson(route('admin.live-chat.reply', $conversation), ['body' => 'Hi, Sam here – what is the order number?'])->assertOk();
        $this->assertSame('human', $conversation->refresh()->status);

        auth()->logout();
        $poll = $this->postJson(route('live-chat.poll'), ['token' => $token, 'after' => 0])->assertOk()->assertJsonPath('status', 'human');
        $last = collect($poll->json('messages'))->last();
        $this->assertSame(['staff', 'Sam'], [$last['role'], $last['author']]);

        // while staff have the chat the assistant stays quiet
        $before = $conversation->messages()->count();
        $this->send('It is 1234', $token)->assertOk();
        $this->assertSame($before + 1, $conversation->messages()->count());

        $this->actingAs($staff)->post(route('admin.live-chat.hand-back', $conversation))->assertRedirect();
        $this->assertSame('ai', $conversation->refresh()->status);
    }

    public function test_a_customer_who_waits_too_long_leaves_an_email_that_reaches_the_inbox(): void
    {
        LiveChat::markStaffSeen();
        $token = $this->send('Anyone there?')->assertJsonPath('status', 'waiting')->assertJsonPath('ask_email', false)->json('token');

        $this->travel(ChatSettings::waitMinutes() + 1)->minutes();
        $this->postJson(route('live-chat.poll'), ['token' => $token])->assertOk()->assertJsonPath('ask_email', true);

        $this->postJson(route('live-chat.details'), ['token' => $token, 'name' => 'Ada', 'email' => 'Ada@Example.test'])->assertOk()
            ->assertJsonPath('ask_email', false);
        $submission = FormSubmission::query()->where('form', 'live_chat')->firstOrFail();
        $this->assertSame('ada@example.test', $submission->email);
        $this->assertStringContainsString('Customer: Anyone there?', $submission->message);
        Mail::assertSent(ContactSubmitted::class);

        // the honeypot and a second submission are ignored
        $this->postJson(route('live-chat.details'), ['token' => $token, 'email' => 'x@example.test', 'company' => 'spam'])->assertStatus(422);
        $this->postJson(route('live-chat.details'), ['token' => $token, 'email' => 'other@example.test'])->assertOk();
        $this->assertSame(1, FormSubmission::query()->where('form', 'live_chat')->count());
    }

    public function test_an_assistant_failure_hands_the_chat_to_staff(): void
    {
        $this->fakeProvider(fn () => throw new \RuntimeException('Claude API: overloaded'));
        $response = $this->send('Hello')->assertOk()->assertJsonPath('status', 'waiting');
        $this->assertStringContainsString('assistant isn’t available', collect($response->json('messages'))->pluck('body')->implode(' '));
        Mail::assertSent(LiveChatHandoff::class, fn ($mail) => str_contains($mail->reason, 'overloaded'));
    }

    public function test_the_reply_limit_and_new_chats_per_ip_are_capped(): void
    {
        $provider = $this->fakeProvider(fn () => [['role' => 'assistant', 'text' => 'Sure!', 'tool_calls' => []]]);
        Setting::set('live_chat.max_replies', 1, 'live_chat');
        $token = $this->send('One')->assertJsonPath('status', 'ai')->json('token');
        $this->send('Two', $token)->assertJsonPath('status', 'waiting');
        $this->assertCount(1, $provider->calls);

        ChatConversation::query()->update(['ip_address' => '127.0.0.1']);
        for ($i = 1; $i < \Pine\Commerce\Http\Controllers\LiveChatController::NEW_PER_HOUR; $i++) {
            ChatConversation::start(['ip_address' => '127.0.0.1']);
        }
        $this->send('Another new chat')->assertStatus(429);
    }

    public function test_the_transcript_drops_a_tool_call_without_results(): void
    {
        $conversation = ChatConversation::start();
        $chat = app(LiveChat::class);
        $chat->add($conversation, 'customer', 'Hi');
        $chat->add($conversation, 'assistant', '', ['tool_calls' => [['id' => 'x', 'name' => 'search_products', 'input' => ['query' => 'a']]]]);
        $chat->add($conversation, 'staff', 'Hello from Sam', ['author' => 'Sam']);
        $chat->add($conversation, 'notice', 'Sam joined');

        $this->assertSame([
            ['role' => 'user', 'text' => 'Hi'],
            ['role' => 'assistant', 'text' => '[Reply from Sam of our team] Hello from Sam', 'tool_calls' => []],
        ], $chat->transcript($conversation));
    }

    public function test_admin_pages_and_settings(): void
    {
        $this->send('Hello?');
        $conversation = ChatConversation::firstOrFail();
        $manager = $this->staff();

        $this->actingAs($manager)->get(route('admin.live-chat.index'))->assertOk()->assertSee('Visitor #'.$conversation->id);
        $this->assertTrue(LiveChat::staffOnline(), 'an open live-chat page counts as online');
        $this->actingAs($manager)->getJson(route('admin.live-chat.index', ['tab' => 'waiting']))->assertOk()->assertJsonPath('waiting', 1);
        $this->actingAs($manager)->get(route('admin.live-chat.show', $conversation))->assertOk()->assertSee('Hello?');
        $this->actingAs($manager)->getJson(route('admin.live-chat.messages', [$conversation, 'after' => 0]))->assertOk()->assertJsonPath('status', 'waiting');
        $this->actingAs($manager)->get(route('admin.live-chat.settings'))->assertForbidden();

        $admin = $this->neutralAdmin();
        $this->actingAs($admin)->get(route('admin.live-chat.settings'))->assertOk()->assertSee('API key');
        $this->actingAs($admin)->put(route('admin.live-chat.settings.update'), [
            'provider' => 'anthropic', 'api_key' => 'sk-ant-secret-123', 'model' => 'claude-haiku-5-5', 'assistant_name' => 'Ava',
            'knowledge' => 'Free UK delivery over £50.', 'wait_minutes' => 3, 'max_replies' => 20,
        ])->assertRedirect(route('admin.live-chat.settings'));
        $this->assertSame('sk-ant-secret-123', ChatSettings::apiKey());
        $this->assertStringNotContainsString('sk-ant-secret-123', (string) Setting::query()->where('key', 'live_chat.api_key')->value('value'));
        $this->assertSame(['claude-haiku-5-5', 'Ava', 3], [ChatSettings::model(), ChatSettings::assistantName(), ChatSettings::waitMinutes()]);
        $this->actingAs($admin)->get(route('admin.live-chat.settings'))->assertOk()->assertDontSee('sk-ant-secret-123')->assertSee('A key is saved');
        $this->assertStringContainsString('Free UK delivery over £50.', app(LiveChat::class)->systemPrompt());

        // an empty key keeps the saved one; OpenAI needs a model
        $this->actingAs($admin)->put(route('admin.live-chat.settings.update'), ['provider' => 'openai', 'wait_minutes' => 3, 'max_replies' => 20])
            ->assertSessionHasErrors('model');
        $this->assertSame('sk-ant-secret-123', ChatSettings::apiKey());
    }

    public function test_the_claude_provider_runs_the_tool_loop_with_fallbacks_and_thinking_passed_back(): void
    {
        $history = [];
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-5-5', 'stop_reason' => 'tool_use',
                'content' => [
                    ['type' => 'thinking', 'thinking' => '', 'signature' => 'sig-abc'],
                    ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'search_products', 'input' => ['query' => 'shirt']],
                ],
                'usage' => ['input_tokens' => 100, 'output_tokens' => 20, 'cache_read_input_tokens' => 0],
            ])),
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'id' => 'msg_2', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-5-5', 'stop_reason' => 'end_turn',
                'content' => [['type' => 'text', 'text' => 'We have the Classic Linen Shirt.']],
                'usage' => ['input_tokens' => 120, 'output_tokens' => 10, 'cache_read_input_tokens' => 90],
            ])),
        ]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        $provider = new AnthropicProvider('sk-test', 'claude-opus-5-5', new Guzzle(['handler' => $stack]));

        $ran = [];
        $result = $provider->respond('System prompt', [
            ['role' => 'user', 'text' => 'Earlier question'],
            ['role' => 'assistant', 'text' => 'Earlier answer', 'tool_calls' => []],
            ['role' => 'user', 'text' => 'Any shirts?'],
        ], ChatTools::definitions(), function (string $name, array $input) use (&$ran) {
            $ran[] = [$name, $input];

            return '{"products":[]}';
        });

        $this->assertSame([['search_products', ['query' => 'shirt']]], $ran);
        $this->assertSame('We have the Classic Linen Shirt.', end($result['entries'])['text']);
        $this->assertSame(['input' => 220, 'output' => 30, 'cache_read' => 90], $result['usage']);
        $this->assertSame(['assistant', 'tool', 'assistant'], array_column($result['entries'], 'role'));

        $this->assertCount(2, $history);
        $first = $history[0]['request'];
        $this->assertSame('Bearer sk-test', $first->getHeaderLine('Authorization'));
        $this->assertStringContainsString('server-side-fallback-2026-07-01', $first->getHeaderLine('anthropic-beta'));
        $body = json_decode((string) $first->getBody(), true);
        $this->assertSame('default', $body['fallbacks']);
        $this->assertSame(['type' => 'adaptive'], $body['thinking']);
        $this->assertSame('low', $body['output_config']['effort']);
        $this->assertSame(['type' => 'ephemeral'], $body['cache_control']);
        $this->assertSame('search_products', $body['tools'][0]['name']);
        $this->assertArrayHasKey('input_schema', $body['tools'][0]);
        $this->assertSame(['user', 'assistant', 'user'], array_column($body['messages'], 'role'));

        $second = json_decode((string) $history[1]['request']->getBody(), true);
        $assistant = $second['messages'][3];
        $this->assertSame('assistant', $assistant['role']);
        $this->assertEquals(['type' => 'thinking', 'thinking' => '', 'signature' => 'sig-abc'], $assistant['content'][0], 'thinking goes back verbatim within the reply');
        $toolResult = $second['messages'][4]['content'][0];
        $this->assertSame(['tool_result', 'toolu_1', '{"products":[]}'], [$toolResult['type'], $toolResult['tool_use_id'], $toolResult['content']]);
    }

    public function test_the_openai_provider_runs_function_calls(): void
    {
        Http::fake([OpenAiProvider::ENDPOINT => Http::sequence()
            ->push(['choices' => [['message' => ['role' => 'assistant', 'content' => null, 'tool_calls' => [
                ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'get_product', 'arguments' => '{"product_id":7}']],
            ]]]], 'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 5]])
            ->push(['choices' => [['message' => ['role' => 'assistant', 'content' => 'It is £30.']]], 'usage' => ['prompt_tokens' => 60, 'completion_tokens' => 4]]),
        ]);
        $provider = new OpenAiProvider('sk-openai', 'my-model');

        $result = $provider->respond('System', [['role' => 'user', 'text' => 'Price of 7?']], ChatTools::definitions(), fn ($name, $input) => '{"price":"£30.00"}');

        $this->assertSame('It is £30.', end($result['entries'])['text']);
        Http::assertSentCount(2);
        $requests = Http::recorded();
        $second = $requests[1][0]->data();
        $this->assertSame('my-model', $second['model']);
        $this->assertSame(['system', 'user', 'assistant', 'tool'], array_column($second['messages'], 'role'));
        $this->assertSame('call_1', $second['messages'][3]['tool_call_id']);
        $this->assertSame('Bearer sk-openai', $requests[0][0]->header('Authorization')[0]);
    }
}
