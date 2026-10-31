{{-- Visual page builder (PageDesignController@edit): full-screen GrapesJS designer for one page; admin/js/builder.js reads #pb-config. --}}
@extends('commerce::admin.layouts.app', ['width' => 'full'])

@section('title', 'Design · '.$page->title)

@push('head')
    <link rel="stylesheet" href="{{ commerce_admin_asset('vendor/grapesjs-0.23.6/css/grapes.min.css', false) }}">
    <link rel="stylesheet" href="{{ commerce_admin_asset('css/builder.css') }}">
@endpush

@push('vendor')
    <script defer src="{{ commerce_admin_asset('vendor/grapesjs-0.23.6/grapes.min.js', false) }}"></script>
    <script defer src="{{ commerce_admin_asset('js/builder.js') }}"></script>
@endpush

@section('content')
    <x-admin.media-bridge />
    <x-admin.link-picker />
    <script type="application/json" id="pb-config">@json($config)</script>

    <div class="pb-builder" id="pb-builder" data-right-tab="settings">
        <header class="pb-bar">
            <a class="btn btn--ghost btn--sm" href="{{ route('admin.pages.edit', $page) }}" data-pb-back>
                <x-admin.icon name="arrow-left" /> <span class="pb-hide-sm">Page settings</span>
            </a>
            <div class="pb-bar__title">
                <strong title="{{ $page->title }}">{{ $page->title }}</strong>
                <span class="pb-status" data-pb-status>{{ $page->status === 'published' ? 'Published' : 'Draft' }}</span>
                <span class="pb-saved" data-pb-saved aria-live="polite">All changes saved</span>
            </div>
            <div class="pb-bar__group" role="group" aria-label="Screen size">
                <button type="button" class="pb-tool is-active" data-device="desktop" aria-pressed="true" title="Computer"><x-admin.icon name="computer-desktop" label="Computer" /></button>
                <button type="button" class="pb-tool" data-device="tablet" aria-pressed="false" title="Tablet"><x-admin.icon name="device-tablet" label="Tablet" /></button>
                <button type="button" class="pb-tool" data-device="phone" aria-pressed="false" title="Phone"><x-admin.icon name="device-phone-mobile" label="Phone" /></button>
            </div>
            <div class="pb-bar__group">
                <button type="button" class="pb-tool" data-pb-undo title="Undo (Ctrl+Z)"><x-admin.icon name="arrow-uturn-left" label="Undo" /></button>
                <button type="button" class="pb-tool" data-pb-redo title="Redo (Ctrl+Shift+Z)"><x-admin.icon name="arrow-uturn-right" label="Redo" /></button>
                <button type="button" class="pb-tool" data-pb-preview title="Preview without the editing tools"><x-admin.icon name="eye" label="Preview" /></button>
            </div>
            <div class="pb-bar__actions">
                <a class="btn btn--sm pb-hide-sm" href="{{ $page->url }}" target="_blank" rel="noopener">View page</a>
                <button type="button" class="btn btn--sm" data-pb-publish hidden>Save and publish</button>
                <button type="button" class="btn btn--primary btn--sm" data-pb-save title="Save (Ctrl+S)">Save</button>
            </div>
        </header>

        <aside class="pb-left" aria-label="Blocks and layers">
            <div class="pb-tabs" role="tablist">
                <button type="button" role="tab" class="pb-tab is-active" data-pb-left="add" aria-selected="true"><x-admin.icon name="squares-plus" /> Add</button>
                <button type="button" role="tab" class="pb-tab" data-pb-left="layers" aria-selected="false"><x-admin.icon name="rectangle-stack" /> Layers</button>
            </div>
            <p class="pb-help" data-pb-help>Drag a block onto the page. A line shows where it will go.</p>
            <div id="pb-blocks" class="pb-scroll"></div>
            <div id="pb-layers" class="pb-scroll" hidden></div>
        </aside>

        <div class="pb-canvas" id="pb-canvas"></div>

        <aside class="pb-right" aria-label="Settings for the selected part">
            <div class="pb-right__empty" data-pb-empty>
                <x-admin.icon name="cursor-arrow-rays" size="lg" />
                <h2>Click anything on the page to change it</h2>
                <ol>
                    <li><strong>Add:</strong> drag a block from the left onto the page.</li>
                    <li><strong>Change:</strong> click a part to see its settings here.</li>
                    <li><strong>Type:</strong> double-click text to edit the words.</li>
                    <li><strong>Pictures:</strong> double-click a picture to choose another.</li>
                    <li><strong>Check:</strong> use the phone and tablet buttons at the top.</li>
                    <li><strong>Save</strong> when you’re happy. Undo puts mistakes right.</li>
                </ol>
            </div>
            <div class="pb-right__selected" data-pb-selected hidden>
                <div class="pb-selected">
                    <strong data-pb-name></strong>
                    <div class="pb-selected__actions">
                        <button type="button" class="pb-tool" data-pb-act="parent" title="Select the part it sits in"><x-admin.icon name="arrow-up-left" label="Select the part it sits in" /></button>
                        <button type="button" class="pb-tool" data-pb-act="up" title="Move up"><x-admin.icon name="arrow-up" label="Move up" /></button>
                        <button type="button" class="pb-tool" data-pb-act="down" title="Move down"><x-admin.icon name="arrow-down" label="Move down" /></button>
                        <button type="button" class="pb-tool" data-pb-act="copy" title="Duplicate"><x-admin.icon name="document-duplicate" label="Duplicate" /></button>
                        <button type="button" class="pb-tool pb-tool--danger" data-pb-act="delete" title="Delete"><x-admin.icon name="trash" label="Delete" /></button>
                    </div>
                </div>
                <div class="pb-tabs" role="tablist">
                    <button type="button" role="tab" class="pb-tab is-active" data-pb-tab="settings" aria-selected="true">Settings</button>
                    <button type="button" role="tab" class="pb-tab" data-pb-tab="style" aria-selected="false">Style</button>
                </div>
                <div id="pb-settings-panel" class="pb-scroll">
                    <p class="pb-help" data-pb-traits-empty hidden>Nothing to set here – use <strong>Style</strong> to change how it looks.</p>
                    <div id="pb-traits"></div>
                </div>
                <div id="pb-style-panel" class="pb-scroll" hidden>
                    <p class="pb-help">Changes apply to this part only. Use the phone button at the top to change how it looks on phones.</p>
                    <div id="pb-styles"></div>
                </div>
            </div>
        </aside>

        <div class="pb-starter" data-pb-starter hidden>
            <div class="pb-starter__box" role="dialog" aria-modal="true" aria-labelledby="pb-starter-title">
                <h2 id="pb-starter-title">How would you like to start?</h2>
                <p>You can change everything afterwards, and nothing is shown to customers until you save.</p>
                <div class="pb-starter__grid">
                    <button type="button" class="pb-start" data-pb-start="current">
                        <x-admin.icon name="document-text" size="lg" /><strong>With the page’s current text</strong><span>Its title and words in one section, ready to add pictures and blocks around.</span>
                    </button>
                    <button type="button" class="pb-start" data-pb-start="story">
                        <x-admin.icon name="photo" size="lg" /><strong>A story page</strong><span>Banner, picture and text, three highlights, a review and a call to action.</span>
                    </button>
                    <button type="button" class="pb-start" data-pb-start="promo">
                        <x-admin.icon name="megaphone" size="lg" /><strong>A promotion page</strong><span>Banner, products, text and picture, questions and answers.</span>
                    </button>
                    <button type="button" class="pb-start" data-pb-start="blank">
                        <x-admin.icon name="squares-plus" size="lg" /><strong>A blank page</strong><span>Start empty and drag in exactly what you want.</span>
                    </button>
                </div>
            </div>
        </div>

        <div class="pb-small-screen">
            <x-admin.icon name="computer-desktop" size="xl" />
            <p>The visual builder needs a bigger screen. Open this page on a computer or tablet.</p>
            <a class="btn btn--sm" href="{{ route('admin.pages.edit', $page) }}">Back to the page</a>
        </div>
    </div>
@endsection
