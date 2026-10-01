{{-- Contact form (contact_form shortcode / contact page template). Field names use commerce.forms.contact.prefix. --}}
@php
    $p = (string) config('commerce.forms.contact.prefix', 'cf_');
    $contactErrors = isset($errors) ? $errors->getBag('contact') : new \Illuminate\Support\MessageBag;
    $contactStatus = session('contact_status');
    $field = function (string $name) use ($contactErrors, $p) {
        return $contactErrors->has($p.$name) ? 'aria-invalid="true" aria-describedby="'.$p.$name.'_error"' : '';
    };
@endphp
<div class="contact-form" id="contact-form">
    @if ($contactStatus)
        <div class="notice notice--success" role="status">{{ $contactStatus }}</div>
    @elseif ($contactErrors->any())
        <div class="notice notice--error" role="alert">{{ $contactErrors->first('form') ?: 'Please add your name, a valid email address and a message.' }}</div>
    @endif
    <form class="form contact-form__form" method="post" action="{{ route('contact.submit') }}" novalidate>
        @csrf
        <input type="hidden" name="page_url" value="{{ url()->current() }}">
        <p class="sr-only" aria-hidden="true"><label for="{{ $p }}company">Company</label><input id="{{ $p }}company" name="{{ $p }}company" type="text" autocomplete="off" tabindex="-1"></p>
        <div class="form-grid">
            <div class="field">
                <label for="{{ $p }}name">Name <span class="req" aria-hidden="true">*</span></label>
                <input id="{{ $p }}name" name="{{ $p }}name" type="text" autocomplete="name" required maxlength="120" value="{{ old($p.'name') }}" {!! $field('name') !!}>
                @if ($contactErrors->has($p.'name'))<p class="field__error" id="{{ $p }}name_error">{{ $contactErrors->first($p.'name') }}</p>@endif
            </div>
            <div class="field">
                <label for="{{ $p }}email">Email <span class="req" aria-hidden="true">*</span></label>
                <input id="{{ $p }}email" name="{{ $p }}email" type="email" autocomplete="email" required maxlength="190" value="{{ old($p.'email') }}" {!! $field('email') !!}>
                @if ($contactErrors->has($p.'email'))<p class="field__error" id="{{ $p }}email_error">{{ $contactErrors->first($p.'email') }}</p>@endif
            </div>
            <div class="field">
                <label for="{{ $p }}phone">Phone <span class="opt">(optional)</span></label>
                <input id="{{ $p }}phone" name="{{ $p }}phone" type="tel" autocomplete="tel" maxlength="40" value="{{ old($p.'phone') }}">
            </div>
            <div class="field">
                <label for="{{ $p }}subject">Subject</label>
                <input id="{{ $p }}subject" name="{{ $p }}subject" type="text" maxlength="160" value="{{ old($p.'subject') }}" placeholder="How can we help?">
            </div>
            <div class="field field--full">
                <label for="{{ $p }}message">Message <span class="req" aria-hidden="true">*</span></label>
                <textarea id="{{ $p }}message" name="{{ $p }}message" rows="6" required maxlength="5000" {!! $field('message') !!}>{{ old($p.'message') }}</textarea>
                @if ($contactErrors->has($p.'message'))<p class="field__error" id="{{ $p }}message_error">{{ $contactErrors->first($p.'message') }}</p>@endif
            </div>
        </div>
        <button class="btn btn--primary" type="submit">Send message</button>
    </form>
</div>
