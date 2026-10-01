<?php

namespace Pine\Commerce\Http\Requests\Admin\Content;

use Pine\Commerce\Models\Page;
use Pine\Commerce\Models\Redirect;
use Pine\Commerce\Services\Catalog\Categories;
use Pine\Commerce\View\Components\MenuComponent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Create/update a redirect. Old addresses are stored the way the storefront looks them up
 * (ResolveController: lower-case path without the leading/trailing slash, e.g. "old-page/sub") and shown as "/old-page/sub/".
 */
class RedirectRequest extends FormRequest
{
    /** Types the storefront's redirect lookup honours. */
    public const TYPES = [
        301 => ['label' => 'Permanent (301)', 'help' => 'The page has moved for good. Google moves its ranking to the new address.'],
        302 => ['label' => 'Temporary (302)', 'help' => 'For short-term changes, e.g. a sale page. Google keeps the old address.'],
        307 => ['label' => 'Temporary, keep method (307)', 'help' => 'Like 302, rarely needed.'],
        308 => ['label' => 'Permanent, keep method (308)', 'help' => 'Like 301, rarely needed.'],
        410 => ['label' => 'Gone (410)', 'help' => 'The page was removed for good and has no replacement. Visitors see a “page removed” page; Google drops the address quickly.'],
    ];

    /** Types the admin form offers (the others stay valid for existing rules and the CSV import). */
    public const FORM_TYPES = [301, 302, 410];

    /** "Gone" - no new address. */
    public const GONE = 410;


