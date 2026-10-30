{{-- My account › Points (feature "loyalty"): balance, what it is worth, how points are earned and spent, history. $balance, $history --}}
@extends('account.frame')

@php
    $L = \Pine\Commerce\Services\Loyalty\Loyalty::class;
    $label = $L::label();
@endphp

@section('account_content')
<h1 class="page-title">Your {{ $label }}</h1>
<div class="card points-balance">
    <p class="points-balance__value"><strong>{{ number_format($balance) }}</strong> {{ $label }}</p>
    @if (! $L::canSpend())
    <p class="muted">Worth {{ money($L::value($balance)) }}. Using {{ $label }} at checkout is paused for now – your balance is kept and you keep earning.</p>
    @else
    <p class="muted">Worth {{ money($L::value($balance)) }} off your next order.@if ($L::minRedeem() > 0 && $balance < $L::minRedeem()) You can use them once you have {{ number_format($L::minRedeem()) }}.@endif</p>
    @endif
</div>
<ul class="points-rules">
    <li>Earn {{ rtrim(rtrim(number_format($L::earnRate(), 2), '0'), '.') }} {{ \Illuminate\Support\Str::plural(rtrim($label, 's'), (int) ceil($L::earnRate())) }} for every £1 you spend, added when your order is paid.</li>
    <li>{{ number_format($L::redeemRate()) }} {{ $label }} = {{ money(1) }} off{{ $L::canSpend() ? ' – choose “Use my '.$label.'” at checkout' : '' }}.</li>
    @if ($L::signupPoints() > 0)<li>New accounts get {{ number_format($L::signupPoints()) }} {{ $label }} to start with.</li>@endif
</ul>
@if ($history->isNotEmpty())
    <h2 class="section-title">History</h2>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th scope="col">Date</th><th scope="col">What happened</th><th scope="col">{{ ucfirst($label) }}</th></tr></thead>
            <tbody>
                @foreach ($history as $row)
                    <tr>
                        <td data-title="Date"><time datetime="{{ $row->created_at?->toAtomString() }}">{{ \Pine\Commerce\Services\Checkout\UkTime::format($row->created_at, 'j M Y') }}</time></td>
                        <td data-title="What happened">{{ $row->label() }}@if ($row->order) – <a href="{{ route('account.order', ['number' => $row->order->number]) }}">order #{{ $row->order->number }}</a>@elseif ($row->note && $row->type === 'adjust') – {{ $row->note }}@endif</td>
                        <td data-title="{{ ucfirst($label) }}" class="{{ $row->points < 0 ? 'muted' : '' }}">{{ $row->points > 0 ? '+' : '' }}{{ number_format($row->points) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @if ($history->lastPage() > 1)
        <nav class="pagination" aria-label="History pages">
            @if ($history->currentPage() > 1)<a class="btn btn--outline btn--sm" href="{{ $history->previousPageUrl() }}">Previous</a>@endif
            @if ($history->hasMorePages())<a class="btn btn--outline btn--sm" href="{{ $history->nextPageUrl() }}">Next</a>@endif
        </nav>
    @endif
@endif
@endsection
