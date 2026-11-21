<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Models\BusinessDay;
use App\Models\Kot;
use App\Models\Order;
use App\Services\BusinessDays;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * 80mm print layouts: kitchen tickets, customer receipts and the day-end
 * report. The POS prints them through a hidden iframe; they also open in a
 * normal tab for reprinting. Route-model binding is tenant-scoped, so another
 * shop's ticket or order is a 404.
 */
class PrintController extends Controller
{
    public function __construct(private TenantContext $tenancy) {}

    public function kot(Request $request, Kot $kot): View
    {
        $this->allow($request, ['pos.use', 'orders.view']);

        $kot->increment('printed_count');
        $kot->load([
            'order.table', 'order.waiter', 'createdBy',
            'items.modifiers',
            'voids.item.modifiers',
        ]);

        return view('print.kot', ['shop' => $this->tenancy->shop(), 'kot' => $kot, 'order' => $kot->order]);
    }

    public function receipt(Request $request, Order $order): View
    {
        $this->allow($request, ['pos.use', 'orders.view']);

        $order->load(['items.modifiers', 'payments.account', 'table', 'waiter', 'rider', 'closedBy', 'openedBy']);

        return view('print.receipt', ['shop' => $this->tenancy->shop(), 'order' => $order]);
    }

    public function day(Request $request, BusinessDay $businessDay, BusinessDays $days): View
    {
        $this->allow($request, ['day.open_close', 'reports.view']);

        $businessDay->load(['openedBy', 'closedBy']);

        return view('print.day', [
            'shop' => $this->tenancy->shop(),
            'day' => $businessDay,
            'summary' => $businessDay->isOpen() ? $days->summary($businessDay) : $businessDay->summary,
        ]);
    }

    /** @param list<string> $permissions  any one of them is enough */
    private function allow(Request $request, array $permissions): void
    {
        $user = $request->user('shop');

        abort_unless(collect($permissions)->contains(fn ($p) => $user?->hasPermission($p)), 403);
    }
}
