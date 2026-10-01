<?php

namespace Pine\Commerce\Scheduling\Tasks;

use Pine\Commerce\Models\StockNotification;
use Pine\Commerce\Scheduling\Task;
use Pine\Commerce\Services\Admin\CatalogueTools;
use Pine\Commerce\Support\Features;

/**
 * Back-in-stock sweep. Alerts are already sent straight away when staff restock in the back office (product form,
 * bulk actions, inventory CSV); this catches every other way a product comes back (a cancelled order releasing stock,
 * an import, a client integration) – waiting alerts whose product/variation can be bought again.
 */
class SendBackInStockAlerts extends Task
{
    public function skipReason(): ?string
    {
        return Features::enabled('stock_alerts', false) ? null : 'Feature stock_alerts is off.';
    }

    public function handle(): string
    {
        $ids = StockNotification::query()->whereNull('notified_at')->distinct()->pluck('product_id')->all();
        $result = CatalogueTools::notifyBackInStock($ids);

        return $result['sent'].' sent, '.$result['failed'].' failed, '.$result['waiting'].' still waiting';
    }
}
