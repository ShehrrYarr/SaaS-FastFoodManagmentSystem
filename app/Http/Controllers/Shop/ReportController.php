<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Models\BusinessDay;
use App\Models\Order;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Spreadsheet download of the orders behind a report (Excel opens it directly). */
class ReportController extends Controller
{
    public function export(Request $request, TenantContext $tenancy): StreamedResponse
    {
        $shop = $tenancy->shop();

        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $from = Carbon::parse($data['from']);
        $to = Carbon::parse($data['to']);
        abort_if($from->diffInDays($to) >= 366, 422, 'Choose a range of at most a year.');

        $days = BusinessDay::query()->whereBetween('business_date', [$from->toDateString(), $to->toDateString()])->pluck('business_date', 'id');
        $tz = $shop->timezone;

        $filename = strtolower($shop->order_prefix).'-orders-'.$from->toDateString().'-to-'.$to->toDateString().'.csv';

        return response()->streamDownload(function () use ($days, $tz) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows names correctly

            fputcsv($out, [
                'Order', 'Business day', 'Paid / cancelled at', 'Type', 'Status', 'Table', 'Waiter', 'Customer', 'Phone',
                'Items', 'Discounts', 'Service charge', 'Delivery charge', 'Total', 'Paid by', 'Closed by',
            ]);

            Order::query()
                ->whereIn('closed_business_day_id', $days->keys())
                ->whereIn('status', ['completed', 'cancelled'])
                ->with(['payments.account', 'table', 'waiter', 'closedBy'])
                ->orderBy('closed_at')
                ->chunk(500, function ($orders) use ($out, $days, $tz) {
                    foreach ($orders as $order) {
                        fputcsv($out, array_map([$this, 'cell'], [
                            $order->order_number,
                            $days[$order->closed_business_day_id]?->format('Y-m-d'),
                            $order->closed_at?->timezone($tz)->format('Y-m-d H:i'),
                            Order::TYPES[$order->type] ?? $order->type,
                            $order->status === 'completed' ? 'Paid' : 'Cancelled',
                            $order->table?->name,
                            $order->waiter?->name,
                            $order->customer_name,
                            $order->customer_phone,
                            $order->subtotal,
                            bcadd($order->item_discount_total, $order->discount_amount, 2),
                            $order->service_charge,
                            $order->delivery_charge,
                            $order->status === 'completed' ? $order->grand_total : '0.00',
                            $order->payments->map(fn ($p) => $p->account?->name.' '.$p->amount)->implode('; '),
                            $order->closedBy?->name,
                        ]));
                    }
                });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Stops spreadsheet formulas sneaking in through names or notes (=, +, -, @). */
    private function cell(mixed $value): string
    {
        $value = (string) $value;

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) && ! is_numeric($value) ? "'".$value : $value;
    }
}
