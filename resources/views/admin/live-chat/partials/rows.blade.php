{{-- Live chat list rows (also returned as JSON html by the 10-second refresh). Data: $conversations (paginator) --}}
@if ($conversations->isEmpty())
    <x-admin.empty icon="chat-bubble-left-right" title="No chats here" description="New chats from the shop’s chat widget appear here." size="sm" />
@else
    <div class="table-wrap">
        <table class="table table--stack">
            <thead>
                <tr><th>Customer</th><th>Last message</th><th>Status</th><th class="nowrap">Updated</th></tr>
            </thead>
            <tbody>
                @foreach ($conversations as $conversation)
                    @php($last = $conversation->messages->first())
                    <tr @class(['is-unread' => $conversation->hasUnread() && $conversation->status !== 'ai'])>
                        <td class="stack-title" style="max-width:240px">
                            <a href="{{ route('admin.live-chat.show', $conversation) }}" class="row-link">
                                @if ($conversation->hasUnread() && $conversation->status !== 'ai')<span class="unread-dot" aria-hidden="true"></span>@endif{{ $conversation->displayName() }}
                            </a>
                            <div class="cell-sub truncate">{{ $conversation->customer_messages_count }} {{ \Illuminate\Support\Str::plural('message', $conversation->customer_messages_count) }}@if ($conversation->email && $conversation->email !== $conversation->displayName()) · {{ $conversation->email }}@endif</div>
                        </td>
                        <td data-label="Last message">
                            <div class="truncate" style="max-width:520px">
                                @if ($last)<span class="text-muted">{{ ['customer' => 'Customer', 'staff' => 'Team', 'assistant' => 'Assistant'][$last->role] ?? '' }}:</span> {{ \Illuminate\Support\Str::limit(preg_replace('/\s+/', ' ', (string) $last->body), 140) }}@endif
                            </div>
                        </td>
                        <td data-label="Status">
                            <x-admin.badge :color="['waiting' => 'attention', 'human' => 'info', 'ai' => 'gray', 'closed' => 'outline'][$conversation->status] ?? 'gray'" :dot="$conversation->status === 'waiting'">{{ $conversation->statusLabel() }}</x-admin.badge>
                        </td>
                        <td class="nowrap text-muted" data-label="Updated"><x-admin.time :value="$conversation->last_message_at ?? $conversation->updated_at" /></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
