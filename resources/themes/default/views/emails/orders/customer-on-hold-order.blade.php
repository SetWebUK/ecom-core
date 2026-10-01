@extends('emails.layouts.base')

@section('body')
<p style="margin:0 0 16px;">Hi {{ $order->billing_first_name ?: 'there' }},</p>
<p style="margin:0 0 16px;">Thanks for your order. It’s on hold until we confirm your payment has been received.</p>
@if ($order->payment_method === 'bacs')
    @if (! empty($bacsInstructions))<p style="margin:0 0 16px;">{!! nl2br(e($bacsInstructions)) !!}</p>@endif
    @foreach ($bacsAccounts ?? [] as $account)
        <table cellspacing="0" cellpadding="0" border="0" width="100%" role="presentation" style="margin:0 0 16px;background:#f4f5f7;border-radius:8px;">
            <tr><td style="padding:14px 16px;font-size:14px;line-height:1.7;">
                @if ($account['account_name'])Account name: <strong>{{ $account['account_name'] }}</strong><br>@endif
                @if ($account['bank_name'])Bank: <strong>{{ $account['bank_name'] }}</strong><br>@endif
                @if ($account['sort_code'])Sort code: <strong>{{ $account['sort_code'] }}</strong><br>@endif
                @if ($account['account_number'])Account number: <strong>{{ $account['account_number'] }}</strong><br>@endif
                @if ($account['iban'])IBAN: <strong>{{ $account['iban'] }}</strong><br>@endif
                @if ($account['bic'])BIC: <strong>{{ $account['bic'] }}</strong>@endif
            </td></tr>
        </table>
    @endforeach
    @if (! empty($bacsAccounts))<p style="margin:0 0 16px;">Please use <strong>{{ $order->number }}</strong> as the payment reference.</p>@endif
@endif
@include('emails.partials.order-details')
@include('emails.partials.addresses')
@endsection
