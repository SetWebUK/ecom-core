<?php

namespace Pine\Commerce\Services\LiveChat;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Pine\Commerce\Mail\ContactSubmitted;
use Pine\Commerce\Mail\LiveChatHandoff;
use Pine\Commerce\Models\ChatConversation;
use Pine\Commerce\Models\ChatMessage;
use Pine\Commerce\Models\FormSubmission;
use Pine\Commerce\Services\LiveChat\Providers\AnthropicProvider;
use Pine\Commerce\Services\LiveChat\Providers\ChatProvider;
use Pine\Commerce\Services\LiveChat\Providers\OpenAiProvider;
use Pine\Commerce\Support\Features;
use Throwable;

/**
 * Storefront live chat (feature "live_chat"). Without an AI provider every chat goes to staff; with one, the assistant
 * answers from the catalogue until the customer (or the assistant) asks for a person:
 *
 *   ai ──handOff()──▶ waiting ──staffReply()──▶ human ──handBack()──▶ ai          (any) ──close()──▶ closed
 *
 * Waiting: staff get one email; a member of staff with Admin › Live chat open counts as "online". When nobody is
 * online, or nobody has answered after ChatSettings::waitMinutes(), the widget asks for the customer's email, and the
 * details + transcript become an Inbox › Form submissions message (form "live_chat"). No cron or queue is needed:
 * the wait is checked on each widget poll.
 */
class LiveChat
{
    public const STAFF_ONLINE_SECONDS = 120;

    public const MAX_MESSAGE_LENGTH = 1000;

    public static function enabled(): bool
    {
        return Features::enabled('live_chat', false);
    }

    public static function markStaffSeen(): void
    {
        Cache::put('live_chat.staff_seen', time(), self::STAFF_ONLINE_SECONDS);
    }

    public static function staffOnline(): bool
    {
        return (time() - (int) Cache::get('live_chat.staff_seen', 0)) < self::STAFF_ONLINE_SECONDS;
    }

    // ------------------------------------------------------------------ customer side

    public function customerMessage(ChatConversation $conversation, string $text): void
    {
        $text = Str::limit(trim($text), self::MAX_MESSAGE_LENGTH, '');
        if ($text === '') {
            return;
        }
        if ($conversation->status === 'closed') {
            $conversation->status = 'ai';
        }
        $this->add($conversation, 'customer', $text);

        if ($conversation->status !== 'ai') {
            return; // staff will answer
        }
        if (! ChatSettings::aiReady()) {
            $this->handOff($conversation, 'New chat', true);

            return;
        }
        if ($conversation->ai_replies >= ChatSettings::maxReplies()) {
            $this->handOff($conversation, 'The assistant reached its reply limit for this chat', true);

            return;
        }

        $lock = Cache::lock('live_chat.reply.'.$conversation->id, 120);
        if (! $lock->get()) {
            return; // a reply to an earlier message is still being written; it will see this one next time
        }
        try {
            $this->assistantReply($conversation);
        } finally {
            $lock->release();
        }
    }

    /**
     * Hand the chat to staff. Returns whether staff are online. $announce: add the customer-facing notice here (the
     * assistant's tool call lets the assistant tell the customer itself).
     */
    public function handOff(ChatConversation $conversation, string $reason, bool $announce): bool
    {
        $online = self::staffOnline();
        if (in_array($conversation->status, ['waiting', 'human'], true)) {
            return $online;
        }
        $conversation->forceFill(['status' => 'waiting', 'handoff_at' => now()]);
        if (! $online && ! $conversation->email) {
            $conversation->email_requested_at = now();
        }
        $conversation->save();

        if ($announce) {
            $this->add($conversation, 'notice', $online
                ? 'A member of our team will be with you shortly.'
                : ($conversation->email
                    ? 'Our team isn’t online right now – we’ll reply to '.$conversation->email.' as soon as we can.'
                    : 'Our team isn’t online right now. Leave your email below and we’ll get back to you.'));
        }
        $this->notifyStaff($conversation, $reason);

        return $online;
    }

    /** Widget poll: nobody answered in time → ask for an email address (once). */
    public function checkWaiting(ChatConversation $conversation): void
    {
        if ($conversation->status !== 'waiting' || $conversation->email_requested_at || $conversation->email || ! $conversation->handoff_at
            || $conversation->handoff_at->gt(now()->subMinutes(ChatSettings::waitMinutes()))) {
            return;
        }
        $conversation->forceFill(['email_requested_at' => now()])->save();
        $this->add($conversation, 'notice', 'Sorry for the wait – our team is busy. Leave your email below and we’ll reply as soon as we can.');
    }

