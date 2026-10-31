<?php

namespace Pine\Commerce\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Pine\Commerce\Models\Setting;
use Pine\Commerce\Tests\TestCase;
use Symfony\Component\Mailer\Exception\TransportException;

/** MAIL_MAILER=smtp2go: email through SMTP2GO's web API with SMTP2GO_API_KEY (commerce.mail.smtp2go). */
class Smtp2GoMailerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // the Settings › Emails sender would override mail.from; none in this test (earlier tests may leave one memoised)
        Cache::forget('settings.all');
        Setting::flushMemo();
        config(['mail.default' => 'smtp2go', 'commerce.mail.smtp2go.key' => 'api-TEST', 'mail.from' => ['address' => 'sales@shop.example.test', 'name' => 'The Shop']]);
    }

    public function test_a_message_goes_to_the_send_endpoint_with_recipients_headers_and_attachments(): void
    {
        Http::fake(['api.smtp2go.com/*' => Http::response(['data' => ['succeeded' => 1, 'failed' => 0, 'failures' => [], 'email_id' => '1abc-2def']])]);

        Mail::send([], [], function (Message $m) {
            $m->to('ada@example.test', 'Ada')->cc('ops@shop.example.test')->bcc('archive@shop.example.test')
                ->replyTo('help@shop.example.test')->subject('Your order #1042')
                ->html('<p>Thanks for your order</p>')->text('Thanks for your order')
                ->attachData('%PDF-1.4 invoice', 'invoice-1042.pdf', ['mime' => 'application/pdf']);
        });

        Http::assertSent(function (Request $request) {
            $body = $request->data();

            return $request->url() === 'https://api.smtp2go.com/v3/email/send'
                && $request->hasHeader('X-Smtp2go-Api-Key', 'api-TEST')
                && $body['sender'] === '"The Shop" <sales@shop.example.test>'
                && $body['to'] === ['"Ada" <ada@example.test>']
                && $body['cc'] === ['ops@shop.example.test']
                && $body['bcc'] === ['archive@shop.example.test']
                && $body['subject'] === 'Your order #1042'
                && $body['html_body'] === '<p>Thanks for your order</p>'
                && $body['text_body'] === 'Thanks for your order'
                && in_array(['header' => 'Reply-To', 'value' => 'help@shop.example.test'], $body['custom_headers'], true)
                && $body['attachments'] === [['filename' => 'invoice-1042.pdf', 'fileblob' => base64_encode('%PDF-1.4 invoice'), 'mimetype' => 'application/pdf']];
        });
    }

    public function test_a_refused_message_throws_like_a_failed_smtp_send(): void
    {
        Http::fake(['api.smtp2go.com/*' => Http::response(['data' => ['error' => 'Sender address is not verified', 'error_code' => 'E_ApiResponseCodes.NON_VALIDATED_SENDER']], 400)]);

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('Sender address is not verified');

        Mail::raw('Hello', fn (Message $m) => $m->to('ada@example.test')->subject('Hi'));
    }

    public function test_without_a_key_nothing_is_sent(): void
    {
        config(['commerce.mail.smtp2go.key' => null]);
        Http::fake();

        try {
            Mail::raw('Hello', fn (Message $m) => $m->to('ada@example.test')->subject('Hi'));
            $this->fail('expected a TransportException');
        } catch (TransportException $e) {
            $this->assertStringContainsString('SMTP2GO_API_KEY', $e->getMessage());
        }
        Http::assertNothingSent();
    }
}
