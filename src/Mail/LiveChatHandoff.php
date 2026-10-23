<?php

namespace Pine\Commerce\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\Route;
use Pine\Commerce\Models\ChatConversation;

/** Live chat: a customer is waiting for a person (sent once per conversation, to the live-chat notification address). */
class LiveChatHandoff extends Mailable
{
    use Queueable;

    public function __construct(public ChatConversation $conversation, public string $reason, public string $transcript) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Live chat: '.$this->conversation->displayName().' is waiting for a reply');
    }

    public function content(): Content
    {
        return new Content(view: 'commerce::emails.live-chat-handoff', with: [
            'heading' => 'A customer is waiting in live chat',
            'storeName' => (string) setting('store.name', config('app.name')),
            'reason' => $this->reason,
            'transcript' => $this->transcript,
            'pageUrl' => $this->conversation->page_url,
            'chatUrl' => Route::has('admin.live-chat.show') ? route('admin.live-chat.show', $this->conversation) : null,
        ]);
    }
}
