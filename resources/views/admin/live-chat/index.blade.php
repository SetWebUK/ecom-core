{{-- Admin › Live chat: conversations, waiting ones first; the list refreshes itself every 10 s (and keeps staff "online"). --}}
@extends('commerce::admin.layouts.app', ['width' => 'wide'])

@section('title', 'Live chat')

@section('content')
    <x-admin.page-header title="Live chat" subtitle="Chats from the shop’s chat widget. While this page is open, customers see that the team is online.">
        <x-slot:actions>
            @if (auth()->user()?->isAdmin())
                <x-admin.button :href="route('admin.live-chat.settings')" icon="cog-6-tooth">Settings</x-admin.button>
            @endif
        </x-slot:actions>
    </x-admin.page-header>

    @unless ($aiReady)
        <x-admin.callout type="info" title="No AI assistant">
            Every chat comes straight to the team.
            @if (auth()->user()?->isAdmin())
                Add a Claude or OpenAI key in <a href="{{ route('admin.live-chat.settings') }}">Settings</a> to let the assistant answer product questions first.
            @endif
        </x-admin.callout>
    @endunless

    <x-admin.card flush>
        <x-admin.status-tabs param="tab" default="open" :current="$tab" :tabs="collect($tabs)->map(fn ($label, $key) => ['label' => $label, 'count' => $key === 'waiting' ? $counts['waiting'] : null])->all()" />
        <div x-data="liveChatList('{{ route('admin.live-chat.index', ['tab' => $tab, 'page' => $conversations->currentPage()]) }}')">
            <div x-ref="rows">
                @include('commerce::admin.live-chat.partials.rows', ['conversations' => $conversations])
            </div>
        </div>
        <x-admin.pagination :paginator="$conversations" />
    </x-admin.card>
@endsection

@push('scripts')
<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('liveChatList', (url) => ({
        init() { this.timer = setInterval(() => this.refresh(), 10000); },
        destroy() { clearInterval(this.timer); },
        refresh() {
            if (document.hidden) return;
            fetch(url, {headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}, credentials: 'same-origin'})
                .then(r => r.ok ? r.json() : null).then(d => { if (d) this.$refs.rows.innerHTML = d.html; }).catch(() => {});
        },
    }));
});
</script>
@endpush
