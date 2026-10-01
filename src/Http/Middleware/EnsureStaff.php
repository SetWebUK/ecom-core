<?php

namespace Pine\Commerce\Http\Middleware;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Gatekeeper for every /admin page (alias "admin", registered in bootstrap/app.php).
 *
 *   Route::middleware('admin')        -> any active staff member (role admin|manager)
 *   Route::middleware('admin:admin')  -> administrators only (staff users, payment settings…)
 *
 * - Guests are sent to the admin login page (the intended URL is remembered).
 * - Signed-in customers get an admin-styled 403 page.
 * - Staff whose account was deactivated mid-session are signed out.
 * - Error responses raised inside admin controllers (403/404/419/500…) are re-rendered with the admin
 *   error page instead of the storefront's, and every admin response is marked private + noindex.
 */
class EnsureStaff
{
    public function handle(Request $request, Closure $next, ?string $role = null): Response
    {
        $user = $request->user();

        if (! $user) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Your session has expired. Please sign in again.'], 401);
            }

            if (! $request->isMethod('GET') && ! $request->isMethod('HEAD') && $request->hasSession()) {
                // Signed out while a form was open (session timed out): come back to that page after signing in, not to the
                // POST-only address, and say that the change wasn't saved.
                $admin = url('/admin');
                $back = url()->previous();
                $request->session()->put('url.intended', $back === $admin || str_starts_with($back, $admin.'/') ? $back : route('admin.dashboard'));

                return redirect()->route('admin.login')->with('warning', 'You were signed out, so your last change wasn’t saved. Sign in again, then redo it.');
            }

            return redirect()->guest(route('admin.login'));
        }

        if ($user->isStaff() && ! $user->is_active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('admin.login')->withErrors(['email' => 'Your staff account has been deactivated. Ask an administrator to re-enable it.']);
        }

        if (! $user->canAccessAdmin()) {
            return $this->error($request, 403, "Your account ({$user->email}) doesn't have access to the back office.");
        }

        if ($role === 'admin' && ! $user->isAdmin()) {
            return $this->error($request, 403, 'Only administrators can open this page.');
        }

        $response = $next($request);

        $response = $this->replaceErrorPage($request, $response);

        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    /** Swap the storefront error page for the admin one when a controller aborts. */
    protected function replaceErrorPage(Request $request, Response $response): Response
    {
        $status = $response->getStatusCode();
        $exception = property_exists($response, 'exception') ? $response->exception : null;

        if ($status < 400 || ! $exception instanceof Throwable || $request->expectsJson()) {
            return $response;
        }
        if ($status >= 500 && config('app.debug')) {
            return $response; // keep the debug page while developing
        }

        $message = match (true) {
            $exception instanceof ModelNotFoundException => 'That record no longer exists – it may have been deleted.',
            $exception instanceof AuthorizationException => $exception->getMessage() ?: 'You are not allowed to do that.',
            $exception instanceof HttpExceptionInterface && $exception->getMessage() !== '' && $status < 500 => $exception->getMessage(),
            default => null,
        };

        return $this->error($request, $status, $message);
    }

    /**
     * "Page expired" (419 – the form's CSRF token is stale, e.g. the tab was left open for hours) inside /admin: instead of
     * the framework's bare error page, go back to the form with what was typed (never passwords or payment secrets) and a
     * toast explaining that nothing was saved. Registered in bootstrap/app.php (withExceptions → render).
     */
    public static function expiredSession(HttpExceptionInterface $e, Request $request): ?Response
    {
        if ($e->getStatusCode() !== 419 || ! $request->is('admin', 'admin/*') || $request->expectsJson() || ! $request->hasSession()) {
            return null;
        }

        $admin = url('/admin');
        $back = url()->previous();
        if ($back === '' || ! ($back === $admin || str_starts_with($back, $admin.'/'))) {
            $back = route('admin.dashboard');
        }

        $redirect = redirect()->to($back)->with('warning', 'Your session timed out, so that wasn’t saved. Please check the page and try again.');

        // Only refill the form for our own pages – a forged cross-site post must never pre-fill a staff member's form.
        $origin = $request->headers->get('Origin');
        $sameOrigin = $request->headers->get('Sec-Fetch-Site') === 'same-origin'
            || ($origin && rtrim($origin, '/') === $request->getSchemeAndHttpHost());
        if ($sameOrigin) {
            $redirect->withInput(\Illuminate\Support\Arr::except($request->except(['_token', '_method']), [
                'password', 'password_confirmation', 'current_password',
                'payments.stripe.secret_key', 'payments.stripe.webhook_secret', 'payments.paypal.secret',
            ]));
        }

        return $redirect;
    }

    protected function error(Request $request, int $status, ?string $message = null): Response
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message ?? 'Something went wrong.'], $status);
        }

        return response()->view('commerce::admin.layouts.error', ['status' => $status, 'message' => $message], $status)
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Cache-Control', 'no-store, private');
    }
}
