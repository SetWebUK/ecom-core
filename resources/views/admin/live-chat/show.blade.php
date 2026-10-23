{{-- One live chat: the transcript (new messages every 3 s), reply box, hand back to the assistant, close. --}}
@extends('commerce::admin.layouts.app')

@section('title', 'Chat with '.$conversation->displayName())

@section('content')
    <x-admin.page-header :title="$conversation->displayName()" :back="route('admin.live-chat.index')" back-label="Back to live chat">
        <x-slot:meta>
            Started <x-admin.time :value="$conversation->created_at" format="datetime" />
            · <span data-chat-status>{{ $conversation->statusLabel() }}</span>
        </x-slot:meta>
        <x-slot:actions>
            @if (in_array($conversation->status, ['waiting', 'human'], true))
                <form method="POST" action="{{ route('admin.live-chat.hand-back', $conversation) }}">@csrf
                    <x-admin.button type="submit" icon="sparkles">{{ $aiReady ? 'Hand back to assistant' : 'Release chat' }}</x-admin.button>
                </form>
            @endif
            @if ($conversation->status !== 'closed')
                <form method="POST" action="{{ route('admin.live-chat.close', $conversation) }}">@csrf
                    <x-admin.button type="submit" icon="check">Close chat</x-admin.button>
                </form>
            @endif
            <form method="POST" action="{{ route('admin.live-chat.destroy', $conversation) }}">@csrf @method('DELETE')
                <x-admin.button type="submit" variant="ghost-danger" icon="trash" data-confirm="The conversation is removed for good." data-confirm-title="Delete this chat?" data-confirm-button="Delete chat">Delete</x-admin.button>
            </form>
        </x-slot:actions>
    </x-admin.page-header>

    <style>
        .chat-log{display:flex;flex-direction:column;gap:10px;max-height:60vh;overflow-y:auto;padding:4px}
        .chat-line{max-width:80%;padding:8px 12px;border-radius:10px;background:var(--color-surface-muted,#f3f4f6)}
        .chat-line--customer{align-self:flex-start;background:#fff;border:1px solid var(--color-border,#e5e7eb)}
        .chat-line--staff{align-self:flex-end;background:#e0ecff}
        .chat-line--assistant{align-self:flex-end}
        .chat-line--notice{align-self:center;background:none;color:var(--color-text-muted,#6b7280);font-size:13px;padding:0}
        .chat-line__who{font-size:12px;font-weight:600;margin-bottom:2px}
        .chat-line__lookup{font-size:12px;margin-bottom:2px}
        .chat-line__body{white-space:pre-wrap;overflow-wrap:anywhere}
    </style>

    <div class="layout">
        <div class="layout__main">
            <x-admin.card title="Conversation">
                <div x-data="liveChatThread({{ (int) $messages->max('id') }})">
                    <div class="chat-log" x-ref="log">
                        @foreach ($messages as $message)
                            @include('commerce::admin.live-chat.partials.message', ['message' => $message])
                        @endforeach
                    </div>
                    <form class="mt-4" method="POST" action="{{ route('admin.live-chat.reply', $conversation) }}" @submit.prevent="send($event)">
                        @csrf
                        <x-admin.textarea name="body" label="Reply" rows="3" :help="$conversation->status === 'ai' && $aiReady ? 'Replying takes the chat over from the assistant until you hand it back.' : 'Enter sends, Shift+Enter adds a line.'" x-ref="body" @keydown.enter="if (! $event.shiftKey) { $event.preventDefault(); send($event); }" />
                        <div class="mt-2"><x-admin.button type="submit" variant="primary" icon="paper-airplane" x-bind:disabled="busy">Send</x-admin.button></div>
                    </form>
                </div>
            </x-admin.card>
        </div>
        <div class="layout__side">
            <x-admin.card title="Customer">
                <dl class="kv">
                    <dt>Name</dt><dd>{{ $conversation->name ?: '—' }}</dd>
                    <dt>Email</dt><dd>@if ($conversation->email)<a href="mailto:{{ $conversation->email }}">{{ $conversation->email }}</a>@else — @endif</dd>
                    @if ($conversation->user)
                        <dt>Account</dt><dd>@if (Route::has('admin.customers.show'))<a href="{{ route('admin.customers.show', $conversation->user) }}">{{ $conversation->user->name }}</a>@else{{ $conversation->user->name }}@endif</dd>
                    @endif
                    @if ($conversation->page_url)
                        <dt>Started on</dt><dd><a href="{{ $conversation->page_url }}" target="_blank" rel="noopener" class="break">{{ $conversation->page_url }}</a></dd>
                    @endif
                    @if ($conversation->form_submission_id && Route::has('admin.form-submissions.show'))
                        <dt>Left details</dt><dd><a href="{{ route('admin.form-submissions.show', $conversation->form_submission_id) }}">Inbox message</a></dd>
                    @endif
                    <dt>Assistant replies</dt><dd>{{ $conversation->ai_replies }}</dd>
                </dl>
            </x-admin.card>
        </div>
    </div>
@endsection

@push('scripts')
<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('liveChatThread', (lastId) => ({
        lastId, busy: false,
        init() { this.scroll(); this.timer = setInterval(() => this.poll(), 3000); },
        destroy() { clearInterval(this.timer); },
        scroll() { this.$nextTick(() => { this.$refs.log.scrollTop = this.$refs.log.scrollHeight; }); },
        poll() {
            if (document.hidden) return;
            fetch(@json(route('admin.live-chat.messages', $conversation)) + '?after=' + this.lastId, {headers: {'Accept': 'application/json'}, credentials: 'same-origin'})
                .then(r => r.ok ? r.json() : null).then(d => {
                    if (!d) return;
                    if (d.html) { this.$refs.log.insertAdjacentHTML('beforeend', d.html); this.scroll(); }
                    this.lastId = Math.max(this.lastId, d.last_id);
                    document.querySelectorAll('[data-chat-status]').forEach(el => el.textContent = d.status_label);
                }).catch(() => {});
        },
        send(event) {
            const box = this.$root.querySelector('textarea[name="body"]');
            const body = box.value.trim();
            if (!body || this.busy) return;
            this.busy = true;
            fetch(@json(route('admin.live-chat.reply', $conversation)), {method: 'POST', credentials: 'same-origin',
                headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content},
                body: JSON.stringify({body})})
                .then(r => { if (!r.ok) throw new Error(); box.value = ''; this.poll(); })
                .catch(() => alert('The reply could not be sent. Please try again.'))
                .finally(() => { this.busy = false; box.focus(); });
        },
    }));
});
</script>
@endpush
