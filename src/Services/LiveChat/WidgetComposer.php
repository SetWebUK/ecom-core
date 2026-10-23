<?php

namespace Pine\Commerce\Services\LiveChat;

use Illuminate\View\View;

/**
 * Adds the live-chat widget to every storefront page while feature "live_chat" is on: pushed into the layout's
 * @stack('scripts') (both the default theme and child themes render it), once per request. Composed on layouts.app,
 * so admin pages, emails and PDFs never get it.
 */
class WidgetComposer
{
    public function compose(View $view): void
    {
        $request = request();
        if (! LiveChat::enabled() || $request->attributes->get('live_chat.widget') || $request->is('admin', 'admin/*')) {
            return;
        }
        $request->attributes->set('live_chat.widget', true);

        $view->getFactory()->startPush('scripts', view('commerce::live-chat.widget', ['config' => [
            'assistantName' => ChatSettings::assistantName(),
            'storeName' => (string) setting('store.name', config('app.name')),
            'greeting' => ChatSettings::aiReady() ? ChatSettings::greeting() : 'Hi! Send us a message and a member of our team will reply here.',
            'aiReady' => ChatSettings::aiReady(),
            'endpoints' => [
                'send' => route('live-chat.send'),
                'poll' => route('live-chat.poll'),
                'handoff' => route('live-chat.handoff'),
                'details' => route('live-chat.details'),
            ],
        ]])->render());
    }
}
