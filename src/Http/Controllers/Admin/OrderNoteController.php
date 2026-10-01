<?php

namespace Pine\Commerce\Http\Controllers\Admin;

use Pine\Commerce\Http\Controllers\Controller;
use Pine\Commerce\Http\Requests\Admin\Sales\OrderNoteRequest;
use Pine\Commerce\Models\Order;
use Pine\Commerce\Models\OrderNote;
use Pine\Commerce\Services\Admin\OrderManager;
use Illuminate\Http\RedirectResponse;

/** Order timeline notes: private staff notes and notes to the customer (emailed). */
class OrderNoteController extends Controller
{
    public function store(OrderNoteRequest $request, Order $order): RedirectResponse
    {
        $result = OrderManager::addNote($order, $request->noteText(), $request->toCustomer());

        if (! $request->toCustomer()) {
            return back()->with('success', 'Note added.');
        }

        return $result['emailed']
            ? back()->with('success', 'Note added and emailed to '.$order->email.'.')
            : back()->with('warning', 'Note added, but the email to the customer couldn’t be sent.');
    }

    /** Staff notes can be removed; system notes (the order's history) can't. */
    public function destroy(Order $order, OrderNote $note): RedirectResponse
    {
        abort_unless($note->order_id === $order->id, 404);
        if ($note->is_system) {
            return back()->with('error', 'Automatic history entries can’t be deleted.');
        }
        $note->delete();

        return back()->with('success', 'Note deleted.');
    }
}
