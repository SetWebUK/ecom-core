{{-- One message on the conversation page (also rendered for the 3-second poll). Data: $message (ChatMessage) --}}
@php
    $meta = (array) ($message->meta ?? []);
    $lookups = collect($meta['tool_calls'] ?? [])->map(fn ($c) => match ($c['name'] ?? '') {
        'search_products' => 'searched products for “'.($c['input']['query'] ?? '').'”',
        'get_product' => 'looked up product #'.($c['input']['product_id'] ?? '?'),
        'request_human' => 'asked for a person: '.($c['input']['reason'] ?? ''),
        default => $c['name'] ?? 'lookup',
    });
@endphp
@if ($message->role === 'tool')
    {{-- lookup results stay hidden; the call above them says what was looked up --}}
@elseif ($message->role === 'notice')
    <div class="chat-line chat-line--notice" data-id="{{ $message->id }}">{{ $message->body }}</div>
@elseif ($message->role !== 'assistant' || trim((string) $message->body) !== '' || $lookups->isNotEmpty())
    <div @class(['chat-line', 'chat-line--'.$message->role]) data-id="{{ $message->id }}">
        <div class="chat-line__who">
            {{ match ($message->role) { 'customer' => 'Customer', 'staff' => ($meta['author'] ?? 'Team'), default => 'Assistant' } }}
            <span class="text-muted"> · <x-admin.time :value="$message->created_at" format="time" /></span>
            @if (! empty($meta['model']))<span class="text-muted"> · {{ $meta['model'] }}</span>@endif
        </div>
        @if ($lookups->isNotEmpty())
            <div class="chat-line__lookup text-muted">🔎 {{ $lookups->implode('; ') }}</div>
        @endif
        @if (trim((string) $message->body) !== '')
            <div class="chat-line__body">{{ $message->body }}</div>
        @endif
    </div>
@endif
