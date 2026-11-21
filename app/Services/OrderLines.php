<?php

namespace App\Services;

use App\Exceptions\PosException;
use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use App\Models\ProductVariant;
use App\Support\Money;

/**
 * Turns "variant + chosen add-ons + quantity" into a priced order line, using
 * today's menu prices. Every line is priced here on the server, both when it
 * is added to the cart and again when it is sent, so prices can't be altered
 * from the browser.
 */
class OrderLines
{
    public const MAX_QUANTITY = 999;

    /**
     * @param  list<int>  $optionIds
     * @return array{key: string, signature: string, variant_id: int, product_id: int, name: string,
     *     variant_name: ?string, unit_price: string, options: list<array{id: int, group: string, name: string, price: string}>,
     *     modifiers_total: string, combo: ?list<array{name: string, quantity: int}>, quantity: int, note: ?string,
     *     discount_type: ?string, discount_value: string}
     */
    public function build(int $variantId, array $optionIds = [], int $quantity = 1, ?string $note = null): array
    {
        $variant = ProductVariant::query()
            ->with(['product' => fn ($q) => $q->withTrashed()->with([
                'variants',
                'modifierGroups.options',
                'comboItems.variant.product.variants',
            ])])
            ->find($variantId);

        $product = $variant?->product;

        if (! $variant || ! $product || $product->trashed()) {
            throw new PosException('That item is no longer on the menu.');
        }

        if (! $product->is_available || ! $variant->is_available) {
            throw new PosException($product->name.' is not available right now.');
        }

        if ($quantity < 1 || $quantity > self::MAX_QUANTITY) {
            throw new PosException('Quantity must be between 1 and '.self::MAX_QUANTITY.'.');
        }

        $options = $this->resolveOptions($product->modifierGroups, array_values(array_unique(array_map('intval', $optionIds))));

        $note = trim((string) $note);
        $note = $note === '' ? null : mb_substr($note, 0, 255);

        $line = [
            'variant_id' => $variant->id,
            'product_id' => $product->id,
            'name' => $product->name,
            'variant_name' => $product->variants->count() > 1 ? $variant->name : null,
            'unit_price' => Money::of($variant->price),
            'options' => $options,
            'modifiers_total' => Money::sum(array_column($options, 'price')),
            'combo' => $product->isCombo()
                ? $product->comboItems->map(fn ($c) => [
                    'name' => $c->variant->product->variants->count() > 1
                        ? $c->variant->product->name.' ('.$c->variant->name.')'
                        : $c->variant->product->name,
                    'quantity' => $c->quantity,
                ])->values()->all()
                : null,
            'quantity' => $quantity,
            'note' => $note,
            'discount_type' => null,
            'discount_value' => '0.00',
        ];

        $line['signature'] = $this->signature($line);
        $line['key'] = bin2hex(random_bytes(6));

        return $line;
    }

    /** Lines with the same item, add-ons and note are merged in the cart. */
    public function signature(array $line): string
    {
        $options = array_column($line['options'], 'id');
        sort($options);

        return $line['variant_id'].'|'.implode(',', $options).'|'.mb_strtolower((string) $line['note']);
    }

    /** "+ Extra cheese, No onion" */
    public static function optionsLabel(array $line): string
    {
        return implode(', ', array_column($line['options'] ?? [], 'name'));
    }

    public static function lineTotal(array $line): string
    {
        $gross = Money::mul(Money::add($line['unit_price'], $line['modifiers_total']), $line['quantity']);

        return Money::sub($gross, Money::discount($gross, $line['discount_type'] ?? null, $line['discount_value'] ?? 0));
    }

    /**
     * Checks the chosen options against each add-on group's rules.
     *
     * @param  \Illuminate\Support\Collection<int, ModifierGroup>  $groups
     * @param  list<int>  $optionIds
     * @return list<array{id: int, group: string, name: string, price: string}>
     */
    private function resolveOptions($groups, array $optionIds): array
    {
        /** @var array<int, array{0: ModifierGroup, 1: ModifierOption}> $byId */
        $byId = [];
        foreach ($groups as $group) {
            foreach ($group->options as $option) {
                $byId[$option->id] = [$group, $option];
            }
        }

        $chosen = [];
        $perGroup = [];

        foreach ($optionIds as $id) {
            if (! isset($byId[$id])) {
                throw new PosException('One of the chosen add-ons does not belong to this item.');
            }

            [$group, $option] = $byId[$id];

            if (! $option->is_available) {
                throw new PosException($option->name.' is not available right now.');
            }

            $perGroup[$group->id] = ($perGroup[$group->id] ?? 0) + 1;
            $chosen[] = [
                'id' => $option->id,
                'group' => $group->name,
                'name' => $option->name,
                'price' => Money::of($option->price),
            ];
        }

        foreach ($groups as $group) {
            $count = $perGroup[$group->id] ?? 0;

            if ($count < $group->min_select) {
                throw new PosException($group->min_select === 1
                    ? 'Choose '.mb_strtolower($group->name).'.'
                    : 'Choose at least '.$group->min_select.' from '.$group->name.'.');
            }

            if ($group->max_select !== null && $count > $group->max_select) {
                throw new PosException('Choose up to '.$group->max_select.' from '.$group->name.'.');
            }
        }

        return $chosen;
    }
}
