<?php

namespace Pine\Commerce\Tests\Fixtures;

use Illuminate\Http\Request;
use Pine\Commerce\Contracts\DashboardWidget;

/** Class form of a dashboard widget (ExtensionApiTest). */
class TradeWidget implements DashboardWidget
{
    public function title(): ?string
    {
        return 'Trade accounts';
    }

    public function view(): string
    {
        return 'fixture::widget';
    }

    public function data(Request $request): array
    {
        return ['message' => 'Trade widget for '.$request->user()->email];
    }

    public function visible(Request $request): bool
    {
        return (bool) $request->user()?->isAdmin();
    }
}
