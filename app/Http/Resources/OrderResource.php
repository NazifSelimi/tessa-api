<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

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
        $shippingInfo = $this->relationLoaded('info') ? $this->info : null;
        $fullName = trim(implode(' ', array_filter([
            $shippingInfo?->first_name ?? $user?->first_name,
            $shippingInfo?->last_name ?? $user?->last_name,
        ])));
        $country = $shippingInfo?->country ?? 'MK';
        $state = $country === 'MK' ? 'North Macedonia' : $country;

        // Expose who placed the order so admins can tell apart retail vs. pro
        // (stylist) pricing at a glance. No user → guest checkout.
        $roleMap = [
            \App\Models\User::ROLE_ADMIN => 'admin',
            \App\Models\User::ROLE_STYLIST => 'stylist',
            \App\Models\User::ROLE_USER => 'user',
        ];
        $customerRole = $user ? ($roleMap[(int) $user->role] ?? 'user') : 'guest';

        return [
            'id' => (string) $this->id,
            'userId' => $this->user_id ? (string) $this->user_id : null,
            'customerRole' => $customerRole,
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

                $brandName = ($product && $product->relationLoaded('brand'))
                    ? $product->brand?->name
                    : null;
                $categoryName = ($product && $product->relationLoaded('category'))
                    ? $product->category?->name
                    : null;

                return [
                    'productId' => (string) $item->product_id,
                    'productName' => $product?->name ?? 'Unknown',
                    'brandName' => $brandName,
                    'categoryName' => $categoryName,
                    'quantity' => (int) $item->quantity,
                    'unitPrice' => (float) $item->price,
                    'total' => (float) ($item->price * $item->quantity),
                    'image' => $image,
                ];
            })->values(),
            'subtotal' => (float) $subtotal,
            'discount' => (float) ($this->discount ?? 0),
            'shipping' => (float) ($this->shipping ?? 0),
            'tax' => (float) ($this->tax ?? 0),
            'total' => (float) $this->total,
            'status' => $status,
            'paymentMethod' => $this->payment_method ?? 'cod',
            'paymentStatus' => $this->payment_status ?? 'pending',
            'shippingAddress' => [
                'fullName' => $fullName,
                'phone' => $shippingInfo?->phone ?? $user?->phone ?? '',
                'address' => $shippingInfo?->address ?? $user?->address ?? '',
                'city' => $shippingInfo?->city ?? $user?->city ?? '',
                'state' => $state,
                'zipCode' => $shippingInfo?->postal_code ?? $user?->postcode ?? '',
                'country' => $country,
            ],
            'customMessage' => $this->message,
            'couponCode' => $this->coupon?->code ?? $this->coupon_code,
            'trackingNumber' => $this->tracking_number,
            'createdAt' => $this->created_at?->toISOString(),
            'updatedAt' => $this->updated_at?->toISOString(),
        ];
    }
}
