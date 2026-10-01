{{-- Create/edit a blog category. --}}
@extends('commerce::admin.layouts.app', ['width' => 'narrow'])

@php
    $editing = $category->exists;
    $saveLabel = $editing ? 'Save' : 'Add category';
@endphp

@section('title', $editing ? $category->name.' · Blog categories' : 'Add blog category')

@section('content')
    <x-admin.page-header :title="$editing ? $category->name : 'Add blog category'" :back="route('admin.post-categories.index')" back-label="Back to blog categories" />

    <x-admin.form id="post-category-form" :action="$editing ? route('admin.post-categories.update', $category) : route('admin.post-categories.store')" :method="$editing ? 'PUT' : 'POST'" dirty :save-label="$saveLabel">
        <x-admin.card>
            <div class="stack-fields">
                <x-admin.input name="name" label="Name" :value="$category->name" required maxlength="120" :autofocus="! $editing" />
                <x-admin.input name="slug" label="URL handle" :value="$category->slug" prefix="/blog/category/" class="mono" maxlength="120" x-data="slugField('#f-name')" help="Leave blank to make one from the name." />
            </div>
        </x-admin.card>
    </x-admin.form>

    <div class="form-actions">
        @if ($editing)
            <x-admin.confirm :action="route('admin.post-categories.destroy', $category)" variant="ghost-danger" icon="trash"
                             :title="'Delete “'.($category->name).'”?'" confirm-label="Delete category"
                             :message="$category->posts_count ? 'Its '.$category->posts_count.' posts stay on the blog without a category. The category page stops working.' : 'The category page stops working.'">Delete category</x-admin.confirm>
        @endif
        <span class="flex-1"></span>
        <x-admin.button :href="route('admin.post-categories.index')">Cancel</x-admin.button>
        <x-admin.button type="submit" form="post-category-form" variant="primary">{{ $saveLabel }}</x-admin.button>
    </div>
@endsection
