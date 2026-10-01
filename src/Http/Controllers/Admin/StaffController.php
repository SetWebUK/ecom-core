<?php

namespace Pine\Commerce\Http\Controllers\Admin;

use Pine\Commerce\Http\Controllers\Admin\Concerns\AdminIndex;
use Pine\Commerce\Http\Controllers\Controller;
use Pine\Commerce\Http\Requests\Admin\Content\StaffRequest;
use Pine\Commerce\Models\User;
use Pine\Commerce\Notifications\AdminResetPassword;
use Pine\Commerce\Services\Admin\StaffPassword;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

/**
 * Staff accounts (administrators only): who can sign in to /admin. Staff are users with role admin|manager.
 * New staff either get an email link to choose their own password, or the administrator sets one.
 * "Remove access" turns the account back into a customer account (orders and notes keep their author).
 */
class StaffController extends Controller
{
    use AdminIndex;

    public function index(Request $request): View
    {
        $status = $this->filterValue($request, 'status', ['active' => 1, 'inactive' => 1]) ?? 'all';

        $staff = User::query()
            ->whereIn('role', ['admin', 'manager'])
            ->when($status === 'active', fn ($q) => $q->where('is_active', true))
            ->when($status === 'inactive', fn ($q) => $q->where('is_active', false))
            ->orderByDesc('is_active')->orderByRaw("role = 'admin' desc")->orderBy('name')
            ->get(['id', 'name', 'first_name', 'last_name', 'email', 'role', 'is_active', 'last_login_at', 'created_at']);

        $counts = User::query()->whereIn('role', ['admin', 'manager'])->selectRaw('count(*) as total, sum(is_active = 1) as active')->first();

        return view('commerce::admin.staff.index', [
            'staff' => $staff,
            'status' => $status,
            'tabs' => [
                'all' => ['label' => 'All', 'count' => (int) $counts->total],
                'active' => ['label' => 'Active', 'count' => (int) $counts->active],
                'inactive' => ['label' => 'Switched off', 'count' => (int) $counts->total - (int) $counts->active],
            ],
        ]);
    }

    public function create(): View
    {
        return view('commerce::admin.staff.form', ['member' => new User(['role' => 'manager', 'is_active' => true])]);
    }

    public function store(StaffRequest $request): RedirectResponse
    {
        $invite = $request->input('password_mode') === 'invite';
        $member = DB::transaction(fn () => User::forceCreate($request->staffData() + [
            'password' => Hash::make($invite ? Str::random(48) : $request->input('password')),
        ]));

        if ($invite) {
            $sent = $this->sendLink($member);

            return redirect()->route('admin.staff.index')->with($sent ? 'success' : 'warning', $sent
                ? "{$member->full_name} added. We’ve emailed {$member->email} a link to choose a password."
                : "{$member->full_name} added, but the email couldn’t be sent. Use “Send password link” on their account to try again.");
        }

        return redirect()->route('admin.staff.index')->with('success', "{$member->full_name} added. Give them their password in person – they can change it on their profile.");
    }

    public function edit(User $staff): View
    {
        abort_unless($staff->isStaff(), 404);

        return view('commerce::admin.staff.form', [
            'member' => $staff,
            'isSelf' => $staff->is(auth()->user()),
            'lastAdmin' => $staff->role === 'admin' && $staff->is_active && StaffRequest::activeAdmins($staff->id) === 0,
        ]);
    }

    public function update(StaffRequest $request, User $staff): RedirectResponse
    {
        abort_unless($staff->isStaff(), 404);
        $data = $request->staffData();
        $wasActive = $staff->is_active;
        $staff->forceFill($data)->save();

        if (! $staff->canAccessAdmin()) {
            // signed out everywhere straight away
            DB::table('sessions')->where('user_id', $staff->id)->delete();
            $staff->forceFill(['remember_token' => Str::random(60)])->save();
        }

        if ($staff->role === 'customer') {
            return redirect()->route('admin.staff.index')->with('success', "{$staff->full_name} no longer has access to the back office. Their customer account is unchanged.");
        }

        return redirect()->route('admin.staff.edit', $staff)->with('success', $wasActive && ! $staff->is_active
            ? "{$staff->full_name}’s account is switched off and they have been signed out."
            : 'Staff account saved.');
    }

    /** Set a new password, or email a reset link. */
    public function password(Request $request, User $staff): RedirectResponse
    {
        abort_unless($staff->isStaff(), 404);

        if ($request->input('mode') === 'link') {
            if (! $staff->canAccessAdmin()) {
                return back()->with('error', 'Switch the account on first – switched-off staff can’t reset their password.');
            }
            $sent = $this->sendLink($staff);

            return back()->with($sent ? 'success' : 'error', $sent ? "Password link emailed to {$staff->email}." : 'The email couldn’t be sent. Check the mail settings and try again.');
        }

        $data = $request->validateWithBag('password', [
            'password' => ['required', 'string', 'max:255', 'confirmed', StaffPassword::rule()],
        ], [], ['password' => 'new password']);

        $staff->forceFill(['password' => Hash::make($data['password']), 'remember_token' => Str::random(60)])->save();
        if (! $staff->is($request->user())) {
            DB::table('sessions')->where('user_id', $staff->id)->delete();
        } else {
            // Your own password: keep this session, sign out every other device (same as Profile › Password)
            $request->session()->regenerate();
            DB::table('sessions')->where('user_id', $staff->id)->where('id', '!=', $request->session()->getId())->delete();
        }

        return back()->with('success', $staff->is($request->user()) ? 'Your password has been changed.' : "New password set – {$staff->full_name} has been signed out and must use it next time.");
    }

    protected function sendLink(User $user): bool
    {
        try {
            $status = Password::broker()->sendResetLink(['email' => $user->email], fn (User $u, string $token) => $u->notify(new AdminResetPassword($token)));

            return in_array($status, [Password::RESET_LINK_SENT, Password::RESET_THROTTLED], true);
        } catch (Throwable $e) {
            Log::error('Staff password link failed for user '.$user->id.': '.$e->getMessage());

            return false;
        }
    }
}
