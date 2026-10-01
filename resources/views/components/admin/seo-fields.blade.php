{{--
    Meta title + description with a live Google result preview and length counters.
    <x-admin.seo-fields :title="$page->meta_title" :description="$page->meta_description"
                        :url="$page->exists ? url($page->path) : null" title-source="#f-title" description-source="#f-content"
                        :fallback-title="$page->title" />
    Props: title, description (current values), title-name / description-name (field names, default meta_title / meta_description),
           fallback-title / fallback-description (used by the storefront when blank), title-source / description-source
           (CSS selector of an input to follow live), url (page URL), slug-source + base-url (build the URL from a slug input live)
--}}
@props(['title' => null, 'description' => null, 'titleName' => 'meta_title', 'descriptionName' => 'meta_description',
        'fallbackTitle' => null, 'fallbackDescription' => null, 'titleSource' => null, 'descriptionSource' => null,
        'url' => null, 'slugSource' => null, 'baseUrl' => null])
@php
    $titleKey = \Pine\Commerce\View\Components\Admin\Ui::key($titleName);
    $descriptionKey = \Pine\Commerce\View\Components\Admin\Ui::key($descriptionName);
    $titleId = \Pine\Commerce\View\Components\Admin\Ui::id($titleName);
    $descriptionId = \Pine\Commerce\View\Components\Admin\Ui::id($descriptionName);
    $siteName = setting('seo.site_name', setting('store.name', config('app.name')));
    $config = [
        'title' => (string) old($titleKey, $title),
        'description' => (string) old($descriptionKey, $description),
        'fallbackTitle' => (string) $fallbackTitle,
        'fallbackDescription' => \Illuminate\Support\Str::limit(trim(preg_replace('/\s+/', ' ', strip_tags((string) $fallbackDescription))), 300, ''),
        'titleSource' => $titleSource,
        'descriptionSource' => $descriptionSource,
        'suffix' => (string) setting('seo.title_suffix', '| '.$siteName),
        'url' => $url ?? url('/'),
        'slugSource' => $slugSource,
        'baseUrl' => $baseUrl,
    ];
@endphp
<div {{ $attributes->class(['stack']) }} x-data="seoFields(@js($config))">
    <div class="serp" aria-label="Google search result preview">
        <div class="serp__site">
            <span class="serp__favicon"><img src="{{ commerce_admin_brand('favicon') }}" alt=""></span>
            <span>
                <span style="display:block">{{ $siteName }}</span>
                <span class="serp__url" x-text="displayUrl"></span>
            </span>
        </div>
        <div class="serp__title" x-text="effectiveTitle"></div>
        <div class="serp__desc" x-text="effectiveDescription"></div>
    </div>
    <x-admin.field label="Page title" :for="$titleId" :error="$titleKey" help="Shown as the link in Google and in the browser tab. Leave blank to use the default.">
        <x-slot:labelExtra><span class="field__counter" :class="{ 'is-over': title.length > titleMax }" x-text="title.length + ' / ' + titleMax"></span></x-slot:labelExtra>
        <input type="text" class="input @error($titleKey) is-invalid @enderror" name="{{ $titleName }}" id="{{ $titleId }}" value="{{ $config['title'] }}" x-model="title" maxlength="255"
               :placeholder="sourceTitle ? sourceTitle + (suffix ? ' ' + suffix : '') : ''">
    </x-admin.field>
    <x-admin.field label="Meta description" :for="$descriptionId" :error="$descriptionKey" help="The summary under the link in Google. Aim for 120–160 characters.">
        <x-slot:labelExtra><span class="field__counter" :class="{ 'is-over': description.length > descriptionMax }" x-text="description.length + ' / ' + descriptionMax"></span></x-slot:labelExtra>
        <textarea class="textarea @error($descriptionKey) is-invalid @enderror" name="{{ $descriptionName }}" id="{{ $descriptionId }}" rows="3" x-model="description" maxlength="1000"
                  :placeholder="sourceDescription ? sourceDescription.slice(0, 160) : ''">{{ $config['description'] }}</textarea>
    </x-admin.field>
</div>
