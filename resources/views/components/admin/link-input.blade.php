{{--
    URL field with a "Browse" button that searches pages, categories, products and blog posts.
    <x-admin.link-input name="to_url" label="Redirect to" :value="$redirect->to_url" help="…" required />
    Stores what is typed/picked: site-relative paths ("/contact-us/"), full URLs, mailto: or tel: links.
    Props: name, label, value, help, required, optional, placeholder, id, error
--}}
@props(['name', 'label' => null, 'value' => null, 'help' => null, 'required' => false, 'optional' => false, 'placeholder' => '/page-address/ or https://…', 'id' => null, 'error' => null])
@php
    $key = \Pine\Commerce\View\Components\Admin\Ui::key($name);
    $id ??= \Pine\Commerce\View\Components\Admin\Ui::id($name);
    $error ??= $key;
    $invalid = $errors->has($error);
@endphp
<x-admin.link-picker />
<x-admin.field :label="$label" :for="$id" :help="$help" :error="$error" :required="$required" :optional="$optional" {{ $attributes->only('class') }}>
    <div @class(['input-group', 'is-invalid' => $invalid]) x-data="linkInput">
        <input type="text" name="{{ $name }}" id="{{ $id }}" x-ref="input" value="{{ old($key, $value) }}" placeholder="{{ $placeholder }}"
               {{ $attributes->except('class')->class(['input', 'mono']) }} @required($required) maxlength="1000" autocomplete="off" spellcheck="false"
               @if ($invalid) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif>
        <button type="button" class="btn btn--sm" @click="browse()"><x-admin.icon name="link" /><span>Browse</span></button>
    </div>
</x-admin.field>
