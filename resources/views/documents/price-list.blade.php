@php use App\Services\Documents\DocumentText as T; @endphp
<!DOCTYPE html>
<html lang="{{ $locale }}">
<head><meta charset="utf-8">@include('documents._style')</head>
<body>
<div class="footer">{{ $shop->get('name') }}@if ($shop->phones()) · {{ __('documents.order_contact', [], $locale) }}: {{ $shop->phones() }}@endif @if ($shop->get('website_label')) · {{ $shop->get('website_label') }}@endif</div>

@include('documents._header', [
    'title' => __('documents.price_list_title', [], $locale),
    'subtitle' => __($audience === 'business' ? 'documents.audience_business' : 'documents.audience_retail', [], $locale),
    'meta' => '<div style="margin-top:6px;">' . e(__('documents.valid_from', ['date' => T::date(null)], $locale)) . '<br>' . e(__('documents.products_count', ['count' => $list['count']], $locale)) . ($inStockOnly ? '<br>' . e(__('documents.in_stock_only', [], $locale)) : '') . '</div>',
])

@if ($list['count'] === 0)
    <p style="margin-top: 30px;">{{ __('documents.empty', [], $locale) }}</p>
@endif

@foreach ($list['groups'] as $group)
    <div class="brand">{{ $group['brand'] }}</div>
    <table class="lines">
        @foreach ($group['categories'] as $category)
            <tr class="category"><td colspan="3">{{ $category['category'] }}</td></tr>
            @foreach ($category['rows'] as $row)
                <tr>
                    <td class="small muted" style="width: 14%;">{{ $row['code'] }}</td>
                    <td>{{ $row['name'] }}@if ($row['shades'] > 1) <span class="muted small">· {{ __('documents.shades', ['count' => $row['shades']], $locale) }}</span>@endif</td>
                    <td class="r" style="width: 22%; white-space: nowrap;">{{ $row['from'] ? __('documents.from_price', ['price' => T::money($row['price'], $locale)], $locale) : T::money($row['price'], $locale) }}</td>
                </tr>
            @endforeach
        @endforeach
    </table>
@endforeach

<p class="small muted" style="margin-top: 16px;">{{ __('documents.prices_note', [], $locale) }}</p>
</body>
</html>
