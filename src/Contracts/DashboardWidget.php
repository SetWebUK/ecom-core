<?php

namespace Pine\Commerce\Contracts;

use Illuminate\Http\Request;

/**
 * Optional class form of a back-office dashboard widget (Commerce::dashboardWidget('key', Widget::class)). The array
 * form (['title' => …, 'view' => …, 'data' => fn () => [...]]) needs no class; see docs/EXTENDING.md.
 */
interface DashboardWidget
{
    /** Card title (null = the view renders its own card). */
    public function title(): ?string;

    /** Blade view rendered inside the card, e.g. 'admin.widgets.trade-orders' (client resources/views). */
    public function view(): string;

    /** Data for the view; runs only when the widget is shown. */
    public function data(Request $request): array;

    /** Show it to this request's user (e.g. administrators only)? */
    public function visible(Request $request): bool;
}
