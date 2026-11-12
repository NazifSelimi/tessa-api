<style>
    @page { margin: 34px 36px 46px 36px; }
    * { font-family: 'DejaVu Sans', sans-serif; }
    body { font-size: 11px; color: #222; }
    h1 { font-size: 20px; margin: 0; color: {{ $shop->get('brand_color') }}; }
    .muted { color: #777; }
    .small { font-size: 9.5px; }
    table { width: 100%; border-collapse: collapse; }
    .head td { vertical-align: top; padding: 0; }
    .shop-name { font-size: 18px; font-weight: bold; color: {{ $shop->get('brand_color') }}; }
    .box { border: 1px solid #ddd; padding: 8px 10px; }
    .label { font-size: 9px; text-transform: uppercase; color: #888; letter-spacing: .5px; }
    .lines th { background: {{ $shop->get('brand_color') }}; color: #fff; font-size: 9.5px; text-align: left; padding: 6px 6px; }
    .lines td { padding: 5px 6px; border-bottom: 1px solid #e6e6e6; vertical-align: top; }
    .r { text-align: right; }
    .c { text-align: center; }
    .totals td { padding: 3px 6px; }
    .grand td { font-size: 14px; font-weight: bold; border-top: 2px solid {{ $shop->get('brand_color') }}; padding-top: 6px; }
    .page-break { page-break-after: always; }
    .footer { position: fixed; bottom: -30px; left: 0; right: 0; font-size: 9px; color: #888; text-align: center; }
    .brand { font-size: 14px; font-weight: bold; color: {{ $shop->get('brand_color') }}; border-bottom: 2px solid {{ $shop->get('brand_color') }}; padding: 10px 0 3px; margin-top: 6px; }
    .category td { font-weight: bold; background: #f4f4f4; padding: 4px 6px; }
</style>