    /** The customer left their details: Inbox message + store email, and the chat stays open for staff. */
    public function leaveDetails(ChatConversation $conversation, string $name, string $email, string $ip): void
    {
        $conversation->forceFill(['name' => Str::limit(trim($name), 120, '') ?: $conversation->name, 'email' => strtolower(trim($email))])->save();
        $transcript = $this->transcriptText($conversation);

        $submission = FormSubmission::create([
            'form' => 'live_chat',
            'name' => $conversation->name ?: $conversation->email,
            'email' => $conversation->email,
            'subject' => 'Live chat',
            'message' => $transcript,
            'data' => ['conversation_id' => $conversation->id, 'page_url' => $conversation->page_url],
            'ip_address' => $ip,
        ]);
        $conversation->forceFill(['form_submission_id' => $submission->id])->save();
        $this->add($conversation, 'notice', 'Thanks – we’ll reply to '.$conversation->email.'. You can keep this window open in case someone joins.');

        try {
            Mail::to(setting('store.email', config('mail.from.address')))->send(new ContactSubmitted($submission));
        } catch (Throwable $e) {
            Log::error('Live chat details email failed', ['conversation' => $conversation->id, 'error' => $e->getMessage()]);
        }
    }

    // ------------------------------------------------------------------ staff side

    public function staffReply(ChatConversation $conversation, Authenticatable $staff, string $text): ChatMessage
    {
        $first = $conversation->status !== 'human';
        $conversation->forceFill(['status' => 'human', 'staff_id' => $staff->getAuthIdentifier(), 'staff_read_at' => now()]);
        $name = trim((string) ($staff->first_name ?? '')) ?: Str::before((string) ($staff->name ?? 'Team'), ' ');
        if ($first) {
            $this->add($conversation, 'notice', $name.' from our team joined the chat.');
        }

        return $this->add($conversation, 'staff', Str::limit(trim($text), 4000, ''), ['author' => $name], (int) $staff->getAuthIdentifier());
    }

    public function handBack(ChatConversation $conversation): void
    {
        if ($conversation->status === 'ai') {
            return;
        }
        $conversation->forceFill(['status' => 'ai', 'handoff_at' => null, 'email_requested_at' => null]);
        $this->add($conversation, 'notice', ChatSettings::aiReady()
            ? 'You’re chatting with '.ChatSettings::assistantName().' again.'
            : 'Our team has finished here – send a message any time.');
    }

    public function close(ChatConversation $conversation): void
    {
        if ($conversation->status === 'closed') {
            return;
        }
        $conversation->forceFill(['status' => 'closed']);
        $this->add($conversation, 'notice', 'This chat has ended. Send a message if you need anything else.');
    }

    // ------------------------------------------------------------------ messages

    /** @return Collection<int, ChatMessage> */
    public function visibleMessages(ChatConversation $conversation, int $afterId = 0): Collection
    {
        return $conversation->messages()->where('id', '>', $afterId)->whereIn('role', ChatMessage::VISIBLE)
            ->limit(200)->get()->filter->isVisible()->values();
    }

    public function add(ChatConversation $conversation, string $role, string $body, ?array $meta = null, ?int $userId = null): ChatMessage
    {
        $message = $conversation->messages()->create(['role' => $role, 'body' => $body, 'meta' => $meta, 'user_id' => $userId, 'created_at' => now()]);
        if (in_array($role, ['customer', 'staff', 'assistant'], true)) {
            $conversation->last_message_at = now();
        }
        if ($role === 'customer') {
            $conversation->staff_read_at = null;
        }
        $conversation->save();

        return $message;
    }

    /** Plain-text transcript (Inbox message, staff email). */
    public function transcriptText(ChatConversation $conversation): string
    {
        return $conversation->messages()->whereIn('role', ['customer', 'assistant', 'staff'])->get()->filter->isVisible()
            ->map(fn (ChatMessage $m) => match ($m->role) {
                'customer' => 'Customer',
                'staff' => ($m->meta['author'] ?? 'Staff'),
                default => ChatSettings::assistantName(),
            }.': '.$m->body)->implode("\n\n");
    }

    // ------------------------------------------------------------------ assistant

    protected function assistantReply(ChatConversation $conversation): void
    {
        $tools = new ChatTools($conversation, $this);
        try {
            $result = $this->provider()->respond($this->systemPrompt(), $this->transcript($conversation), ChatTools::definitions(),
                fn (string $name, array $input) => $tools->run($name, $input));
        } catch (Throwable $e) {
            Log::warning('Live chat assistant failed', ['conversation' => $conversation->id, 'error' => $e->getMessage()]);
            $conversation->refresh();
            $this->add($conversation, 'notice', 'Sorry, our assistant isn’t available right now.');
            $this->handOff($conversation, 'The assistant could not answer ('.Str::limit($e->getMessage(), 120).')', true);

            return;
        }

        $conversation->refresh(); // request_human may have changed the status meanwhile
        $last = count($result['entries']) - 1;
        foreach ($result['entries'] as $i => $entry) {
            if ($entry['role'] === 'tool') {
                $this->add($conversation, 'tool', '', ['results' => $entry['results']]);
            } else {
                $meta = ['tool_calls' => $entry['tool_calls'] ?? []];
                if ($i === $last) {
                    $meta += ['provider' => ChatSettings::provider(), 'model' => $result['model'], 'usage' => $result['usage']];
                }
                $this->add($conversation, 'assistant', (string) ($entry['text'] ?? ''), $meta);
            }
        }
        $conversation->increment('ai_replies');
    }

