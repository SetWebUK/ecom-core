<?php

namespace Pine\Commerce\Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Hash;
use Pine\Commerce\Models\Setting;
use Pine\Commerce\Models\User;
use Pine\Commerce\Tests\Concerns\InstallsNeutralStore;
use Pine\Commerce\Tests\TestCase;
use Pine\Commerce\Theme\Storefront;
use Pine\Commerce\Theme\ThemeContract;
use Pine\Commerce\Theme\ThemeManager;

/**
 * 1.4: the separate "Create an account" page (GET /my-account/register/, route register.show), the sign-in-only
 * auth.login, where failed sign-ins and registrations are sent back to, and the default theme fallback for auth.register.
 */
class RegistrationPageTest extends TestCase
{
    use InstallsNeutralStore;

    protected ?string $themeRoot = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpNeutralStore(['commerce.features.registration' => true]);
    }

    protected function tearDown(): void
    {
        if ($this->themeRoot) {
            $this->app->make(ThemeManager::class)->reset();
            (new Filesystem)->deleteDirectory($this->themeRoot);
        }
        $this->tearDownNeutralStore();
        parent::tearDown();
    }

    public function test_register_page_is_its_own_route_under_my_account(): void
    {
        $this->assertStringEndsWith('/my-account/register/', route('register.show'));
        $this->assertSame(route('register.show'), route('register'), 'the POST keeps its name and URL');

        $html = $this->get(route('register.show'))->assertOk()->assertHeader('Cache-Control', 'no-store, private')->getContent();
        $this->assertStringContainsString('action="'.route('register').'"', $html);
        foreach (['name="email"', 'name="password"', 'name="first_name"', 'name="last_name"', 'data-password-toggle', 'data-password-strength', 'js/auth.js'] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }
        $this->assertStringContainsString('href="'.route('account').'"', $html, 'link back to sign in');
        $this->assertStringContainsString('noindex', $html);
    }

    public function test_sign_in_page_is_sign_in_only_and_links_to_the_register_page(): void
    {
        $html = $this->get(route('account'))->assertOk()->getContent();
        $this->assertStringContainsString('action="'.route('login.attempt').'"', $html);
        $this->assertStringNotContainsString('action="'.route('register').'"', $html, 'no register form on the sign-in page');
        $this->assertStringContainsString('href="'.route('register.show').'"', $html);
        $this->assertStringContainsString('href="'.route('password.request').'"', $html);

        $html = $this->get(route('account', ['redirect' => '/checkout/']))->getContent();
        $this->assertStringContainsString(e(route('register.show', ['redirect' => '/checkout/'])), $html, 'the way back to the checkout is kept');
    }

    public function test_register_page_is_for_guests_only(): void
    {
        $user = $this->customer();
        $this->actingAs($user)->get(route('register.show'))->assertRedirect(route('account'));
        $this->actingAs($user)->get(route('register.show', ['redirect' => '/checkout/']))->assertRedirect(url('/checkout/'));
        $this->actingAs($user)->get(route('register.show', ['redirect' => '//evil.example']))->assertRedirect(route('account'));
    }

    public function test_feature_off_or_setting_off_means_404_and_no_link(): void
    {
        config(['commerce.features.registration' => false]);
        Storefront::flush();
        $this->get(route('register.show'))->assertNotFound();
        $this->post(route('register'), ['email' => 'new@example.test', 'password' => 'long-enough-1'])->assertNotFound();
        $this->assertStringNotContainsString(route('register.show'), $this->get(route('account'))->assertOk()->getContent());

        config(['commerce.features.registration' => true]);
        Setting::set('account.registration', false);
        $this->get(route('register.show'))->assertNotFound();
        $this->assertStringNotContainsString(route('register.show'), $this->get(route('account'))->assertOk()->getContent());
    }

    public function test_registration_errors_return_to_the_register_page_with_old_input(): void
    {
        $this->from(route('account'))->post(route('register'), ['email' => 'not-an-email', 'password' => 'short', 'first_name' => 'Ada'])
            ->assertRedirect(route('register.show'))
            ->assertSessionHasErrors(['email', 'password'])
            ->assertSessionHasInput('email', 'not-an-email')
            ->assertSessionHasInput('first_name', 'Ada')
            ->assertSessionMissing('_old_input.password');

        $html = $this->get(route('register.show'))->assertOk()->getContent();
        $this->assertStringContainsString('value="not-an-email"', $html);
        $this->assertStringContainsString('aria-invalid="true"', $html);
        $this->assertStringContainsString('Please enter a password of at least 8 characters.', $html);

        // an email that is already registered, keeping the way back to the checkout
        $this->customer();
        $this->post(route('register'), ['email' => 'ADA@example.test', 'password' => 'long-enough-1', 'redirect' => '/checkout/'])
            ->assertRedirect(route('register.show', ['redirect' => '/checkout/']))
            ->assertSessionHasErrors('email');
        // an unsafe redirect is dropped
        $this->post(route('register'), ['email' => '', 'password' => '', 'redirect' => '//evil.example'])
            ->assertRedirect(route('register.show'));
    }

    public function test_registration_rate_limit_returns_to_the_register_page(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('register'), ['email' => 'x', 'password' => 'x']);
        }
        $this->post(route('register'), ['email' => 'x', 'password' => 'x'])
            ->assertRedirect(route('register.show'))
            ->assertSessionHasErrors(['email' => 'Too many registration attempts. Please try again later.']);
    }

    public function test_successful_registration_signs_the_customer_in(): void
    {
        $this->post(route('register'), ['email' => 'Grace@Example.test', 'password' => 'long-enough-1', 'first_name' => 'Grace', 'last_name' => 'Hopper'])
            ->assertRedirect(route('account'));
        $user = User::where('email', 'grace@example.test')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('Grace', $user->first_name);
    }

    public function test_sign_in_errors_return_to_the_sign_in_page(): void
    {
        $this->from(route('register.show'))->post(route('login.attempt'), ['username' => '', 'password' => ''])
            ->assertRedirect(route('account'))->assertSessionHasErrors('username');

        $this->customer();
        $this->from(route('register.show'))->post(route('login.attempt'), ['username' => 'ada@example.test', 'password' => 'wrong-password'])
            ->assertRedirect(route('account'))->assertSessionHasErrors('username')->assertSessionHasInput('username', 'ada@example.test');
        $this->assertStringContainsString('is incorrect', $this->get(route('account'))->getContent());

        $this->post(route('login.attempt'), ['username' => '', 'password' => '', 'redirect' => '/checkout/'])
            ->assertRedirect(url('checkout'));
    }

    public function test_a_theme_without_auth_register_falls_back_to_the_default_theme_view(): void
    {
        $this->themeRoot = sys_get_temp_dir().'/commerce-auth-themes-'.bin2hex(random_bytes(4));
        $dir = $this->themeRoot.'/legacy-auth';
        $fs = new Filesystem;
        $fs->ensureDirectoryExists($dir.'/views/auth');
        $fs->put($dir.'/theme.json', json_encode(['name' => 'Legacy auth', 'slug' => 'legacy-auth', 'version' => '1.0.0', 'parent' => 'default', 'supports' => []]));
        $fs->put($dir.'/views/auth/login.blade.php', '<p>legacy sign-in and register form</p>');

        $themes = $this->app->make(ThemeManager::class);
        $themes->addRoot($this->themeRoot);
        $themes->activate('legacy-auth');
        Storefront::flush();

        $this->assertStringContainsString('legacy sign-in and register form', $this->get(route('account'))->assertOk()->getContent());
        $this->assertStringEndsWith('resources/themes/default/views/auth/register.blade.php', $themes->find('auth.register'));
        $html = $this->get(route('register.show'))->assertOk()->getContent();
        $this->assertStringContainsString('action="'.route('register').'"', $html);
        $this->assertSame([], ThemeContract::check($themes, 'legacy-auth'));
    }

    public function test_default_theme_auth_pages_render(): void
    {
        $html = $this->get(route('password.request'))->assertOk()->getContent();
        $this->assertStringContainsString('name="user_login"', $html);
        $html = $this->get(route('password.reset', ['token' => 'abc', 'email' => 'ada@example.test']))->assertOk()->getContent();
        $this->assertStringContainsString('data-password-match="password_1"', $html);
        $this->assertStringContainsString('value="ada@example.test"', $html);
        $this->assertStringContainsString('Check your email', $this->withSession(['reset_sent' => true])->get(route('password.request'))->getContent());
    }

    protected function customer(): User
    {
        $user = new User;
        $user->forceFill(['name' => 'ada', 'first_name' => 'Ada', 'email' => 'ada@example.test', 'role' => 'customer', 'is_active' => true, 'password' => Hash::make('Correct-Horse-9')])->save();

        return $user;
    }
}
