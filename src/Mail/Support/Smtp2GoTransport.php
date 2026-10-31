<?php

namespace Pine\Commerce\Mail\Support;

use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\MessageConverter;
use Symfony\Component\Mime\Part\DataPart;
use Throwable;

/**
 * Sends through SMTP2GO's web API (POST {endpoint}/email/send) instead of SMTP: MAIL_MAILER=smtp2go with
 * SMTP2GO_API_KEY (an API key allowed to send emails; commerce.mail.smtp2go). Attachments (e.g. invoice PDFs) and
 * inline images go with the message; Bcc and other envelope-only recipients are sent as bcc; Reply-To and the
 * message's own X- headers as custom headers. A refused message throws, so Laravel reports it like an SMTP failure.
 */
class Smtp2GoTransport extends AbstractTransport
{
    public function __construct(protected string $key, protected string $endpoint = 'https://api.smtp2go.com/v3')
    {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        if (trim($this->key) === '') {
            throw new TransportException('SMTP2GO: no API key (set SMTP2GO_API_KEY).');
        }
        $email = MessageConverter::toEmail($message->getOriginalMessage());
        $payload = $this->payload($email, $message->getEnvelope());

        try {
            $response = Http::timeout(30)->connectTimeout(10)->acceptJson()
                ->withHeaders(['X-Smtp2go-Api-Key' => $this->key])
                ->post(rtrim($this->endpoint, '/').'/email/send', $payload);
        } catch (Throwable $e) {
            throw new TransportException('SMTP2GO could not be reached: '.$e->getMessage(), 0, $e);
        }
        $data = (array) $response->json('data', []);
        if (! $response->successful() || (int) ($data['succeeded'] ?? 0) < 1) {
            $why = $data['error'] ?? ($data['failures'][0] ?? null) ?? $response->body();
            throw new TransportException(sprintf('SMTP2GO refused the message (HTTP %d): %s', $response->status(), is_string($why) ? $why : json_encode($why)));
        }
        if (! empty($data['email_id'])) {
            $message->setMessageId((string) $data['email_id']);
        }
    }

    /** The /email/send body for a message. */
    public function payload(Email $email, Envelope $envelope): array
    {
        $list = fn (array $addresses) => array_values(array_map(fn (Address $a) => $a->toString(), $addresses));
        $to = $list($email->getTo());
        $cc = $list($email->getCc());
        // envelope recipients not in To/Cc (Bcc, or set only on the envelope) must still receive it
        $shown = array_map('strtolower', array_map(fn (Address $a) => $a->getAddress(), array_merge($email->getTo(), $email->getCc())));
        $bcc = $list(array_filter($envelope->getRecipients(), fn (Address $a) => ! in_array(strtolower($a->getAddress()), $shown, true)));

        $headers = [];
        if ($replyTo = $email->getReplyTo()) {
            $headers[] = ['header' => 'Reply-To', 'value' => implode(', ', $list($replyTo))];
        }
        foreach ($email->getHeaders()->all() as $header) {
            if (str_starts_with(strtolower($header->getName()), 'x-') || in_array(strtolower($header->getName()), ['list-unsubscribe', 'list-unsubscribe-post'], true)) {
                $headers[] = ['header' => $header->getName(), 'value' => $header->getBodyAsString()];
            }
        }

        $attachments = $inlines = [];
        foreach ($email->getAttachments() as $part) {
            /** @var DataPart $part */
            $file = ['filename' => $part->getFilename() ?: 'attachment', 'fileblob' => base64_encode($part->getBody()), 'mimetype' => $part->getMediaType().'/'.$part->getMediaSubtype()];
            if ($part->getDisposition() === 'inline') {
                $inlines[] = ['filename' => $part->hasContentId() ? $part->getContentId() : $file['filename']] + $file;
            } else {
                $attachments[] = $file;
            }
        }

        return array_filter([
            'sender' => ($email->getFrom()[0] ?? $envelope->getSender())->toString(),
            'to' => $to ?: $bcc,
            'cc' => $cc,
            'bcc' => $to ? $bcc : [],
            'subject' => (string) $email->getSubject(),
            'html_body' => $email->getHtmlBody() !== null ? (string) (is_resource($email->getHtmlBody()) ? stream_get_contents($email->getHtmlBody()) : $email->getHtmlBody()) : null,
            'text_body' => $email->getTextBody() !== null ? (string) (is_resource($email->getTextBody()) ? stream_get_contents($email->getTextBody()) : $email->getTextBody()) : null,
            'custom_headers' => $headers,
            'attachments' => $attachments,
            'inlines' => $inlines,
        ], fn ($v) => $v !== null && $v !== []);
    }

    public function __toString(): string
    {
        return 'smtp2go+api://'.parse_url($this->endpoint, PHP_URL_HOST);
    }
}
