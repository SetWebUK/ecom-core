<?php

namespace Pine\Commerce\Http\Requests\Admin\Content;

use Pine\Commerce\Models\Menu;
use Illuminate\Validation\Validator;

/**
 * Save a whole menu: name, location and the item tree posted as JSON ("tree"):
 *   [{id|null, label, url, badge, icon, css_class, open_in_new_tab, children: [...]}, ...]   (max 3 levels, 500 items)
 * tree() returns the validated, normalised nodes.
 */
class MenuTreeRequest extends MenuRequest
{
    public const MAX_DEPTH = 3;

    public const MAX_ITEMS = 500;

    /** @var array<int, array>|null */
    protected ?array $nodes = null;

    public function rules(): array
    {
        return parent::rules() + [
            'tree' => ['required', 'string', 'max:1000000', 'json'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->has('tree')) {
                    return;
                }
                $decoded = json_decode((string) $this->input('tree'), true);
                if (! is_array($decoded) || ($decoded !== [] && ! array_is_list($decoded))) {
                    $validator->errors()->add('tree', 'The menu could not be read. Reload the page and try again.');

                    return;
                }
                /** @var Menu $menu */
                $menu = $this->route('menu');
                $ownIds = $menu instanceof Menu ? $menu->items()->pluck('id')->all() : [];
                $count = 0;
                $seen = [];
                $problems = [];
                $this->nodes = $this->clean($decoded, 1, $ownIds, $count, $seen, $problems);
                if ($count > self::MAX_ITEMS) {
                    $problems[] = 'A menu can have at most '.self::MAX_ITEMS.' links.';
                }
                foreach (array_slice(array_unique($problems), 0, 5) as $problem) {
                    $validator->errors()->add('tree', $problem);
                }
            },
        ];
    }

    protected function clean(array $nodes, int $depth, array $ownIds, int &$count, array &$seen, array &$problems): array
    {
        $out = [];
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }
            $count++;
            $label = trim((string) ($node['label'] ?? ''));
            $name = $label !== '' ? '“'.mb_strimwidth(strip_tags($label), 0, 40, '…').'”' : 'An item';
            if ($depth > self::MAX_DEPTH) {
                $problems[] = "{$name} is nested too deep – menus can have 3 levels.";
            }
            if ($label === '') {
                $problems[] = 'Every menu item needs a label.';
            } elseif (mb_strlen($label) > 500) {
                $problems[] = "{$name}: the label is too long.";
            }
            $url = trim((string) ($node['url'] ?? ''));
            if (mb_strlen($url) > 1000) {
                $problems[] = "{$name}: the link is too long.";
            } elseif ($url !== '' && preg_match('/^\s*(javascript|data|vbscript):/i', $url)) {
                $problems[] = "{$name}: that kind of link isn’t allowed.";
            }
            $icon = trim((string) ($node['icon'] ?? ''));
            if ($icon !== '' && (mb_strlen($icon) > 500 || ! preg_match('#^(uploads/|https?://|/)[^\s<>"\']*$#i', $icon))) {
                $problems[] = "{$name}: choose the image from the media library.";
            }
            $class = trim((string) ($node['css_class'] ?? ''));
            if ($class !== '' && ! preg_match('/^[A-Za-z0-9 _-]{1,190}$/', $class)) {
                $problems[] = "{$name}: the style class can only contain letters, numbers, spaces, dashes and underscores.";
            }
            $badge = trim((string) ($node['badge'] ?? ''));
            if (mb_strlen($badge) > 40) {
                $problems[] = "{$name}: the badge is too long (40 characters max).";
            }
            $id = isset($node['id']) && is_numeric($node['id']) ? (int) $node['id'] : null;
            if ($id !== null && (! in_array($id, $ownIds, true) || isset($seen[$id]))) {
                $id = null; // not ours (or duplicated) – save as a new item
            }
            if ($id !== null) {
                $seen[$id] = true;
            }

            $out[] = [
                'id' => $id,
                'label' => $label,
                'url' => $url !== '' ? $url : null,
                'badge' => $badge !== '' ? $badge : null,
                'icon' => $icon !== '' ? $icon : null,
                'css_class' => $class !== '' ? $class : null,
                'open_in_new_tab' => filter_var($node['open_in_new_tab'] ?? false, FILTER_VALIDATE_BOOL),
                'children' => $this->clean(is_array($node['children'] ?? null) ? $node['children'] : [], $depth + 1, $ownIds, $count, $seen, $problems),
            ];
        }

        return $out;
    }

    /** @return array<int, array> */
    public function tree(): array
    {
        return $this->nodes ?? [];
    }
}
