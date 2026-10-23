<?php

namespace Pine\Commerce\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Pine\Commerce\Models\ChatConversation;
use Pine\Commerce\Services\LiveChat\ChatSettings;
use Pine\Commerce\Services\LiveChat\LiveChat;

/**
 * The storefront chat widget's JSON endpoints (feature "live_chat"). Every call is a POST carrying the conversation
 * token in the body (never in the URL, so it stays out of access logs); the widget keeps the token in localStorage.
 * A conversation is only created with the visitor's first message.
 */
class LiveChatController extends Controller
{
    /** New conversations per IP address and hour (each can cost AI replies). */
    public const NEW_PER_HOUR = 10;

    public function __construct(protected LiveChat $chat) {}

    public function send(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['nullable', 'string', 'max:64'],
            'body' => ['required', 'string', 'max:'.LiveChat::MAX_MESSAGE_LENGTH],
            'page_url' => ['nullable', 'string', 'max:500'],
            'after' => ['nullable', 'integer', 'min:0'],
        ]);
        $conversation = $this->find($data['token'] ?? null);
        if (! $conversation && ChatConversation::query()->where('ip_address', $request->ip())->where('created_at', '>', now()->subHour())->count() >= self::NEW_PER_HOUR) {
            return response()->json(['message' => 'Too many new chats from your connection – please try again later.'], 429);
        }
        $conversation ??= ChatConversation::start([
            'user_id' => $request->user()?->getAuthIdentifier(),
            'name' => $request->user()?->name,
            'email' => $request->user()?->email,
            'page_url' => $this->sameHost((string) ($data['page_url'] ?? '')),
            'ip_address' => $request->ip(),
        ]);
        $this->chat->customerMessage($conversation, $data['body']);

        return $this->state($conversation->refresh(), (int) ($data['after'] ?? 0));
    }

    public function poll(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:64'], 'after' => ['nullable', 'integer', 'min:0']]);
        $conversation = $this->find($data['token']);
        if (! $conversation) {
            return response()->json(['gone' => true]);
        }
        $this->chat->checkWaiting($conversation);

        return $this->state($conversation, (int) ($data['after'] ?? 0));
    }

    public function handoff(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:64'], 'after' => ['nullable', 'integer', 'min:0']]);
        $conversation = $this->find($data['token']) ?? abort(404);
        $this->chat->handOff($conversation, 'The customer asked to talk to a person', true);

        return $this->state($conversation->refresh(), (int) ($data['after'] ?? 0));
    }

    public function details(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:64'],
            'name' => ['nullable', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190'],
            'after' => ['nullable', 'integer', 'min:0'],
            'company' => ['nullable', 'max:0'], // honeypot
        ]);
        $conversation = $this->find($data['token']) ?? abort(404);
        if (! $conversation->form_submission_id) {
            $this->chat->leaveDetails($conversation, (string) ($data['name'] ?? ''), $data['email'], (string) $request->ip());
        }

        return $this->state($conversation->refresh(), (int) ($data['after'] ?? 0));
    }

    protected function find(?string $token): ?ChatConversation
    {
        return $token ? ChatConversation::query()->where('token', $token)->first() : null;
    }

    protected function state(ChatConversation $conversation, int $after): JsonResponse
    {
        return response()->json([
            'token' => $conversation->token,
            'status' => $conversation->status,
            'ask_email' => (bool) $conversation->email_requested_at && ! $conversation->form_submission_id,
            'can_handoff' => $conversation->status === 'ai' && ChatSettings::aiReady(),
            'messages' => $this->chat->visibleMessages($conversation, $after)->map->toPayload()->values(),
        ]);
    }

    protected function sameHost(string $url): ?string
    {
        return $url !== '' && parse_url($url, PHP_URL_HOST) === request()->getHost() ? mb_substr($url, 0, 500) : null;
    }
}
