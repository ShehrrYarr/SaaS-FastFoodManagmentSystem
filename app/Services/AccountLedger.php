<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Support\Money;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The only way an account balance changes. Each posting locks the account
 * row, so two tills paying into the Cash Drawer at once still produce the
 * correct running balance.
 */
class AccountLedger
{
    /**
     * @param  string|int|float  $amount  Signed: positive adds money, negative takes it out.
     */
    public function post(
        Account $account,
        string $type,
        string|int|float $amount,
        ?Model $source = null,
        ?string $note = null,
        ?DateTimeInterface $at = null,
        ?int $businessDayId = null,
    ): AccountTransaction {
        if (! array_key_exists($type, AccountTransaction::TYPES)) {
            throw new InvalidArgumentException("Unknown account transaction type [{$type}].");
        }

        return DB::transaction(function () use ($account, $type, $amount, $source, $note, $at, $businessDayId) {
            $locked = Account::query()->acrossShops()->whereKey($account->getKey())->lockForUpdate()->firstOrFail();

            $amount = Money::of($amount);
            $balance = Money::add($locked->current_balance, $amount);

            $transaction = new AccountTransaction([
                'tenant_id' => $locked->tenant_id,
                'account_id' => $locked->id,
                'type' => $type,
                'amount' => $amount,
                'balance_after' => $balance,
                'business_day_id' => $businessDayId,
                'user_id' => Auth::guard('shop')->id(),
                'note' => $note,
                'transacted_at' => $at ?? now(),
            ]);

            if ($source) {
                $transaction->source()->associate($source);
            }

            $transaction->save();

            $locked->forceFill(['current_balance' => $balance])->save();
            $account->setRawAttributes($locked->getAttributes(), true);

            return $transaction;
        });
    }
}
