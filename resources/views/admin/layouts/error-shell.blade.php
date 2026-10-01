@extends('commerce::admin.layouts.app')

@section('title', $title)

@section('content')
    @php
        $back = url()->previous();
        $back = $back && $back !== url()->current() && str_starts_with($back, url('/admin')) ? $back : route('admin.dashboard');
    @endphp
    <div class="card">
        <x-admin.empty :icon="$status === 403 ? 'lock-closed' : ($status === 404 ? 'magnifying-glass' : 'exclamation-triangle')" :title="$title" :description="$detail">
            <x-admin.button :href="$back" icon="arrow-left">Go back</x-admin.button>
            <x-admin.button :href="route('admin.dashboard')" variant="primary">Go to Home</x-admin.button>
        </x-admin.empty>
        <p class="text-center text-xs text-subtle" style="padding-bottom:16px">Error {{ $status }}</p>
    </div>
@endsection
