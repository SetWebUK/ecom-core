<?php

namespace Pine\Commerce\Http\Controllers\Admin;

use Pine\Commerce\Http\Controllers\Controller;
use Pine\Commerce\Http\Requests\Admin\Sales\RefundRequest;
use Pine\Commerce\Models\Order;
use Pine\Commerce\Services\Admin\OrderManager;
use Illuminate\Http\RedirectResponse;
use RuntimeException;

/** Refund an order (modal on the order page): through the payment gateway when it supports it, or record a manual refund. */
class RefundController extends Controller
{
    public function store(RefundRequest $request, Order $order): RedirectResponse
    {
        try {
            $refund = OrderManager::refund(
                $order,
                $request->amount(),
                $request->refundLines(),
                $request->reason(),
                $request->boolean('restock'),
                $request->viaGateway(),
                $request->shipping(),
            );
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['amount' => $e->getMessage()], 'refund');
        }

        $message = money($refund->amount).($request->viaGateway() ? ' refunded to the customer' : ' refund recorded').'.';
        if ($order->refresh()->status === 'refunded') {
            $message .= ' The order is now fully refunded.';
        }

        return redirect()->route('admin.orders.show', $order)->with('success', $message);
    }
}
