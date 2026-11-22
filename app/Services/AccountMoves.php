<?php

namespace App\Services;

use App\Exceptions\PosException;
use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\ShopSequence;
use App\Models\Transfer;
use App\Support\Money;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Moving money that isn't a sale or an expense:
 * - transfer: from one account to another (drawer banked at Meezan), BLG-TRF-000001;
 * - money in / out: cash the owner adds or takes, bank charges, corrections.
 * An account can never give more than it holds.
 */
class AccountMoves
{
    private const ATTEMPTS = 3;

    public function __construct(
        private TenantContext $tenancy,
        private AccountLedger $ledger,
        private BusinessDays $days,
    ) {}

    public function transfer(int $fromId, int $toId, string|int|float $amount, ?string $note = null): Transfer
    {
        $amount = Money::of($amount);

        if (! Money::isPositive($amount)) {
            throw new PosException('Enter the amount to transfer.');
        }
        if ($fromId === $toId) {
            throw new PosException('Choose two different accounts.');
        }

        return DB::transaction(function () use ($fromId, $toId, $amount, $note) {
            $day = $this->days->current(lock: true);

            $accounts = $this->ledger->lock([$fromId, $toId]);
            $from = $accounts->get($fromId);
            $to = $accounts->get($toId);

            if (! $from?->is_active || ! $to?->is_active) {
                throw new PosException('Both accounts must be active.');
            }

            $shop = $this->tenancy->shop();
            $sequence = ShopSequence::next($shop->id, 'transfer');

            $transfer = Transfer::create([
                'voucher_number' => ShopSequence::format($shop->order_prefix, $sequence, 'TRF'),
                'sequence' => $sequence,
                'from_account_id' => $from->id,
                'to_account_id' => $to->id,
                'amount' => $amount,
                'note' => $this->clean($note),
                'business_day_id' => $day?->id,
                'created_by' => Auth::guard('shop')->id(),
            ]);

            $suffix = $transfer->note ? ' · '.$transfer->note : '';

            $this->ledger->post($from, 'transfer_out', Money::sub(0, $amount), $transfer,
                mb_substr($transfer->voucher_number.' to '.$to->name.$suffix, 0, 255), businessDayId: $day?->id, mustCover: true);
            $this->ledger->post($to, 'transfer_in', $amount, $transfer,
                mb_substr($transfer->voucher_number.' from '.$from->name.$suffix, 0, 255), businessDayId: $day?->id);

            return $transfer->load('fromAccount', 'toAccount');
        }, self::ATTEMPTS);
    }

    /**
     * Money in (+) or out (−) of one account with a reason, e.g. the owner tops up the
     * float, takes cash home, or the bank deducts a charge.
     */
    public function adjust(int $accountId, string $direction, string|int|float $amount, string $reason): AccountTransaction
    {
        $amount = Money::of($amount);
        $reason = $this->clean($reason) ?? throw new PosException('Say what the money was for.');

        if (! in_array($direction, ['in', 'out'], true)) {
            throw new PosException('Choose money in or money out.');
        }
        if (! Money::isPositive($amount)) {
            throw new PosException('Enter the amount.');
        }

        return DB::transaction(function () use ($accountId, $direction, $amount, $reason) {
            $day = $this->days->current(lock: true);

            $account = Account::query()->active()->find($accountId)
                ?? throw new PosException('Choose an active account.');

            return $direction === 'in'
                ? $this->ledger->post($account, 'cash_in', $amount, note: $reason, businessDayId: $day?->id)
                : $this->ledger->post($account, 'cash_out', Money::sub(0, $amount), note: $reason, businessDayId: $day?->id, mustCover: true);
        }, self::ATTEMPTS);
    }

    private function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, 255);
    }
}
