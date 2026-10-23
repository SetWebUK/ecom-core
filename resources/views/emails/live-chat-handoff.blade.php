{{--
    Live chat: a customer asked for a person (Pine\Commerce\Mail\LiveChatHandoff). Same branded wrapper as the order emails.
    Data: $heading, $storeName, $reason, $transcript, $pageUrl, $chatUrl
--}}
@extends('emails.layouts.base')

@section('body')
<p style="margin:0 0 16px;">A customer on the website would like to talk to someone.</p>
<p style="margin:0 0 16px;"><strong>Why:</strong> {{ $reason }}</p>
@if ($pageUrl)
    <p style="margin:0 0 16px;"><strong>Page:</strong> <a href="{{ $pageUrl }}" style="color:inherit;">{{ $pageUrl }}</a></p>
@endif
@if ($chatUrl)
    <p style="margin:0 0 24px;"><a href="{{ $chatUrl }}" style="display:inline-block;padding:10px 18px;background:#111827;color:#ffffff;text-decoration:none;border-radius:6px;font-weight:600;">Open the chat</a></p>
@endif
@if (trim($transcript) !== '')
    <p style="margin:0 0 8px;font-size:16px;font-weight:600;">Conversation so far</p>
    <div style="white-space:pre-wrap;padding:12px;background:#f6f7f9;border-radius:6px;font-size:14px;line-height:1.5;">{{ $transcript }}</div>
@endif
<p style="margin:24px 0 0;color:#8a8f98;font-size:12px;">Sent once per chat. Customers who wait longer than the time set in Admin › Live chat › Settings are asked for their email instead.</p>
@endsection
