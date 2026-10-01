<?php

namespace Pine\Commerce\Exports;

use Pine\Commerce\Models\NewsletterSubscriber;
use Pine\Commerce\Services\Admin\LocalTime;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Newsletter subscribers CSV (email, source, subscribed date, status) for import into a mailing tool. */
class NewsletterCsvExport extends CsvExport
{
    public static function download(?Builder $query = null, ?string $filename = null): StreamedResponse
    {
        $query ??= NewsletterSubscriber::query();

        return static::stream(
            $filename ?? 'newsletter-subscribers-'.now()->format('Y-m-d').'.csv',
            ['Email', 'Source', 'Subscribed', 'Status', 'Unsubscribed'],
            (function () use ($query) {
                foreach ($query->reorder()->lazyById(500) as $subscriber) {
                    yield [
                        $subscriber->email,
                        $subscriber->source,
                        $subscriber->created_at ? LocalTime::format($subscriber->created_at, 'Y-m-d H:i') : null,
                        $subscriber->unsubscribed_at ? 'Unsubscribed' : 'Subscribed',
                        $subscriber->unsubscribed_at ? LocalTime::format($subscriber->unsubscribed_at, 'Y-m-d H:i') : null,
                    ];
                }
            })(),
        );
    }
}
