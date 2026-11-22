<?php

namespace App\Services;

use App\Exceptions\PosException;
use App\Models\Account;
use App\Models\AccountTransaction;
use App\Support\Money;
use App\Tenancy\TenantContext;
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
     * @param  bool  $mustCover  Refuse a withdrawal the account's balance cannot cover.
     */
    public function post(
        Account $account,
        string $type,
        string|int|float $amount,
        ?Model $source = null,
        ?string $note = null,
        ?DateTimeInterface $at = null,
        ?int $businessDayId = null,
        bool $mustCover = false,
    ): AccountTransaction {
        if (! array_key_exists($type, AccountTransaction::TYPES)) {
            throw new InvalidArgumentException("Unknown account transaction type [{$type}].");
        }

        return DB::transaction(function () use ($account, $type, $amount, $source, $note, $at, $businessDayId, $mustCover) {
            $locked = Account::query()->acrossShops()->whereKey($account->getKey())->lockForUpdate()->firstOrFail();

            $amount = Money::of($amount);
            $balance = Money::add($locked->current_balance, $amount);

            if ($mustCover && Money::isNegative($amount) && Money::isNegative($balance)) {
                $shop = app(TenantContext::class)->shop();
                $has = $shop ? $shop->money($locked->current_balance) : $locked->current_balance;

                throw new PosException($locked->name.' only has '.$has.'.');
            }

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

    /**
     * Locks several accounts in id order (so two transfers in opposite
     * directions queue instead of deadlocking) and returns them by id.
     *
     * @param  list<int>  $ids
     * @return \Illuminate\Support\Collection<int, Account>
     */
    public function lock(array $ids)
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);

        return Account::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
    }
}
