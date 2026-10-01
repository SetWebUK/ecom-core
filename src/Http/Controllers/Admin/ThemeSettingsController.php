<?php

namespace Pine\Commerce\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Pine\Commerce\Http\Controllers\Controller;
use Pine\Commerce\Http\Middleware\PreviewTheme;
use Pine\Commerce\Models\Setting;
use Pine\Commerce\Services\Admin\StoreSettings;
use Pine\Commerce\Theme\Theme;
use Pine\Commerce\Theme\ThemeManager;

/**
 * Admin › Settings › Theme.
 *  - Active storefront theme (administrators only; stored as setting "theme.active", which overrides COMMERCE_THEME –
 *    choosing the server's own theme clears the setting). Switching publishes the new theme's assets by copy.
 *  - The theme settings declared in theme.json "settings" (brand colours, fonts, logo …), stored as theme.{slug}.{key}.
 *    ?theme={slug} edits another installed theme's settings, so a theme can be prepared before it is switched on.
 *  - Staff preview links: /?preview_theme={slug} shows the storefront in that theme to the signed-in staff member only.
 */
class ThemeSettingsController extends Controller
{
    public function __construct(protected ThemeManager $themes) {}

    public function edit(Request $request): View
    {
        $editing = $this->editingTheme($request);

        return view('commerce::admin.settings.theme', [
            'group' => 'theme',
            'config' => StoreSettings::groups()['theme'],
            'themes' => $this->themes->all(),
            'active' => $this->themes->configuredSlug(),
            'serverTheme' => (string) config('commerce.theme', 'default'),
            'editing' => $editing,
            'fields' => $this->fields($editing),
            'canSwitch' => (bool) $request->user()?->isAdmin(),
            'previewParam' => PreviewTheme::PARAM,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $editing = $this->editingTheme($request);
        $fields = $this->fields($editing);
        $slugs = array_keys($this->themes->all());
        $rules = ['theme' => ['nullable', 'string', Rule::in($slugs)]];
        foreach ($fields as $field) {
            $rules[StoreSettings::inputName($field['key'])] = $this->rule($field);
        }
        if ($request->user()?->isAdmin()) {
            // only themes whose parent chain resolves can go live (a missing parent / cycle would break every page)
            $rules['active_theme'] = ['nullable', 'string', Rule::in(array_values(array_filter($slugs, fn ($s) => $this->themes->usable($s))))];
        }
        $data = $request->validate($rules, ['*.regex' => 'Use a hex colour such as #1d4ed8.']);

        $changed = 0;
        foreach ($fields as $field) {
            $name = StoreSettings::inputName($field['key']);
            if (! array_key_exists($name, $data) && $field['type'] !== 'bool') {
                continue;
            }
            $new = $field['type'] === 'color' ? strtolower(trim((string) ($data[$name] ?? ''))) : StoreSettings::normalise($field, $data[$name] ?? null);
            $current = StoreSettings::value($field);
            $same = $field['type'] === 'bool' ? $new === (bool) $current : (string) $new === (string) $current;
            if (! $same) {
                Setting::set($field['key'], $new, 'theme');
                $changed++;
            }
        }

        $message = $changed ? 'Theme settings saved. The website uses them straight away.' : 'Nothing changed.';
        $switched = null;
        if ($request->user()?->isAdmin() && filled($data['active_theme'] ?? null) && $data['active_theme'] !== $this->themes->configuredSlug()) {
            $switched = $data['active_theme'];
            // the server's own theme (COMMERCE_THEME) needs no override
            Setting::set('theme.active', $switched === (string) config('commerce.theme', 'default') ? '' : $switched, 'theme');
            try {
                Artisan::call('commerce:theme:publish', ['slug' => $switched]);
            } catch (\Throwable $e) {
                Log::error('Publishing theme assets failed after switching theme', ['theme' => $switched, 'error' => $e->getMessage()]);
            }
            Log::info('Storefront theme switched in the back office', ['theme' => $switched, 'user' => $request->user()->id]);
            $message = 'The storefront now uses the “'.$this->themes->get($switched)->name.'” theme.';
        }

        return redirect()->route('admin.settings.theme', $editing->slug !== $this->themes->configuredSlug() || $switched ? ['theme' => $editing->slug] : [])
            ->with('success', $message);
    }

    protected function editingTheme(Request $request): Theme
    {
        $slug = (string) $request->input('theme', $request->query('theme', ''));

        return $slug !== '' && $this->themes->usable($slug) ? $this->themes->get($slug) : $this->themes->get($this->themes->configuredSlug());
    }

    /**
     * theme.json settings of a theme (its own + inherited, unless "settings_inherit": false) as StoreSettings fields.
     *
     * @return list<array>
     */
    public function fields(Theme $theme): array
    {
        $schema = ($theme->manifest()['settings_inherit'] ?? true) === false ? (array) ($theme->manifest()['settings'] ?? []) : $theme->settingsSchema();
        $fields = [];
        foreach ($schema as $key => $def) {
            if (! is_array($def)) {
                continue;
            }
            $type = in_array($def['type'] ?? 'text', ['text', 'textarea', 'bool', 'int', 'image', 'link', 'color', 'select'], true) ? $def['type'] : 'text';
            $fields[] = array_filter([
                'key' => $theme->settingKey((string) $key),
                'label' => (string) ($def['label'] ?? ucfirst(str_replace('_', ' ', (string) $key))),
                'type' => $type,
                'default' => $def['default'] ?? null,
                'help' => $def['help'] ?? null,
                'options' => $def['options'] ?? null,
                'min' => $def['min'] ?? null,
                'max' => $def['max'] ?? null,
                'group' => $def['group'] ?? 'Theme',
            ], fn ($v) => $v !== null);
        }

        return $fields;
    }

    protected function rule(array $field): array
    {
        return match ($field['type']) {
            'bool' => ['nullable', 'boolean'],
            'int' => ['nullable', 'integer', 'min:'.($field['min'] ?? 0), 'max:'.($field['max'] ?? 1000000)],
            'color' => ['nullable', 'string', 'regex:/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
            'image' => ['nullable', 'string', 'max:500', 'regex:#^(uploads/|https?://|/)[^\s<>"\']*$#i'],
            'link' => ['nullable', 'string', 'max:500', 'not_regex:/^\s*(javascript|data|vbscript):/i'],
            'select' => ['nullable', 'string', Rule::in(array_map('strval', array_keys($field['options'] ?? [])))],
            'textarea' => ['nullable', 'string', 'max:5000'],
            default => ['nullable', 'string', 'max:255'],
        };
    }
}
