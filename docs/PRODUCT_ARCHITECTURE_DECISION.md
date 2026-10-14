# Product Architecture Decision

## Approved Phase A

Tessa keeps `products` as the saleable product/variant table. Existing `products.id` values remain the public URL, cart, order, promotion, stock, price, and media identity. A separate `product_variants` table is intentionally not created because it would require moving every existing foreign-key relationship and would put historic orders and current integrations at risk.

Phase A adds context above the current rows without changing their behaviour:

- `product_lines`: optional, brand-scoped lines with a unique `(brand_id, slug)`.
- `product_families`: brand/category-scoped conceptual products, optionally assigned to a product line, with a unique `(brand_id, slug)`.
- Nullable identity fields on `products`: family reference, SKU/EAN/manufacturer code, variant/shade/size/package details, and presentation lifecycle fields.
- Nullable identity snapshot fields on `items`: product and brand name, SKU/EAN/manufacturer code, variant, shade, and exact package label. `items.price` already persists the immutable unit price, so no duplicate unit-price snapshot is added.

## Relationship Diagram

```text
Brand
  |
  +--- ProductLine
  |
  +--- ProductFamily
          |
          +--- Product (existing saleable ID)
                  |
                  +--- Cart
                  +--- Order Item
                  +--- Image
```

`ProductFamily` can also own polymorphic images for future shared media. Existing images remain on `Product`; no image ownership or upload behaviour changes in Phase A.

## Migration Safety Rules

- The migration creates tables and nullable columns only.
- No product rows, IDs, names, descriptions, prices, stock, images, carts, orders, or relationships are rewritten.
- No family, line, SKU, EAN, shade, size, or package data is backfilled.
- Brand/category deletes are restricted once a new line/family references them; deleting a product line sets the optional family link to null.
- The migration is reversible: it removes Phase A columns before dropping the new tables.

## Deferred to Phase B and Later

Phase B requires approved manufacturer/distributor mappings before creating or attaching any product families, lines, identifiers, sizes, shades, or images. API serialization, storefront routes, search, cart/checkout, admin UI, image ownership, order dual-write, and historical backfill are deliberately unchanged in Phase A.

No catalogue identity data has been backfilled.