    public function authorize(): bool
    {
        return (bool) $this->user()?->canAccessAdmin();
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'from_path' => is_string($this->input('from_path')) ? static::normaliseFrom($this->input('from_path')) : $this->input('from_path'),
            'to_url' => is_string($this->input('to_url')) ? static::normaliseTarget($this->input('to_url')) : $this->input('to_url'),
        ]);
    }

    public function rules(): array
    {
        $redirect = $this->route('redirect');

        return [
            'from_path' => ['required', 'string', 'max:255',
                Rule::unique('redirects', 'from_path')->ignore($redirect instanceof Redirect ? $redirect->id : null)],
            'to_url' => [Rule::requiredIf(fn () => (int) $this->input('status_code') !== self::GONE), 'nullable', 'string', 'max:1000', 'regex:#^(/|https?://|mailto:|tel:)#i', 'not_regex:/^\s*(javascript|data|vbscript):/i'],
            'status_code' => ['required', 'integer', Rule::in(array_keys(self::TYPES))],
            'is_active' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'from_path.required' => 'Enter the old address, e.g. /old-page/.',
            'from_path.unique' => 'There is already a redirect from this address. Edit that one instead.',
            'to_url.required' => 'Enter where visitors should be sent.',
            'to_url.regex' => 'Enter a page address like /new-page/ or a full web address starting with https://',
        ];
    }

    public function attributes(): array
    {
        return ['from_path' => 'old address', 'to_url' => 'new address', 'status_code' => 'type'];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $errors = $validator->errors();
                if ($errors->hasAny(['from_path', 'to_url'])) {
                    return;
                }
                $redirect = $this->route('redirect');
                $problem = static::problem((string) $this->input('from_path'), (string) $this->input('to_url'), $redirect instanceof Redirect ? $redirect->id : null,
                    null, (int) $this->input('status_code') === self::GONE);
                if ($problem) {
                    $errors->add($problem[0], $problem[1]);
                }
            },
        ];
    }

    /** Why a rule can't be saved, as [field, message], or null. Shared with the CSV import. */
    public static function problem(string $from, string $to, ?int $ignoreId = null, ?array $pending = null, bool $gone = false): ?array
    {
        if ($from === '') {
            return ['from_path', 'The home page can’t be redirected.'];
        }
        if (! static::reachesRedirects($from)) {
            return ['from_path', '/'.$from.'/ is handled by the shop itself (basket, checkout, blog, search …), so a redirect there would never be used.'];
        }
        if (Page::where('path', $from)->where('status', 'published')->exists()) {
            return ['from_path', 'A published page lives at /'.$from.'/, so this redirect would never be used. Unpublish or move the page first.'];
        }
        if (($category = Categories::byPath($from)) && $category->is_visible) {
            return ['from_path', 'The category “'.$category->name.'” lives at /'.$from.'/, so this redirect would never be used.'];
        }
        if ($gone) {
            return null; // 410: no target, so no self-redirect or loop
        }
        $target = static::internalPath($to);
        if ($target === $from) {
            return ['to_url', 'A redirect can’t point to itself.'];
        }
        // Follow the chain from the target: A -> B -> … -> A is a loop.
        $map = $pending ?? Redirect::query()->where('is_active', true)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->pluck('to_url', 'from_path')->all();
        $seen = [];
        $hops = 0;
        while ($target !== null && isset($map[$target]) && ! isset($seen[$target]) && $hops < 25) {
            $seen[$target] = true;
            $target = static::internalPath($map[$target]);
            $hops++;
            if ($target === $from) {
                return ['to_url', 'This would create a loop: the new address already redirects back to /'.$from.'/.'];
            }
        }

        return null;
    }

    /** Would a visit to this path reach the redirect lookup (ResolveController), or is it answered by another route first? */
    public static function reachesRedirects(string $from): bool
    {
        if (preg_match('#^(search/|(search/)?page/\d+$)#', $from)) {
            return false; // product search URLs
        }
        try {
            $route = app('router')->getRoutes()->match(\Illuminate\Http\Request::create('/'.$from.'/', 'GET'));

            return $route->getName() === 'resolve';
        } catch (\Throwable) {
            return false;
        }
    }

    /** "/Old-Page/?x=1", "https://www.example.com/old-page" -> "old-page" */
    public static function normaliseFrom(string $input): string
    {
        $input = trim($input);
        if (preg_match('#^(?:https?:)?//[^/]+(.*)$#i', $input, $m)) {
            $input = $m[1];
        }
        $input = preg_replace('/[?#].*$/', '', $input);

        return mb_strtolower(trim(rawurldecode($input), "/ \t\n\r"));
    }

    /** Site-relative targets get a leading slash; links to the old domains become site-relative. */
    public static function normaliseTarget(string $input): string
    {
        $input = trim($input);
        if ($input === '') {
            return '';
        }
        if (preg_match('#^(?:https?:)?//([^/]+)(/.*)?$#i', $input, $m) && in_array(strtolower($m[1]), MenuComponent::legacyHosts(), true)) {
            return $m[2] ?? '/';
        }
        if (preg_match('#^(https?://|mailto:|tel:)#i', $input)) {
            return $input;
        }
        if (preg_match('#^www\.#i', $input)) {
            return 'https://'.$input;
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $input)) {
            return $input; // another scheme – left for validation to reject
        }

        return '/'.ltrim($input, '/');
    }

    /** Path key for a target on this site ("/new-page/" -> "new-page"), null for other sites. */
    public static function internalPath(string $to): ?string
    {
        if (preg_match('#^(?:https?:)?//([^/]+)(/.*)?$#i', $to, $m)) {
            $host = strtolower($m[1]);
            if (! in_array($host, array_merge(MenuComponent::legacyHosts(), [strtolower((string) request()->getHost())]), true)) {
                return null;
            }
            $to = $m[2] ?? '/';
        }
        if (preg_match('#^(mailto|tel):#i', $to)) {
            return null;
        }

        return static::normaliseFrom($to);
    }

    public function redirectData(): array
    {
        return [
            'from_path' => $this->input('from_path'),
            // a 410 rule has no target (the column is NOT NULL: stored as '')
            'to_url' => (int) $this->input('status_code') === self::GONE ? '' : $this->input('to_url'),
            'status_code' => (int) $this->input('status_code'),
            'is_active' => $this->boolean('is_active'),
        ];
    }
}
