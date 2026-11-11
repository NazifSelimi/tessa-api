<?php

namespace App\Services\Documents;

use App\Models\Order;

/** Everything an order summary or delivery note prints, worked out once. */
final class OrderDocumentData
{
    /** @return array<string, mixed> */
    public static function for(Order $order, string $locale): array
    {
        $order->loadMissing(['items.product', 'info', 'user']);

        $lines = [];
        $subtotal = 0.0;
        $pieces = 0;
        foreach ($order->items as $item) {
            $amount = (float) $item->price * (int) $item->quantity;
            $subtotal += $amount;
            $pieces += (int) $item->quantity;
            $lines[] = [
                'code' => (string) ($item->product?->manufacturer_code ?? ''),
                'name' => $item->product?->name ?? '#' . $item->product_id,
                'qty' => (int) $item->quantity,
                'price' => (float) $item->price,
                'amount' => $amount,
            ];
        }

        $info = $order->info;
        $user = $order->user;
        $name = trim(($info?->first_name ?? '') . ' ' . ($info?->last_name ?? ''))
            ?: trim(($user?->first_name ?? '') . ' ' . ($user?->last_name ?? ''))
            ?: (string) ($user?->name ?? '');

        return [
            'number' => (int) $order->id,
            'date' => DocumentText::date($order->created_at),
            'locale' => $locale,
            'lines' => $lines,
            'pieces' => $pieces,
            'subtotal' => $subtotal,
            'discount' => (float) $order->discount,
            'couponCode' => (string) ($order->coupon_code ?? ''),
            'storeCredit' => (float) $order->store_credit_used,
            'shipping' => (float) $order->shipping,
            'total' => (float) $order->total,
            'paymentMethod' => (string) ($order->payment_method ?? ''),
            'note' => trim((string) ($order->message ?? '')),
            'buyer' => [
                'name' => $name,
                'phone' => (string) ($info?->phone ?? $user?->phone ?? ''),
                'email' => (string) ($info?->email ?? $user?->email ?? ''),
                'address' => implode(', ', array_filter([
                    $info?->address,
                    trim(($info?->postal_code ?? '') . ' ' . ($info?->city ?? '')),
                    $info?->country,
                ])),
            ],
        ];
    }
}
