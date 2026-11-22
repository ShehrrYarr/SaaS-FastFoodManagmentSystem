<?php

namespace App\Services;

use App\Exceptions\PosException;
use App\Models\Account;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ShopSequence;
use App\Support\Money;
use App\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Records and voids expenses. Recording takes the money out of the chosen
 * account (BLG-EXP-000001); a void puts it back with a reversing entry and
 * keeps the expense on file, so every ledger line stays explained.
 */
class Expenses
{
    private const ATTEMPTS = 3;

    public function __construct(
        private TenantContext $tenancy,
        private AccountLedger $ledger,
        private BusinessDays $days,
    ) {}

    /**
     * @param  array{expense_category_id: int|string|null, account_id: int|string|null, amount: mixed, spent_on?: ?string, payee?: ?string, note?: ?string}  $data
     */
    public function record(array $data): Expense
    {
        $shop = $this->tenancy->shop();
        $amount = Money::of($data['amount'] ?? 0);

        if (! Money::isPositive($amount)) {
            throw new PosException('Enter the amount spent.');
        }

        $date = filled($data['spent_on'] ?? null) ? Carbon::parse($data['spent_on'])->toDateString() : $shop->today()->toDateString();

        if ($date > $shop->today()->toDateString()) {
            throw new PosException('The expense date cannot be in the future.');
        }

        $category = ExpenseCategory::query()->active()->find((int) ($data['expense_category_id'] ?? 0))
            ?? throw new PosException('Choose what the money was spent on.');

        return DB::transaction(function () use ($shop, $data, $amount, $date, $category) {
            $day = $this->days->current(lock: true);

            $account = Account::query()->active()->find((int) ($data['account_id'] ?? 0))
                ?? throw new PosException('Choose an active account to pay from.');

            $sequence = ShopSequence::next($shop->id, 'expense');

            $expense = Expense::create([
                'voucher_number' => ShopSequence::format($shop->order_prefix, $sequence, 'EXP'),
                'sequence' => $sequence,
                'expense_category_id' => $category->id,
                'account_id' => $account->id,
                'amount' => $amount,
                'spent_on' => $date,
                'payee' => $this->clean($data['payee'] ?? null, 120),
                'note' => $this->clean($data['note'] ?? null, 255),
                'business_day_id' => $day?->id,
                'created_by' => Auth::guard('shop')->id(),
            ]);

            $this->ledger->post(
                $account,
                'expense',
                Money::sub(0, $amount),
                $expense,
                $this->label($expense, $category->name),
                businessDayId: $day?->id,
                mustCover: true,
            );

            return $expense->load('category', 'account');
        }, self::ATTEMPTS);
    }

    /** Changes what an expense says; the amount and account change only by voiding it. */
    public function updateDetails(Expense $expense, array $data): Expense
    {
        $shop = $this->tenancy->shop();

        $category = ExpenseCategory::query()->find((int) ($data['expense_category_id'] ?? 0))
            ?? throw new PosException('Choose what the money was spent on.');

        $date = filled($data['spent_on'] ?? null) ? Carbon::parse($data['spent_on'])->toDateString() : $expense->spent_on->toDateString();

        if ($date > $shop->today()->toDateString()) {
            throw new PosException('The expense date cannot be in the future.');
        }

        $expense->update([
            'expense_category_id' => $category->id,
            'spent_on' => $date,
            'payee' => $this->clean($data['payee'] ?? null, 120),
            'note' => $this->clean($data['note'] ?? null, 255),
        ]);

        return $expense;
    }

    public function void(Expense $expense, string $reason): Expense
    {
        $reason = $this->clean($reason, 255) ?? throw new PosException('Give a reason for voiding this expense.');

        return DB::transaction(function () use ($expense, $reason) {
            $day = $this->days->current(lock: true);

            $expense = Expense::query()->whereKey($expense->id)->lockForUpdate()->firstOrFail();

            if ($expense->isVoided()) {
                throw new PosException($expense->voucher_number.' is already voided.');
            }

            $expense->update([
                'voided_at' => now(),
                'voided_by' => Auth::guard('shop')->id(),
                'void_reason' => $reason,
            ]);

            // The money goes back into the account it came from, on today's books.
            $this->ledger->post(
                Account::query()->findOrFail($expense->account_id),
                'expense',
                $expense->amount,
                $expense,
                'Void '.$expense->voucher_number.': '.$reason,
                businessDayId: $day?->id,
            );

            return $expense;
        }, self::ATTEMPTS);
    }

    private function label(Expense $expense, string $category): string
    {
        return mb_substr($expense->voucher_number.' · '.$category.($expense->payee ? ' · '.$expense->payee : ''), 0, 255);
    }

    private function clean(mixed $value, int $max): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
