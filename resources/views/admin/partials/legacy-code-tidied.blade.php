{{-- Page/post editor: the content's old page-builder shortcodes were left out (PageContent::tidyForEditor). $noun = page|post --}}
<x-admin.callout type="info" class="mb-4" title="Old page-builder code hidden">
    This {{ $noun }} came from the old website with page-builder code (like [vc_row …]) around its text. The editor shows it without that code, the way customers see it, so you can just edit the words. The tidy version is saved when you change the content.
</x-admin.callout>
