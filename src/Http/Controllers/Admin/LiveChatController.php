<?php

namespace Pine\Commerce\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Pine\Commerce\Http\Controllers\Controller;
use Pine\Commerce\Models\ChatConversation;
use Pine\Commerce\Models\ChatMessage;
use Pine\Commerce\Services\LiveChat\ChatSettings;
use Pine\Commerce\Services\LiveChat\LiveChat;
use Pine\Commerce\Services\LiveChat\Providers\AnthropicProvider;
use Pine\Commerce\Services\LiveChat\Providers\OpenAiProvider;
use Throwable;

/**
 * Admin › Live chat (feature "live_chat"): conversations (waiting ones first), one conversation with live replies, and
 * the settings (administrators only – they hold the AI provider's API key). Having any of these pages open marks
 * staff as online for the widget (LiveChat::markStaffSeen, refreshed by the pages' polling).
 */
class LiveChatController extends Controller
{
    public const TABS = ['waiting' => 'Waiting', 'open' => 'Open', 'closed' => 'Closed', 'all' => 'All'];

    public function __construct(protected LiveChat $chat) {}

    public function index(Request $request): View|JsonResponse
    {
        LiveChat::markStaffSeen();
        $tab = array_key_exists($request->query('tab'), self::TABS) ? $request->query('tab') : 'open';
        $query = ChatConversation::query()->whereHas('messages', fn ($q) => $q->where('role', 'customer'))
            ->withCount(['messages as customer_messages_count' => fn ($q) => $q->where('role', 'customer')])
            ->with(['messages' => fn ($q) => $q->whereIn('role', ['customer', 'assistant', 'staff'])->where('body', '!=', '')->latest('id')->limit(1)]);
        match ($tab) {
            'waiting' => $query->where('status', 'waiting'),
            'open' => $query->where('status', '!=', 'closed'),
            'closed' => $query->where('status', 'closed'),
            default => null,
        };
        $conversations = $query->orderByRaw("CASE WHEN status = 'waiting' THEN 0 ELSE 1 END")->orderByDesc('last_message_at')
            ->paginate(30)->withQueryString();

        if ($request->wantsJson()) {
            return response()->json([
                'html' => view('commerce::admin.live-chat.partials.rows', ['conversations' => $conversations])->render(),
                'waiting' => self::waitingCount(),
            ]);
        }

        return view('commerce::admin.live-chat.index', [
            'conversations' => $conversations, 'tab' => $tab, 'tabs' => self::TABS,
            'counts' => ['waiting' => self::waitingCount()],
            'aiReady' => ChatSettings::aiReady(),
        ]);
    }

    public function show(ChatConversation $conversation): View
    {
        LiveChat::markStaffSeen();
        $conversation->forceFill(['staff_read_at' => now()])->save();

        return view('commerce::admin.live-chat.show', [
            'conversation' => $conversation->load(['user', 'staff']),
            'messages' => $conversation->messages()->get(),
            'aiReady' => ChatSettings::aiReady(),
        ]);
    }

    /** Polled by the conversation page: new messages after ?after=, current status. */
    public function messages(Request $request, ChatConversation $conversation): JsonResponse
    {
        LiveChat::markStaffSeen();
        $after = max(0, (int) $request->query('after', 0));
        $new = $conversation->messages()->where('id', '>', $after)->get();
        if ($new->contains('role', 'customer')) {
            $conversation->forceFill(['staff_read_at' => now()])->save();
        }

        return response()->json([
            'status' => $conversation->status,
            'status_label' => $conversation->statusLabel(),
            'html' => $new->map(fn (ChatMessage $m) => view('commerce::admin.live-chat.partials.message', ['message' => $m])->render())->implode(''),
            'last_id' => (int) ($new->max('id') ?? $after),
        ]);
    }

    public function reply(Request $request, ChatConversation $conversation): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:4000']]);
        $this->chat->staffReply($conversation, $request->user(), $data['body']);

        return $request->wantsJson() ? response()->json(['ok' => true]) : back();
    }

    public function handBack(Request $request, ChatConversation $conversation): RedirectResponse
    {
        $this->chat->handBack($conversation);

        return back()->with('status', ChatSettings::aiReady() ? 'The assistant answers this chat again.' : 'Chat released.');
    }

    public function close(Request $request, ChatConversation $conversation): RedirectResponse
    {
        $this->chat->close($conversation);

        return back()->with('status', 'Chat closed.');
    }

    public function destroy(ChatConversation $conversation): RedirectResponse
    {
        $conversation->delete();

        return redirect()->route('admin.live-chat.index')->with('status', 'Chat deleted.');
    }

    // ------------------------------------------------------------------ settings (administrators)

    public function settings(): View
    {
        return view('commerce::admin.live-chat.settings', [
            'form' => ChatSettings::form(),
            'providers' => ChatSettings::PROVIDERS,
            'anthropicModels' => ChatSettings::ANTHROPIC_MODELS,
        ]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'provider' => ['required', Rule::in(array_keys(ChatSettings::PROVIDERS))],
            'api_key' => ['nullable', 'string', 'max:500'],
            'api_key_clear' => ['nullable', 'boolean'],
            'model' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9._:\-\/]+$/', Rule::requiredIf(fn () => $request->input('provider') === 'openai')],
            'assistant_name' => ['nullable', 'string', 'max:60'],
            'greeting' => ['nullable', 'string', 'max:500'],
            'knowledge' => ['nullable', 'string', 'max:20000'],
            'wait_minutes' => ['required', 'integer', 'min:1', 'max:120'],
            'notify_email' => ['nullable', 'email', 'max:190'],
            'max_replies' => ['required', 'integer', 'min:1', 'max:500'],
        ], ['model.required_if' => 'Enter the OpenAI model to use.']);
        ChatSettings::save($data);

        return redirect()->route('admin.live-chat.settings')->with('status', 'Live chat settings saved.');
    }

    /** One tiny request with the saved settings, so the shop knows the key and model work. */
    public function test(): RedirectResponse
    {
        if (! ChatSettings::aiReady()) {
            return back()->with('error', 'Choose a provider, save an API key'.(ChatSettings::provider() === 'openai' ? ' and a model' : '').' first.');
        }
        $provider = ChatSettings::provider() === 'openai'
            ? new OpenAiProvider(ChatSettings::apiKey(), ChatSettings::model())
            : new AnthropicProvider(ChatSettings::apiKey(), ChatSettings::model());
        try {
            $result = $provider->respond('You are testing a connection. Reply with the single word OK.', [['role' => 'user', 'text' => 'Connection test']], [], fn () => '');
            $text = trim((string) (collect($result['entries'])->last()['text'] ?? ''));

            return back()->with('status', 'Connected to '.$result['model'].($text !== '' ? ' – it replied “'.\Illuminate\Support\Str::limit($text, 60).'”.' : '.'));
        } catch (Throwable $e) {
            return back()->with('error', 'The connection failed: '.\Illuminate\Support\Str::limit($e->getMessage(), 300));
        }
    }

    public static function waitingCount(): int
    {
        try {
            return ChatConversation::query()->where('status', 'waiting')->count();
        } catch (Throwable) {
            return 0; // not migrated yet
        }
    }
}
