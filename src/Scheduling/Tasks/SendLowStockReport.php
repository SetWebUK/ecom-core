<?php

namespace Pine\Commerce\Scheduling\Tasks;

use Illuminate\Support\Facades\Mail;
use Pine\Commerce\Mail\LowStockReport;
use Pine\Commerce\Scheduling\Task;
use Pine\Commerce\Services\Admin\StoreSettings;

/**
 * Daily low-stock email to the shop (setting "scheduler.low_stock_email", off by default): published products with
 * managed stock at or below their low-stock threshold, and sold-out products. Nothing is sent when nothing is low.
 */
class SendLowStockReport extends Task
{
    public function skipReason(): ?string
    {
        if (! filter_var(setting('scheduler.low_stock_email', StoreSettings::defaultFor('scheduler.low_stock_email', false)), FILTER_VALIDATE_BOOL)) {
            return 'Switched off in Settings › Scheduled tasks.';
        }

        return static::recipients() ? null : 'No shop email address (Settings › Emails › Send order notifications to).';
    }

    /** @return list<string> */
    public static function recipients(): array
    {
        $raw = (string) (setting('emails.admin_address') ?: setting('emails.admin_recipient') ?: setting('store.email') ?: config('mail.from.address'));

        return array_values(array_filter(StoreSettings::emailList($raw), fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
    }

    public function handle(): string
    {
        $mail = new LowStockReport;
        if ($mail->isEmpty()) {
            return 'Nothing is low on stock – no email sent';
        }
        Mail::to(static::recipients())->send($mail);

        return 'Sent to '.implode(', ', static::recipients()).' ('.$mail->low->count().' low, '.$mail->out->count().' sold out)';
    }
}
