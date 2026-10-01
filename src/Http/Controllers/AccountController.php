<?php

namespace Pine\Commerce\Http\Controllers;

use Pine\Commerce\Http\Controllers\Auth\AuthController;
use Pine\Commerce\Models\Address;
use Pine\Commerce\Models\Order;
use Pine\Commerce\Models\Product;
use Pine\Commerce\Models\User;
use Pine\Commerce\Models\WishlistItem;
use Pine\Commerce\Services\Checkout\CheckoutService;
use Pine\Commerce\Support\WpPassword;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * WooCommerce "My account" endpoints: dashboard (or login/register for guests), orders, view order,
 * addresses, account details, downloads and the wishlist. Customers only ever see their own orders.
 */
class AccountController extends Controller
{
    public const ORDERS_PER_PAGE = 10;

    public function dashboard(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            return response(theme_view('auth.login', [
                'registration' => AuthController::registrationEnabled(),
                'redirect' => $this->safeRedirect(is_string($request->query('redirect')) ? $request->query('redirect') : ''),
            ]))->header('Cache-Control', 'no-store, private');
        }

        return $this->page('account.dashboard', 'dashboard', ['user' => $user]);
    }

    public function orders(Request $request, int $page = 1)
    {
        $orders = Order::where('user_id', $request->user()->id)
            ->withCount('items')
            ->withSum('items', 'quantity')
            ->latest('created_at')->latest('id')
            ->paginate(self::ORDERS_PER_PAGE, ['*'], 'page', max(1, $page));
        if ($page > 1 && $orders->isEmpty()) {
            return redirect()->to(route('account.orders'));
        }

        return $this->page('account.orders', 'orders', ['orders' => $orders]);
    }

    public function viewOrder(Request $request, string $number)
    {
        $order = Order::where('number', $number)->where('user_id', $request->user()->id)
            ->with(['items.product.images', 'items.variation', 'notes' => fn ($q) => $q->where('is_customer_note', true)])
            ->first();
        if (! $order) {
            return $this->page('account.invalid-order', 'orders', [], 404);
        }

        return $this->page('account.view-order', 'orders', ['order' => $order]);
    }

    public function downloads(Request $request)
    {
        return $this->page('account.downloads', 'downloads');
    }

    // ------------------------------------------------------------------ addresses

    public function addresses(Request $request)
    {
        $addresses = $request->user()->addresses()->where('is_default', true)->get()->keyBy('type');

        return $this->page('account.addresses', 'edit-address', ['addresses' => $addresses]);
    }

    public function editAddress(Request $request, string $type)
    {
        $address = $request->user()->addresses()->where('type', $type)->where('is_default', true)->first();

        return $this->page('account.edit-address', 'edit-address', [
            'type' => $type,
            'address' => $address,
            'values' => $this->addressValues($request->user(), $type, $address),
        ]);
    }

    public function saveAddress(Request $request, string $type)
    {
        $p = $type;
        $rules = [
            "{$p}_first_name" => ['required', 'string', 'max:100'],
            "{$p}_last_name" => ['required', 'string', 'max:100'],
            "{$p}_company" => ['nullable', 'string', 'max:150'],
            "{$p}_country" => ['required', 'in:'.implode(',', array_keys(CheckoutService::countries()))],
            "{$p}_address_1" => ['required', 'string', 'max:190'],
            "{$p}_address_2" => ['nullable', 'string', 'max:190'],
            "{$p}_city" => ['required', 'string', 'max:100'],
            "{$p}_state" => ['nullable', 'string', 'max:100'],
            "{$p}_postcode" => ['required', 'string', 'max:12'],
            "{$p}_phone" => [$type === 'billing' ? 'required' : 'nullable', 'string', 'max:40'],
        ];
        if ($type === 'billing') {
            $rules['billing_email'] = ['required', 'email:rfc', 'max:190'];
        }
        $label = ucfirst($type);
        $data = $request->validate($rules, ['required' => ':attribute is a required field.'], [
            "{$p}_first_name" => "$label First name", "{$p}_last_name" => "$label Last name", "{$p}_country" => "$label Country / Region",
            "{$p}_address_1" => "$label Street address", "{$p}_city" => "$label Town / City", "{$p}_postcode" => "$label Postcode",
            "{$p}_phone" => "$label Phone", 'billing_email' => 'Billing Email address',
        ]);

        $postcode = CheckoutService::validPostcode((string) $data["{$p}_postcode"], (string) $data["{$p}_country"])
            ? CheckoutService::formatPostcode((string) $data["{$p}_postcode"], (string) $data["{$p}_country"]) : null;
        $errors = [];
        if (! $postcode) {
            $errors["{$p}_postcode"] = 'Please enter a valid postcode / ZIP.';
        }
        if (! empty($data["{$p}_phone"]) && ! CheckoutService::validPhone((string) $data["{$p}_phone"])) {
            $errors["{$p}_phone"] = "$label Phone is not a valid phone number.";
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $values = [
            'first_name' => trim($data["{$p}_first_name"]),
            'last_name' => trim($data["{$p}_last_name"]),
            'company' => trim((string) ($data["{$p}_company"] ?? '')) ?: null,
            'country' => $data["{$p}_country"],
            'address_1' => trim($data["{$p}_address_1"]),
            'address_2' => trim((string) ($data["{$p}_address_2"] ?? '')) ?: null,
            'city' => trim($data["{$p}_city"]),
            'county' => trim((string) ($data["{$p}_state"] ?? '')) ?: null,
            'postcode' => $postcode,
            'phone' => trim((string) ($data["{$p}_phone"] ?? '')) ?: null,
            'email' => $type === 'billing' ? mb_strtolower($data['billing_email']) : null,
        ];
        $address = $request->user()->addresses()->where('type', $type)->where('is_default', true)->first();
        if ($address) {
            $address->forceFill($values)->save();
        } else {
            $address = new Address;
            $address->forceFill($values + ['user_id' => $request->user()->id, 'type' => $type, 'is_default' => true])->save();
        }

        return redirect()->to(route('account.addresses'))->with('account_message', 'Address changed successfully.');
    }

    protected function addressValues(User $user, string $type, ?Address $address): array
    {
        $old = fn ($field, $fallback) => old("{$type}_{$field}", $fallback ?? '');

        return [
            'first_name' => $old('first_name', $address->first_name ?? $user->first_name),
            'last_name' => $old('last_name', $address->last_name ?? $user->last_name),
            'company' => $old('company', $address?->company),
            'country' => $old('country', $address->country ?? 'GB'),
            'address_1' => $old('address_1', $address?->address_1),
            'address_2' => $old('address_2', $address?->address_2),
            'city' => $old('city', $address?->city),
            'state' => $old('state', $address?->county),
            'postcode' => $old('postcode', $address?->postcode),
            'phone' => $old('phone', $address->phone ?? $user->phone),
            'email' => $old('email', $address->email ?? $user->email),
        ];
    }

    // ------------------------------------------------------------------ account details

    public function details(Request $request)
    {
        return $this->page('account.edit-account', 'edit-account', ['user' => $request->user()]);
    }

    public function saveDetails(Request $request)
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate([
            'account_first_name' => ['required', 'string', 'max:100'],
            'account_last_name' => ['required', 'string', 'max:100'],
            'account_display_name' => ['required', 'string', 'max:190'],
            'account_email' => ['required', 'email:rfc', 'max:190'],
            'password_current' => ['nullable', 'string', 'max:4096'],
            'password_1' => ['nullable', 'string', 'min:8', 'max:255'],
            'password_2' => ['nullable', 'string', 'max:255'],
        ], [
            'required' => ':attribute is a required field.',
            'password_1.min' => 'Please enter a password of at least 8 characters.',
        ], [
            'account_first_name' => 'First name', 'account_last_name' => 'Last name',
            'account_display_name' => 'Display name', 'account_email' => 'Email address',
        ]);

        $email = mb_strtolower($data['account_email']);
        $errors = [];
        if ($email !== mb_strtolower((string) $user->email)
            && User::whereRaw('LOWER(email) = ?', [$email])->where('id', '!=', $user->id)->exists()) {
            $errors['account_email'] = 'This email address is already registered.';
        }

        $changingPassword = filled($data['password_1'] ?? null) || filled($data['password_2'] ?? null);
        if ($changingPassword || filled($data['password_current'] ?? null)) {
            $key = 'account-password:'.$user->id;
            if (RateLimiter::tooManyAttempts($key, 5)) {
                throw ValidationException::withMessages(['password_current' => 'Too many attempts. Please try again in a few minutes.']);
            }
            if (! filled($data['password_current'] ?? null)) {
                $errors['password_current'] = 'Please enter your current password.';
            } elseif ($user->password && ! $this->passwordMatches($user, (string) $data['password_current'])) {
                RateLimiter::hit($key, 300);
                $errors['password_current'] = 'Your current password is incorrect.';
            }
            if (! filled($data['password_1'] ?? null)) {
                $errors['password_1'] = 'Please fill out all password fields.';
            } elseif (($data['password_1'] ?? '') !== ($data['password_2'] ?? '')) {
                $errors['password_2'] = 'New passwords do not match.';
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $user->forceFill([
            'first_name' => trim($data['account_first_name']),
            'last_name' => trim($data['account_last_name']),
            'name' => trim($data['account_display_name']),
            'email' => $email,
        ]);
        if ($changingPassword) {
            $user->password = Hash::make($data['password_1']);
            $user->setRememberToken(\Illuminate\Support\Str::random(60));
        }
        $user->save();
        if ($changingPassword) {
            $request->session()->regenerate();
        }

        return redirect()->to(route('account'))->with('account_message', 'Account details changed successfully.');
    }

    protected function passwordMatches(User $user, string $password): bool
    {
        $hash = (string) $user->password;
        try {
            if (password_get_info($hash)['algoName'] !== 'unknown' && ! str_starts_with($hash, '$wp') && Hash::check($password, $hash)) {
                return true;
            }
        } catch (\RuntimeException) {
        }

        return WpPassword::check($password, $hash);
    }

    // ------------------------------------------------------------------ wishlist

    public function wishlist(Request $request)
    {
        $items = WishlistItem::where('user_id', $request->user()->id)
            ->whereHas('product', fn ($q) => $q->published())
            ->with(['product' => fn ($q) => $q->with(['images', 'primaryCategory'])])
            ->latest()->get();

        return $this->page('account.wishlist', 'wishlist', ['items' => $items]);
    }

    /** POST /wishlist/toggle - product_id. JSON for AJAX buttons, redirect otherwise. */
    public function toggleWishlist(Request $request)
    {
        $request->validate(['product_id' => ['required', 'integer']]);
        $user = $request->user();
        if (! $user) {
            $login = route('account', ['redirect' => '/'.ltrim(parse_url(url()->previous(), PHP_URL_PATH) ?? '', '/')]);

            return $request->expectsJson()
                ? response()->json(['ok' => false, 'login' => $login, 'message' => 'Please log in to save products to your wishlist.'], 401)
                : redirect()->to($login)->with('account_message', 'Please log in to save products to your wishlist.');
        }
        $product = Product::published()->find((int) $request->input('product_id'));
        if (! $product) {
            return $request->expectsJson() ? response()->json(['ok' => false, 'message' => 'Product not found.'], 404) : back();
        }

        $existing = WishlistItem::where('user_id', $user->id)->where('product_id', $product->id)->first();
        if ($existing) {
            $existing->delete();
            $added = false;
        } else {
            $item = new WishlistItem;
            $item->forceFill(['user_id' => $user->id, 'product_id' => $product->id])->save();
            $added = true;
        }
        $message = $added ? sprintf('“%s” added to your wishlist.', $product->name) : sprintf('“%s” removed from your wishlist.', $product->name);
        $count = WishlistItem::where('user_id', $user->id)->count();

        return $request->expectsJson()
            ? response()->json(['ok' => true, 'in_wishlist' => $added, 'count' => $count, 'message' => $message])
            : back()->with('account_message', $message);
    }

    // ------------------------------------------------------------------ helpers

    protected function page(string $view, string $endpoint, array $data = [], int $status = 200)
    {
        return response(theme_view($view, $data + [
            'endpoint' => $endpoint,
            'user' => request()->user(),
            'message' => session('account_message'),
        ]), $status)->header('Cache-Control', 'no-store, private');
    }

    protected function safeRedirect(string $target): string
    {
        return preg_match('#^/(?![/\\\\])[^\\\\\s\x00-\x1f]*$#', $target) ? $target : ''; // same rule as AuthController::redirectTarget()
    }
}
