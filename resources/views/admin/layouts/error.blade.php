{{-- Error page for /admin (rendered by Pine\Commerce\Http\Middleware\EnsureStaff). Staff see it inside the admin shell. --}}
@php
    $status = (int) ($status ?? 500);
    $titles = [
        401 => 'Please sign in',
        403 => 'You don’t have access to this page',
        404 => 'We couldn’t find that page',
        405 => 'That action isn’t allowed here',
        419 => 'This page expired',
        429 => 'Too many requests',
        500 => 'Something went wrong',
        503 => 'We’ll be right back',
    ];
    $title = $titles[$status] ?? ($status >= 500 ? $titles[500] : 'Something went wrong');
    $detail = $message ?? match (true) {
        $status === 404 => 'The link may be out of date, or the item was deleted.',
        $status === 419 => 'You were inactive for a while. Go back, reload the page and try again.',
        $status >= 500 => 'The error has been logged. Please try again – if it keeps happening, let your developer know.',
        default => null,
    };
@endphp
@include(auth()->user()?->canAccessAdmin() ? 'commerce::admin.layouts.error-shell' : 'commerce::admin.layouts.error-bare', ['status' => $status, 'title' => $title, 'detail' => $detail])
