{{-- Admin › Live chat › Settings (administrators): AI provider + key (encrypted, never shown), assistant, hand-over. --}}
@extends('commerce::admin.layouts.app')

@section('title', 'Live chat settings')

@section('content')
    <x-admin.page-header title="Live chat settings" :back="route('admin.live-chat.index')" back-label="Back to live chat">
        <x-slot:actions>
            <form method="POST" action="{{ route('admin.live-chat.settings.test') }}">@csrf
                <x-admin.button type="submit" icon="signal">Test connection</x-admin.button>
            </form>
        </x-slot:actions>
    </x-admin.page-header>

    <x-admin.form :action="route('admin.live-chat.settings.update')" method="PUT" dirty>
        <div x-data="{ provider: @js(old('provider', $form['provider'])) }">
            <x-admin.card title="AI assistant" subtitle="The assistant answers product questions from your catalogue (names, prices, stock, specifications) and hands the chat to your team when asked. Without one, every chat goes straight to the team.">
                <x-admin.select name="provider" label="Provider" :options="$providers" :value="$form['provider']" x-model="provider" />

                <div x-show="provider !== 'none'" x-cloak>
                    <x-admin.input name="api_key" type="password" label="API key" autocomplete="off"
                                   :help="$form['has_key'] ? 'A key is saved. Leave this empty to keep it.' : 'From console.anthropic.com (Claude) or platform.openai.com (OpenAI). Stored encrypted; never shown again.'" />
                    @if ($form['has_key'])
                        <x-admin.checkbox name="api_key_clear" label="Remove the saved key" />
                    @endif

                    <div x-show="provider === 'anthropic'">
                        <x-admin.select name="model" id="f-model-anthropic" label="Model" :options="$anthropicModels" :value="$form['provider'] === 'anthropic' && $form['model'] !== '' ? $form['model'] : array_key_first($anthropicModels)"
                                        help="Every reply is billed by your provider. Opus is the most capable; Sonnet and Haiku are faster and cheaper per message." x-bind:disabled="provider !== 'anthropic'" />
                    </div>
                    <div x-show="provider === 'openai'">
                        <x-admin.input name="model" id="f-model-openai" label="Model" :value="$form['provider'] === 'openai' ? $form['model'] : ''" placeholder="e.g. the model id from your OpenAI dashboard"
                                       help="The exact model id. It must support function calling." x-bind:disabled="provider !== 'openai'" />
                    </div>

                    <x-admin.input name="assistant_name" label="Assistant name" :value="$form['assistant_name']" placeholder="Shop assistant" />
                    <x-admin.textarea name="greeting" label="Greeting" rows="2" :value="$form['greeting']"
                                      help="First message in the chat window. Blank = a short default." />
                    <x-admin.textarea name="knowledge" label="Store information for the assistant" rows="10" :value="$form['knowledge']"
                                      help="Delivery times and prices, returns, warranty, payment options, opening hours, contact details, anything customers often ask. The assistant only quotes what is here and in your catalogue." />
                    <x-admin.input name="max_replies" type="number" label="Most assistant replies per chat" :value="$form['max_replies']" min="1" max="500"
                                   help="After this many, the chat goes to the team. Keeps a runaway chat from running up the bill." />
                </div>
            </x-admin.card>

            <x-admin.card title="Hand-over to the team">
                <x-admin.input name="notify_email" type="email" label="Notify" :value="$form['notify_email']" :placeholder="setting('store.email', config('mail.from.address'))"
                               help="Gets one email per chat when a customer wants a person. Blank = the store email." />
                <x-admin.input name="wait_minutes" type="number" label="Minutes before asking for an email" :value="$form['wait_minutes']" min="1" max="120"
                               help="If nobody from the team has answered by then (or nobody has Admin › Live chat open), the chat asks the customer for their email and it arrives in Inbox › Form submissions." />
            </x-admin.card>
        </div>
    </x-admin.form>
@endsection
