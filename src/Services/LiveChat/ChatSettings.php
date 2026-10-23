<?php

namespace Pine\Commerce\Services\LiveChat;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Pine\Commerce\Models\Setting;

/**
 * Admin › Live chat › Settings (settings group "live_chat"). The provider's API key is encrypted with the app key
 * (Crypt::encryptString) and never sent back to the browser – the form only learns whether one is saved; an empty box
 * keeps it (same pattern as the payment gateway secrets and the WooCommerce API connection).
 */
final class ChatSettings
{
    public const PROVIDERS = ['none' => 'Off – staff answer every chat', 'anthropic' => 'Claude (Anthropic)', 'openai' => 'OpenAI'];

    /** Claude models offered in the form (first = default). Any other model id can be typed in "Other". */
    public const ANTHROPIC_MODELS = [
        'claude-opus-5-5' => 'Claude Opus 5.5 – most capable',
        'claude-sonnet-5-5' => 'Claude Sonnet 5.5 – faster, lower cost',
        'claude-haiku-5-5' => 'Claude Haiku 5.5 – fastest, lowest cost',
    ];

    public static function provider(): string
    {
        $provider = (string) Setting::get('live_chat.provider', 'none');

        return array_key_exists($provider, self::PROVIDERS) ? $provider : 'none';
    }

    public static function apiKey(): string
    {
        $value = Setting::get('live_chat.api_key');
        if (! is_string($value) || $value === '') {
            return '';
        }
        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            return ''; // APP_KEY changed: the key has to be entered again
        }
    }

    public static function model(): string
    {
        $model = trim((string) Setting::get('live_chat.model', ''));
        if ($model === '' && self::provider() === 'anthropic') {
            return (string) array_key_first(self::ANTHROPIC_MODELS);
        }

        return $model;
    }

    /** Is an AI assistant configured (provider + key + model)? Without one every chat goes straight to staff. */
    public static function aiReady(): bool
    {
        return self::provider() !== 'none' && self::apiKey() !== '' && self::model() !== '';
    }

    public static function assistantName(): string
    {
        return trim((string) Setting::get('live_chat.assistant_name', '')) ?: 'Shop assistant';
    }

    public static function greeting(): string
    {
        return trim((string) Setting::get('live_chat.greeting', ''))
            ?: 'Hi! I can help you find the right product, check prices and stock, or put you through to our team. What are you looking for?';
    }

    /** Store facts the assistant may quote: delivery, returns, warranty, opening hours … (admin text). */
    public static function knowledge(): string
    {
        return trim((string) Setting::get('live_chat.knowledge', ''));
    }

    /** Minutes a customer waits for staff before the chat asks for an email address instead. */
    public static function waitMinutes(): int
    {
        return max(1, min(120, (int) Setting::get('live_chat.wait_minutes', 5)));
    }

    public static function notifyEmail(): string
    {
        return trim((string) Setting::get('live_chat.notify_email', '')) ?: (string) setting('store.email', config('mail.from.address'));
    }

    /** Most assistant replies in one conversation (keeps a runaway or abusive chat from running up the API bill). */
    public static function maxReplies(): int
    {
        return max(1, (int) Setting::get('live_chat.max_replies', 40));
    }

    /** For the form: plain values + whether a key is saved, never the key. */
    public static function form(): array
    {
        return [
            'provider' => self::provider(),
            'has_key' => self::apiKey() !== '',
            'model' => (string) Setting::get('live_chat.model', ''),
            'assistant_name' => (string) Setting::get('live_chat.assistant_name', ''),
            'greeting' => (string) Setting::get('live_chat.greeting', ''),
            'knowledge' => self::knowledge(),
            'wait_minutes' => self::waitMinutes(),
            'notify_email' => (string) Setting::get('live_chat.notify_email', ''),
            'max_replies' => self::maxReplies(),
        ];
    }

    /**
     * Save the validated form. api_key: a non-empty value replaces the saved one, an empty one keeps it, api_key_clear
     * removes it.
     *
     * @param  array<string,mixed>  $input
     */
    public static function save(array $input): void
    {
        $set = fn (string $key, $value) => Setting::set('live_chat.'.$key, $value, 'live_chat');
        $set('provider', (string) $input['provider']);
        $set('model', trim((string) ($input['model'] ?? '')));
        $set('assistant_name', trim((string) ($input['assistant_name'] ?? '')));
        $set('greeting', trim((string) ($input['greeting'] ?? '')));
        $set('knowledge', trim((string) ($input['knowledge'] ?? '')));
        $set('wait_minutes', (int) ($input['wait_minutes'] ?? 5));
        $set('notify_email', trim((string) ($input['notify_email'] ?? '')));
        $set('max_replies', (int) ($input['max_replies'] ?? 40));

        if (! empty($input['api_key_clear'])) {
            $set('api_key', '');
        } elseif (filled($input['api_key'] ?? null)) {
            $set('api_key', Crypt::encryptString(trim((string) $input['api_key'])));
        }
    }
}
