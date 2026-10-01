<?php

namespace Pine\Commerce\Http\Requests\Admin\Content;

use Pine\Commerce\Models\Post;
use Pine\Commerce\Services\Admin\LocalTime;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Create/update a blog post. Scheduling: status "published" + a publish date in the future = scheduled
 * (Post::published() hides it until then). Content is only replaced when the editor reports a change.
 */
class PostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->canAccessAdmin();
    }

    protected function prepareForValidation(): void
    {
        $slug = is_string($this->input('slug')) && trim($this->input('slug')) !== '' ? $this->input('slug') : (string) $this->input('title');
        $this->merge([
            'slug' => Str::slug(trim($slug, " /\t\n")),
            'title' => is_string($this->input('title')) ? trim($this->input('title')) : $this->input('title'),
            'post_category_id' => $this->input('post_category_id') ?: null,
            'author_id' => $this->input('author_id') ?: null,
        ]);
    }

    public function rules(): array
    {
        $post = $this->route('post');

        return [
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:190', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('posts', 'slug')->ignore($post instanceof Post ? $post->id : null)],
            'excerpt' => ['nullable', 'string', 'max:2000'],
            'content' => ['nullable', 'string', 'max:4000000'],
            'content_changed' => ['nullable', 'boolean'],
            'featured_image' => ['nullable', 'string', 'max:255', 'regex:#^(uploads/|https?://|/)[^\s<>"\']*$#i'],
            'post_category_id' => ['nullable', 'integer', Rule::exists('post_categories', 'id')],
            'author_id' => ['nullable', 'integer', Rule::exists('users', 'id')->whereIn('role', ['admin', 'manager'])],
            'status' => ['required', Rule::in(['published', 'draft'])],
            'published_at' => ['nullable', 'date'],
            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'slug.unique' => 'Another post already uses this address. Change the URL handle.',
            'slug.regex' => 'Use lower-case letters, numbers and dashes only.',
            'slug.required' => 'Enter a title or a URL handle.',
            'featured_image.regex' => 'Choose the image from the media library.',
            'author_id.exists' => 'Choose a staff member as the author.',
        ];
    }

    public function attributes(): array
    {
        return ['slug' => 'URL handle', 'post_category_id' => 'category', 'author_id' => 'author', 'published_at' => 'publish date', 'meta_title' => 'page title'];
    }

    public function postData(?Post $post = null): array
    {
        $publishedAt = LocalTime::fromInputKeeping($this->input('published_at'), $post?->published_at);
        if ($this->input('status') === 'published' && ! $publishedAt) {
            $publishedAt = $post?->published_at ?? now();
        }

        $data = [
            'title' => $this->input('title'),
            'slug' => $this->input('slug'),
            'excerpt' => $this->filled('excerpt') ? trim($this->input('excerpt')) : null,
            'featured_image' => $this->filled('featured_image') ? $this->input('featured_image') : null,
            'post_category_id' => $this->input('post_category_id') ? (int) $this->input('post_category_id') : null,
            'author_id' => $this->input('author_id') ? (int) $this->input('author_id') : null,
            'status' => $this->input('status'),
            'published_at' => $publishedAt,
            'meta_title' => $this->filled('meta_title') ? trim($this->input('meta_title')) : null,
            'meta_description' => $this->filled('meta_description') ? trim($this->input('meta_description')) : null,
        ];
        if (! $post || $this->boolean('content_changed')) {
            $data['content'] = $this->input('content');
        }

        return $data;
    }
}
