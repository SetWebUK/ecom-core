{{-- One form submission: the message, sender details, reply by email, older messages from the same person. --}}
@extends('commerce::admin.layouts.app')

@php
    use Pine\Commerce\Http\Controllers\Admin\SubmissionController;
    $subject = $submission->subject && $submission->subject !== 'Website enquiry' ? $submission->subject : 'Your enquiry';
    $replyUrl = $submission->email ? 'mailto:'.rawurlencode($submission->email).'?subject='.rawurlencode('Re: '.$subject) : null;
@endphp

@section('title', 'Message from '.($submission->name ?: $submission->email))

@section('content')
    <x-admin.page-header :title="$submission->name ?: ($submission->email ?: 'Message')" :back="route('admin.form-submissions.index')" back-label="Back to form submissions">
        <x-slot:meta>{{ SubmissionController::formLabel($submission->form) }} · <x-admin.time :value="$submission->created_at" format="datetime" /></x-slot:meta>
        <x-slot:actions>
            <div class="btn-group">
                <x-admin.button :href="$older ? route('admin.form-submissions.show', $older) : null" :disabled="! $older" icon="chevron-left" label="Older message" />
                <x-admin.button :href="$newer ? route('admin.form-submissions.show', $newer) : null" :disabled="! $newer" icon="chevron-right" label="Newer message" />
            </div>
            @if ($replyUrl)
                <x-admin.button variant="primary" icon="arrow-uturn-left" :href="$replyUrl">Reply by email</x-admin.button>
            @endif
        </x-slot:actions>
    </x-admin.page-header>

    <div class="layout">
        <div class="layout__main">
            <x-admin.card :title="$submission->subject ?: 'Message'">
                <div class="message-body">{{ $submission->message ?: '(no message)' }}</div>
            </x-admin.card>

            @if ($fields)
                <x-admin.card title="Other details">
                    <dl class="kv">
                        @foreach ($fields as $label => $value)
                            <dt>{{ $label }}</dt>
                            <dd>
                                @if (preg_match('#^https?://#', $value))
                                    <a href="{{ $value }}" target="_blank" rel="noopener noreferrer" class="break">{{ $value }}</a>
                                @else
                                    {{ $value }}
                                @endif
                            </dd>
                        @endforeach
                    </dl>
                </x-admin.card>
            @endif

            @if ($fromSender->isNotEmpty())
                <x-admin.card title="Earlier messages from this person" flush>
                    <ul class="list">
                        @foreach ($fromSender as $other)
                            <li>
                                <a class="list__item" href="{{ route('admin.form-submissions.show', $other) }}">
                                    <div class="list__main">
                                        <div class="list__title">{{ $other->subject ?: 'Message' }}</div>
                                        <div class="list__sub">{{ Illuminate\Support\Str::limit((string) $other->message, 120) }}</div>
                                    </div>
                                    <div class="list__meta text-muted"><x-admin.time :value="$other->created_at" format="date" /></div>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </x-admin.card>
            @endif
        </div>

        <div class="layout__aside">
            <x-admin.card title="Sender">
                <dl class="kv kv--stacked">
                    <dt>Name</dt><dd>{{ $submission->name ?: '—' }}</dd>
                    <dt>Email</dt>
                    <dd>@if ($submission->email)<a href="{{ $replyUrl }}" class="break">{{ $submission->email }}</a>@else — @endif</dd>
                    <dt>Phone</dt>
                    <dd>@if ($submission->phone)<a href="tel:{{ preg_replace('/[^0-9+]/', '', $submission->phone) }}">{{ $submission->phone }}</a>@else — @endif</dd>
                    @if ($customer && Route::has('admin.customers.show'))
                        <dt>Customer account</dt><dd><a href="{{ route('admin.customers.show', $customer) }}">{{ $customer->full_name }}</a></dd>
                    @endif
                    @if ($submission->email && Route::has('admin.orders.index'))
                        <dt>Orders</dt><dd><a href="{{ route('admin.orders.index', ['q' => $submission->email]) }}">Find their orders</a></dd>
                    @endif
                    @if ($submission->ip_address)
                        <dt>IP address</dt><dd class="mono text-muted">{{ $submission->ip_address }}</dd>
                    @endif
                </dl>
            </x-admin.card>
            <x-admin.card title="Actions">
                <div class="stack stack--sm">
                    <x-admin.confirm :action="route('admin.form-submissions.unread', $submission)" method="PATCH" :danger="false" title="Mark as unread?" message="It will show as new in the inbox again." confirm-label="Mark as unread" icon="envelope" block>Mark as unread</x-admin.confirm>
                    <x-admin.confirm :action="route('admin.form-submissions.destroy', $submission)" variant="ghost-danger" icon="trash" title="Delete this message?" message="It’s removed for good. This can’t be undone." confirm-label="Delete message" block>Delete message</x-admin.confirm>
                </div>
            </x-admin.card>
        </div>
    </div>
@endsection
