<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class OrderResource extends JsonResource
{
    public function toArray($request)
    {
        $subtotal = $this->items->reduce(function ($sum, $item) {
            return $sum + ($item->price * $item->quantity);
        }, 0);

        $statusMap = [
            \App\Models\Order::STATUS_PENDING => 'pending',
            \App\Models\Order::STATUS_PAID => 'confirmed',
            \App\Models\Order::STATUS_SHIPPED => 'shipped',
            \App\Models\Order::STATUS_CANCELLED => 'cancelled',
        ];

        $status = $statusMap[$this->status] ?? 'pending';

        $user = $this->user;

        return [
            'id' => (string) $this->id,
            'userId' => (string) $this->user_id,
            'items' => $this->items->map(function ($item) {
                $product = $item->product;
                $image = null;

                if ($product && $product->relationLoaded('images')) {
                    $img = $product->images instanceof \Illuminate\Database\Eloquent\Collection
                        ? $product->images->first()
                        : $product->images;
                    if ($img) {
                        $image = asset('storage/images/' . $img->name);
                    }
                }

                return [
                    'productId' => (string) $item->product_id,
                    'productName' => $product?->name ?? 'Unknown',
                    'quantity' => (int) $item->quantity,
                    'unitPrice' => (float) $item->price,
                    'total' => (float) ($item->price * $item->quantity),
                    'image' => $image,
                ];
            })->values(),
            'subtotal' => (float) $subtotal,
            'discount' => (float) ($this->discount ?? 0),
            'total' => (float) $this->total,
            'status' => $status,
            'shippingAddress' => [
                'fullName' => trim(($user?->first_name ?? '') . ' ' . ($user?->last_name ?? '')),
                'phone' => $user?->phone,
                'address' => $user?->address,
                'city' => $user?->city,
                'zipCode' => $user?->postcode,
            ],
            'customMessage' => $this->message,
            'couponCode' => $this->coupon?->code,
            'createdAt' => $this->created_at?->toISOString(),
            'updatedAt' => $this->updated_at?->toISOString(),
        ];
    }
}
