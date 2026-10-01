<?php

namespace Pine\Commerce\Http\Requests\Admin;

use Pine\Commerce\Models\Coupon;
use Pine\Commerce\Services\Admin\LocalTime;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Create/update a discount code. Controllers call $request->couponData() – never $request->all() – so only
 * these normalised fields can ever reach the model (Coupon uses $guarded = ['id']).
 */
class CouponRequest extends FormRequest
{
    public const MONEY_FIELDS = ['amount', 'minimum_spend', 'maximum_spend'];

    public const ID_LISTS = ['product_ids', 'excluded_product_ids', 'category_ids', 'excluded_category_ids'];

    public function authorize(): bool
    {
        return (bool) $this->user()?->canAccessAdmin();
    }

    protected function prepareForValidation(): void
    {
        $clean = [];
        foreach (self::MONEY_FIELDS as $field) {
            if (is_string($this->input($field))) {
                $clean[$field] = trim(str_replace(['£', ',', ' '], '', $this->input($field)));
            }
        }
        if (is_string($this->input('code'))) {
            $clean['code'] = trim($this->input('code'));
        }
        foreach (self::ID_LISTS as $field) {
            if (! $this->filled($field)) {
                $clean[$field] = [];
            }
        }
        $this->merge($clean);
    }

    public function rules(): array
    {
        $coupon = $this->route('coupon');

        return [
            'code' => ['required', 'string', 'min:2', 'max:50', 'regex:/^[A-Za-z0-9][A-Za-z0-9_\-]*$/',
                Rule::unique('coupons', 'code')->ignore($coupon instanceof Coupon ? $coupon->id : null)],
            'description' => ['nullable', 'string', 'max:255'],
            'type' => ['required', Rule::in(array_keys(Coupon::TYPES))],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:99999.99', Rule::when($this->input('type') === 'percent', ['max:100'])],
            'free_shipping' => ['boolean'],
            'minimum_spend' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:999999.99'],
            'maximum_spend' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:999999.99'],
            'individual_use' => ['boolean'],
            'exclude_sale_items' => ['boolean'],
            'is_active' => ['boolean'],
            'product_ids' => ['array', 'max:500'],
            'product_ids.*' => ['integer', Rule::exists('products', 'id')],
            'excluded_product_ids' => ['array', 'max:500'],
            'excluded_product_ids.*' => ['integer', Rule::exists('products', 'id')],
            'category_ids' => ['array', 'max:200'],
            'category_ids.*' => ['integer', Rule::exists('categories', 'id')],
            'excluded_category_ids' => ['array', 'max:200'],
            'excluded_category_ids.*' => ['integer', Rule::exists('categories', 'id')],
            'allowed_emails' => ['nullable', 'string', 'max:10000'],
            'usage_limit' => ['nullable', 'integer', 'min:1', 'max:10000000'],
            'usage_limit_per_user' => ['nullable', 'integer', 'min:1', 'max:10000000'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.regex' => 'Use letters, numbers, dashes or underscores only (no spaces).',
            'code.unique' => 'Another discount already uses this code.',
            'amount.max' => $this->input('type') === 'percent' ? 'A percentage discount can’t be more than 100%.' : 'That amount is too large.',
            '*.decimal' => 'Use at most 2 decimal places.',
            'product_ids.*.exists' => 'One of the chosen products no longer exists.',
            'excluded_product_ids.*.exists' => 'One of the excluded products no longer exists.',
            'category_ids.*.exists' => 'One of the chosen categories no longer exists.',
            'excluded_category_ids.*.exists' => 'One of the excluded categories no longer exists.',
        ];
    }

    public function attributes(): array
    {
        return [
            'minimum_spend' => 'minimum spend',
            'maximum_spend' => 'maximum spend',
            'usage_limit' => 'total uses',
            'usage_limit_per_user' => 'uses per customer',
            'starts_at' => 'start date',
            'expires_at' => 'end date',
            'allowed_emails' => 'allowed emails',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                // Cross-field checks, each skipped when its own fields already failed basic validation.
                $errors = $validator->errors();
                $min = $this->input('minimum_spend');
                $max = $this->input('maximum_spend');
                if (! $errors->hasAny(['minimum_spend', 'maximum_spend']) && is_numeric($min) && is_numeric($max) && (float) $max < (float) $min) {
                    $errors->add('maximum_spend', 'The maximum spend must be more than the minimum spend.');
                }
                if (! $errors->hasAny(['amount', 'free_shipping']) && (float) $this->input('amount') <= 0 && ! $this->boolean('free_shipping')) {
                    $errors->add('amount', 'Enter a discount amount, or turn on free shipping.');
                }
                if (! $errors->hasAny(['starts_at', 'expires_at'])) {
                    $starts = LocalTime::fromInput($this->input('starts_at'));
                    $expires = LocalTime::fromInput($this->input('expires_at'));
                    if ($starts && $expires && $expires->lte($starts)) {
                        $errors->add('expires_at', 'The end date must be after the start date.');
                    }
                }
                if (array_intersect((array) $this->input('product_ids'), (array) $this->input('excluded_product_ids'))) {
                    $errors->add('excluded_product_ids', 'A product can’t be both included and excluded.');
                }
                if (array_intersect((array) $this->input('category_ids'), (array) $this->input('excluded_category_ids'))) {
                    $errors->add('excluded_category_ids', 'A category can’t be both included and excluded.');
                }
                if (! $errors->has('allowed_emails')) {
                    foreach ($this->emails() as $email) {
                        if (! static::validEmailPattern($email)) {
                            $errors->add('allowed_emails', "“{$email}” isn’t a valid email address (use *@example.com to allow a whole domain).");
                            break;
                        }
                    }
                }
            },
        ];
    }

