<?php

namespace Pine\Commerce\Http\Controllers;

use Pine\Commerce\Mail\ContactSubmitted;
use Pine\Commerce\Models\FormSubmission;
use Pine\Commerce\Models\NewsletterSubscriber;
use Pine\Commerce\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

/**
 * Front-end forms: contact enquiry ([contact_form]), footer newsletter sign-up and the
 * WooCommerce-style order tracking form on /orders-and-returns/.
 */
class ContactController extends Controller
{
    public function submit(Request $request): RedirectResponse
    {
        $back = $this->backUrl($request, 'contact-form');
        $p = (string) config('commerce.forms.contact.prefix', 'cf_'); // field name prefix (clients may keep a legacy prefix)

        // Honeypot: pretend everything went fine so bots learn nothing.
        if (filled($request->input($p.'company'))) {
            return redirect()->to($back)->with('contact_status', $this->successMessage());
        }

        $validator = Validator::make($request->all(), [
            $p.'name' => ['required', 'string', 'max:120'],
            $p.'email' => ['required', 'string', 'email:rfc', 'max:190'],
            $p.'phone' => ['nullable', 'string', 'max:40'],
            $p.'subject' => ['nullable', 'string', 'max:160'],
            $p.'message' => ['required', 'string', 'max:5000'],
        ], [], [
            $p.'name' => 'name', $p.'email' => 'email address', $p.'phone' => 'phone number',
            $p.'subject' => 'subject', $p.'message' => 'message',
        ]);

        if ($validator->fails()) {
            return redirect()->to($back)->withErrors($validator, 'contact')->withInput($request->except($p.'company'));
        }
        $data = $validator->validated();

        $submission = FormSubmission::create([
            'form' => 'contact',
            'name' => trim($data[$p.'name']),
            'email' => strtolower(trim($data[$p.'email'])),
            'phone' => filled($data[$p.'phone'] ?? null) ? trim($data[$p.'phone']) : null,
            'subject' => filled($data[$p.'subject'] ?? null) ? trim($data[$p.'subject']) : 'Website enquiry',
            'message' => trim($data[$p.'message']),
            'data' => [
                'page_url' => $this->sameHostUrl(is_string($request->input('page_url')) ? $request->input('page_url') : '') ?? url()->previous(),
                'user_agent' => substr((string) $request->userAgent(), 0, 255),
            ],
            'ip_address' => $request->ip(),
        ]);

        try {
            Mail::to(setting('store.email', config('mail.from.address')))->send(new ContactSubmitted($submission));
        } catch (\Throwable $e) {
            // The enquiry is stored (admin: Form submissions) - never lose it because the mailer failed.
            Log::error('Contact form email failed', ['submission' => $submission->id, 'error' => $e->getMessage()]);
        }

        return redirect()->to($back)->with('contact_status', $this->successMessage());
    }

    public function newsletter(Request $request)
    {
        if (filled($request->input('website'))) { // honeypot
            return $this->newsletterResponse($request, $this->newsletterMessage());
        }

        $validator = Validator::make($request->all(), [
            'email' => ['required', 'string', 'email:rfc', 'max:190'],
            'source' => ['nullable', 'string', 'max:50'],
        ], [
            'email.required' => 'Please enter your email address.',
            'email.email' => 'Please enter a valid email address.',
        ]);
        if ($validator->fails()) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
            }

            return redirect()->to($this->backUrl($request, 'form-field-email'))->withErrors($validator, 'newsletter')->withInput($request->only('email'));
        }
        $data = $validator->validated();

        $email = strtolower(trim($data['email']));
        $subscriber = NewsletterSubscriber::firstOrNew(['email' => $email]);
        if (! $subscriber->exists || $subscriber->unsubscribed_at) {
            $subscriber->source = $data['source'] ?? 'footer';
            $subscriber->unsubscribed_at = null;
            $subscriber->save();
        }

        return $this->newsletterResponse($request, $this->newsletterMessage());
    }

    public function trackOrder(Request $request): RedirectResponse
    {
        $back = $this->backUrl($request, 'order-tracking', url('orders-and-returns'));
        $validator = Validator::make($request->all(), [
            'orderid' => ['required', 'string', 'max:40'],
            'order_email' => ['required', 'string', 'email:rfc', 'max:190'],
        ], [
            'orderid.required' => 'Please enter a valid order ID',
            'order_email.required' => 'Please enter a valid email address',
            'order_email.email' => 'Please enter a valid email address',
        ]);
        if ($validator->fails()) {
            return redirect()->to($back)->withErrors($validator, 'tracking')->withInput();
        }

        $number = ltrim(trim((string) $request->input('orderid')), '#');
        $email = strtolower(trim((string) $request->input('order_email')));
        $order = Order::where(fn ($q) => $q->where('number', $number)->orWhere(fn ($q) => ctype_digit($number) ? $q->where('wp_id', (int) $number) : $q->whereRaw('1 = 0')))
            ->whereRaw('LOWER(email) = ?', [$email])
            ->first();

        if (! $order) {
            return redirect()->to($back)->withErrors(['orderid' => 'Sorry, the order could not be found. Please contact us if you are having difficulty finding your order details.'], 'tracking')->withInput();
        }

        return redirect()->to($back)->with('tracked_order_id', $order->id);
    }

    protected function newsletterResponse(Request $request, string $message)
    {
        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'message' => $message]);
        }

        return redirect()->to($this->backUrl($request, 'form-field-email'))->with('newsletter_status', $message);
    }

    protected function successMessage(): string
    {
        return 'Thanks. Your enquiry has been sent and our team will get back to you as soon as possible.';
    }

    protected function newsletterMessage(): string
    {
        return (string) setting('newsletter.success_message', 'Your submission was successful.');
    }

    /** Previous page on this site (never an open redirect), with an optional #fragment. */
    protected function backUrl(Request $request, ?string $fragment = null, ?string $fallback = null): string
    {
        $url = $this->sameHostUrl(is_string($request->input('page_url')) ? $request->input('page_url') : '') ?? $this->sameHostUrl(url()->previous()) ?? $fallback ?? url('/').'/';
        $url = strtok($url, '#');
        // legacy URLs end in "/" - add it here rather than bouncing through the TrailingSlash redirect
        $path = parse_url($url, PHP_URL_PATH) ?? '/';
        if (\Pine\Commerce\Http\Middleware\TrailingSlash::shouldHaveSlash($path) && ! str_ends_with($path, '/')) {
            $query = parse_url($url, PHP_URL_QUERY);
            $url = preg_replace('#[?].*$#', '', $url).'/'.($query ? '?'.$query : '');
        }

        return $url.($fragment ? '#'.$fragment : '');
    }

    protected function sameHostUrl(string $url): ?string
    {
        if ($url === '' || ! preg_match('#^https?://#i', $url)) {
            return null;
        }

        return parse_url($url, PHP_URL_HOST) === request()->getHost() ? $url : null;
    }
}
