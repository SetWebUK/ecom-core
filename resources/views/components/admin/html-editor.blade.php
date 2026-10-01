{{--
    Rich-text dialog (one per page) for short HTML fields inside repeaters – FAQ answers, home page text blocks.
    Open it from JavaScript: Admin.editHtml({ value: html, title: 'Answer' }, function (html) { … }).
    <x-admin.html-editor />   (loads TinyMCE, the media library bridge and content.js)
--}}
@once('admin-tinymce')
    @push('vendor')
        <script defer src="{{ commerce_admin_asset('vendor/tinymce-6.8.6/tinymce.min.js', false) }}"></script>
    @endpush
@endonce
<x-admin.media-bridge />
@once('admin-html-editor')
    <div x-data="htmlEditor(@js(['uploadUrl' => route('admin.media.upload'), 'height' => 380]))">
        <template x-teleport="body">
            <div class="modal" x-show="open" x-cloak role="dialog" aria-modal="true" aria-labelledby="html-editor-title" @keydown.escape="if (!document.querySelector('.tox-dialog')) close()">
                <div class="modal__backdrop" x-show="open" x-transition.opacity @click="close()"></div>
                <div class="modal__panel modal__panel--lg" x-show="open" x-transition:enter="enter" x-transition:enter-start="pop-from" x-transition:enter-end="to-visible">
                    <div class="modal__header">
                        <h2 class="modal__title" id="html-editor-title" x-text="title">Edit text</h2>
                        <button type="button" class="btn btn--ghost btn--icon btn--sm" @click="close()" aria-label="Close"><x-admin.icon name="x-mark" /></button>
                    </div>
                    <div class="modal__body">
                        <div class="rich-editor">
                            <textarea x-ref="textarea" class="textarea textarea--code" rows="12" aria-label="Text"></textarea>
                        </div>
                        <p class="text-xs text-muted mt-2" x-show="!ready"><span class="spinner spinner--sm" style="display:inline-block;vertical-align:-2px"></span> Loading editor…</p>
                    </div>
                    <div class="modal__footer">
                        <button type="button" class="btn" @click="close()">Cancel</button>
                        <button type="button" class="btn btn--primary" @click="save()"><span>Done</span></button>
                    </div>
                </div>
            </div>
        </template>
    </div>
@endonce