    /** Normalised attributes for Coupon::create()/update(). */
    public function couponData(): array
    {
        $money = fn (string $field) => $this->filled($field) ? round((float) $this->input($field), 2) : null;
        $ids = fn (string $field) => array_values(array_unique(array_map('intval', (array) $this->input($field, [])))) ?: null;

        return [
            'code' => $this->input('code'),
            'description' => $this->input('description'),
            'type' => $this->input('type'),
            'amount' => round((float) $this->input('amount'), 2),
            'free_shipping' => $this->boolean('free_shipping'),
            'minimum_spend' => $money('minimum_spend'),
            'maximum_spend' => $money('maximum_spend'),
            'individual_use' => $this->boolean('individual_use'),
            'exclude_sale_items' => $this->boolean('exclude_sale_items'),
            'is_active' => $this->boolean('is_active'),
            'product_ids' => $ids('product_ids'),
            'excluded_product_ids' => $ids('excluded_product_ids'),
            'category_ids' => $ids('category_ids'),
            'excluded_category_ids' => $ids('excluded_category_ids'),
            'allowed_emails' => $this->emails() ?: null,
            'usage_limit' => $this->filled('usage_limit') ? (int) $this->input('usage_limit') : null,
            'usage_limit_per_user' => $this->filled('usage_limit_per_user') ? (int) $this->input('usage_limit_per_user') : null,
            'starts_at' => LocalTime::fromInput($this->input('starts_at')),
            'expires_at' => LocalTime::fromInput($this->input('expires_at')),
        ];
    }

    /** @return list<string> lower-cased, de-duplicated */
    protected function emails(): array
    {
        $raw = (string) $this->input('allowed_emails', '');

        return array_values(array_unique(array_filter(array_map(
            fn ($e) => mb_strtolower(trim($e)),
            preg_split('/[\s,;]+/', $raw) ?: []
        ))));
    }

    public static function validEmailPattern(string $email): bool
    {
        if (str_starts_with($email, '*@')) {
            return (bool) preg_match('/^\*@[a-z0-9.-]+\.[a-z]{2,}$/i', $email);
        }

        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}