    protected function provider(): ChatProvider
    {
        if (app()->bound(ChatProvider::class)) {
            return app(ChatProvider::class); // tests, or a client app with its own provider
        }

        return match (ChatSettings::provider()) {
            'anthropic' => new AnthropicProvider(ChatSettings::apiKey(), ChatSettings::model()),
            'openai' => new OpenAiProvider(ChatSettings::apiKey(), ChatSettings::model()),
            default => throw new \RuntimeException('No AI provider configured.'),
        };
    }

    /**
     * The stored conversation as a provider-neutral transcript. Staff replies reach the model as labelled assistant
     * turns; notices are left out; a tool call whose results were never stored is dropped (the API needs both halves).
     */
    public function transcript(ChatConversation $conversation): array
    {
        $rows = $conversation->messages()->whereIn('role', ['customer', 'assistant', 'staff', 'tool'])->orderBy('id')->get()->values();
        $out = [];
        foreach ($rows as $i => $m) {
            switch ($m->role) {
                case 'customer':
                    $out[] = ['role' => 'user', 'text' => (string) $m->body];
                    break;
                case 'staff':
                    $out[] = ['role' => 'assistant', 'text' => '[Reply from '.($m->meta['author'] ?? 'a member of staff').' of our team] '.$m->body, 'tool_calls' => []];
                    break;
                case 'assistant':
                    $calls = (array) ($m->meta['tool_calls'] ?? []);
                    $next = $rows[$i + 1] ?? null;
                    if ($calls && (! $next || $next->role !== 'tool')) {
                        $calls = [];
                    }
                    if (trim((string) $m->body) !== '' || $calls) {
                        $out[] = ['role' => 'assistant', 'text' => (string) $m->body, 'tool_calls' => $calls];
                    }
                    break;
                case 'tool':
                    $prev = end($out);
                    if ($prev && $prev['role'] === 'assistant' && ! empty($prev['tool_calls'])) {
                        $out[] = ['role' => 'tool', 'results' => (array) ($m->meta['results'] ?? [])];
                    }
                    break;
            }
        }

        return $out;
    }

    /** Stable for a shop (no dates or per-visitor data), so providers can cache it between messages. */
    public function systemPrompt(): string
    {
        $store = (string) setting('store.name', config('app.name'));
        $knowledge = ChatSettings::knowledge() ?: 'No store policies have been added yet.';

        return <<<PROMPT
You are {$this->assistantName()}, the live-chat assistant on the website of {$store} ({$this->siteUrl()}). You help shoppers find the right product and answer questions about the shop.

How to help:
- Use search_products and get_product for every product, price, stock or specification fact. Never invent products, prices, stock levels, specifications, delivery times or policies.
- When you recommend a product, give its name, price and link (the url from the tool).
- Delivery, returns, warranty, payment options, opening hours and contact details: use only the store information below. If it doesn't cover the question, offer to put the customer through to the team.
- You cannot see orders, payments or customer accounts. For those, for faults, returns or complaints, when the customer asks for a person, or when you cannot help, call request_human with a one-line reason, then tell the customer what its result says.
- Never ask for card details, passwords or other sensitive information.
- Only discuss this shop and its products; politely steer other topics back.
- Turns starting with "[Reply from … of our team]" were written by the shop's staff.

Style: friendly, short (one to four sentences, or a short list), plain text without markdown headings or tables, British English, prices exactly as the tools give them.

Store information (from the shop):
<store_information>
{$knowledge}
</store_information>
PROMPT;
    }

    protected function assistantName(): string
    {
        return ChatSettings::assistantName();
    }

    protected function siteUrl(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    protected function notifyStaff(ChatConversation $conversation, string $reason): void
    {
        if ($conversation->staff_notified_at) {
            return;
        }
        $conversation->forceFill(['staff_notified_at' => now()])->save();
        try {
            Mail::to(ChatSettings::notifyEmail())->send(new LiveChatHandoff($conversation, $reason, $this->transcriptText($conversation)));
        } catch (Throwable $e) {
            Log::error('Live chat staff email failed', ['conversation' => $conversation->id, 'error' => $e->getMessage()]);
        }
    }
}
