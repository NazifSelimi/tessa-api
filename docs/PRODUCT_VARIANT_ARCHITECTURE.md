# Product Variant Architecture

**Status:** design approved for review; no migrations or application changes implemented.

**Scope:** additive catalogue identity layer for Tessa. It preserves the current `products` table, all existing IDs, saleability, URLs, carts, orders, images, and integrations.

## 1. Current Architecture

The current `products` table is both the catalogue record and saleable stock unit. Every hair-colour shade and pack size is an independent product row. `products.id` is the identity used by current storefront URLs, carts, order items, bundles, sales, image relationships, product collections, hair profile mappings, recommendations, reports, admin tools, quick order, search, and the sitemap.

The database has ten foreign keys to `products`: `bundle_products`, `carts`, `images` (legacy nullable column), `items`, `product_collection_product`, `product_hair_concern`, `product_hair_type`, `product_translations`, `product_user`, and `sales`. The active image relationship is polymorphic and uses `images.imageable_type`/`imageable_id`; all current image rows target `App\\Models\\Product`.

Categories are a flat product-type taxonomy. All 16 current categories have no parent. `Hair Color`, `Shampoo`, `Mask`, `Hydrogen Peroxide`, and `Bleach and De Color` are product types, not product lines. Product lines such as No Yellow, Fiber Fix, Real Argan, and Real Keratin are embedded in names only.

## 2. Problems

- A product row cannot state that it is a 350 ml variant of the same family as a 1000 ml row.
- Hair shades are independent products without a parent colour system, exact shade field, deterministic ordering, or swatch.
- SKU, EAN/barcode, manufacturer code, structured size, and structured product line do not exist.
- Product names contain identity fragments but are not a safe source of truth.
- Existing media can be multi-image, but is not explicitly tied to a product family versus a size/shade variant.
- Historical orders read current product names, brand, category, and images, so a future rename would change historic order display.

## 3. Proposed Architecture

### Decision: retain `products` as the variant table

Do **not** create a parallel `product_variants` table. The smallest safe architecture is to retain every current `products` row as the saleable variant and add a new family layer above it. This preserves `products.id` and avoids a risky rewrite of every existing foreign key.

Add two new tables:

### `product_lines`

| Field | Notes |
| --- | --- |
| `id` | Primary key. |
| `brand_id` | Required foreign key to `brands`; avoids Fanola-specific logic. |
| `name`, `slug` | Human name and URL-safe identifier, unique per brand. |
| `sort_order`, `is_active` | Controlled presentation and retirement. |
| timestamps | Standard audit fields. |

### `product_families`

| Field | Notes |
| --- | --- |
| `id` | Primary key. |
| `brand_id`, `category_id` | Required brand and product-type context. |
| `product_line_id` | Nullable until verified; a family may intentionally have no line. |
| `slug` | Stable family URL key, unique per brand. |
| `name` | Conceptual product name, for example `No Yellow Shampoo`. |
| `description` | Optional shared catalogue copy. Existing translated descriptions stay untouched initially. |
| `is_active`, `sort_order` | Publication and presentation. |
| timestamps | Standard audit fields. |

Extend the existing `products` table additively with nullable fields:

| Field | Why it belongs on the existing product row |
| --- | --- |
| `product_family_id` | Connects the preserved saleable ID to its conceptual family. |
| `sku`, `ean`, `manufacturer_code` | Verified manufacturer/distributor identity. All strings: EAN must preserve leading zeroes. |
| `variant_name` | User-facing exception label for kits, testers, named toners, or non-size distinctions. |
| `shade_code`, `shade_name`, `shade_sort_order`, `swatch_hex` | Exact colour data. `shade_code` is a string, never a float. |
| `size_value`, `size_unit`, `package_label` | Structured normal packs and exact display for multipacks such as `12 x 10 ml` or kits. Values remain strings; `1000 ml` and `1 L` are not automatically merged. |
| `sort_order`, `is_active` | Stable display and gradual retirement, independent of stock. |

Existing stock, prices, stylist price, stylist-only flag, featured flag, compare-at price, and existing product ID remain exactly where they are: they are variant-level properties today and must remain so.

Suggested later constraints, applied only after data verification: unique non-null EAN, and brand-scoped unique non-null SKU/manufacturer code. No placeholder identifier may be created merely to satisfy an index.

## 4. ER-Style Relationship Explanation

```text
Brand 1 --- * ProductLine 1 --- * ProductFamily 1 --- * Product (existing row / saleable variant)
                                      |                         |
                                      |                         +--- * Image (variant packshot/gallery/swatch)
                                      +--- * Image (shared family gallery/technical/before-after)

Product (existing ID) --- * Cart
Product (existing ID) --- * Item / historic order line
Product (existing ID) --- * Bundle, Sale, Collection, Hair profile mapping
```

The polymorphic `images` table already supports both product-family and product ownership. A family image will use `imageable_type=ProductFamily`; a pack/shade image will use the existing `Product` owner. Existing images remain on their current product row until manually verified and migrated.

## 5. Migration Strategy

### Phase A: additive schema only

Create `product_lines` and `product_families`. Add nullable family/identity fields to `products`. Add nullable snapshot fields to `items`. Do not change reads, writes, routes, foreign keys, or existing image ownership.

### Phase B: reviewed backfill

Use the approved supplier/distributor worksheet, not names alone. Create a family only for rows with an approved mapping and populate variant fields only from verified data. Keep all current product rows, IDs, prices, stock, images, and mappings unchanged.

### Phase C: dual-read API and admin

Continue returning the current product response unchanged. Add optional `family` and `variant` objects only when mapped. Add family/variant management to the admin without removing legacy product editing. New catalogue work should create a family and a product variant together; legacy products remain manageable.

### Phase D: image ownership and commerce rollout

After EAN/SKU and pack/shade review, attach approved shared assets to the family and pack/shade assets to the existing product variant. Add family detail endpoints, variant selectors, variant-aware search, and professional shade-grid ordering. Carts and promotions continue to use existing product IDs.

### Phase E: deferred cleanup

Only after every integration uses the new model, decide whether legacy duplication can be removed. This phase is explicitly out of scope; no current column, product ID, URL, order, or media record is removed now.

## 6. Backwards Compatibility Strategy

- `products.id` remains the permanent public and commerce identity for the current product/variant.
- Existing `/product/{id}` URLs, cart payloads, order requests, bundles, sales, quick order, recommendations, reports, and admin endpoints continue to accept the current ID.
- The existing `ProductResource` shape remains valid. New data is additive and nullable.
- Unmapped products simply have no `family`/`variant` object. Existing clients do not need a migration to continue working.
- Existing product translations, collection membership, hair mappings, and images stay attached to the same product IDs.
- Do not move or merge product rows as part of the first migration.

## 7. Order-History Implications

Current `items` stores only `product_id`, `quantity`, and `price`. The 80 current staging order items have product IDs and unit-price snapshots, but order output reads product name, brand, category, and image from the live product. A rename, merge, or media change would alter history.

When schema implementation is approved, add nullable snapshots to `items` and populate them for every **new** order in the same transaction as stock reduction:

- `product_name_snapshot`, `brand_name_snapshot`, `category_name_snapshot`
- `variant_name_snapshot`, `sku_snapshot`, `ean_snapshot`, `manufacturer_code_snapshot`
- `shade_code_snapshot`, `shade_name_snapshot`
- `size_value_snapshot`, `size_unit_snapshot`, `package_label_snapshot`
- optional `image_reference_snapshot` for historic visual continuity

`items.price` already supplies the immutable unit-price snapshot. Keep `items.product_id` unchanged for traceability and returns. Historic existing orders should fall back to live data until a deliberately reviewed archival backfill is approved; do not fabricate snapshots from current names after the fact.

## 8. Image Strategy

No image download, replacement, or conversion is part of this work. The existing media pipeline can preserve `original`, create `transparent_master`, `card`, and `detail` derivatives, and expose a multi-image gallery.

Use the existing polymorphic relationship as follows:

- Family owner: approved shared campaign, technical, before/after, shade-chart, or generic product images.
- Existing product owner: exact packshot for a size, colour tube for a shade, shade swatch, and variant gallery images.

Do not attach a shade image without verified shade code plus a visual review. Add an `asset_role` field only in the later media migration if roles need querying; the initial values should be limited to `packshot`, `gallery`, `shade_chart`, `before_after`, and `technical`.

## 9. Colour and Shade Strategy

`shade_code` is a nullable string. It preserves `7.1`, `7.11`, `7.13`, `8.1`, `10.003`, `R.66`, and leading/trailing zero semantics exactly. It must never be cast to decimal/float.

`shade_name` stores the manufacturer-approved label, `shade_sort_order` governs professional-grid order, and `swatch_hex` is optional because a digital colour swatch is not authoritative without manufacturer approval. Named toner/corrector products may use `variant_name` with a nullable `shade_code` where the source does not define one.

The current 200 Hair Color rows are all classified as deferred in the migration plan because the product line/range is not reliably encoded. No automatic family merge is permitted.

## 10. Size Strategy

`size_value` and `size_unit` are strings, allowing exact supplier values such as `350`/`ml`, `1000`/`ml`, `10`/`L`, and `500`/`gr`. `package_label` preserves multipacks and kits where simple size fields are insufficient. Store source values as supplied; normalization for display can be a separate read-only formatter later.

Never infer that `1000 ml` and `1 L` are the same SKU or packaging. Any conversion is a display concern only after a verified manufacturer match.

## 11. Admin UX

The current admin form edits one product row plus translations and one upload. Extend it in a later implementation phase with two deliberately separated areas:

### Product family

- Brand, product line, category/type, family name, family description, publishing state, shared media.

### Variant (existing product ID)

- SKU, EAN, manufacturer code, variant label, shade code/name/swatch, pack size/package label, prices, professional status, stock, publishing state, exact variant media.

The variant image upload must display a persistent identity header containing family, current product ID, shade code/name, and pack. It must require confirmation when an image is being attached to a colour variant and warn when the selected image is already associated with a different shade/pack. The UI should never let a generic family image silently replace a 7.11 or 7.1 packshot.

## 12. API Changes

Keep all existing fields and IDs. Add nullable fields gradually:

```json
{
  "id": "410",
  "name": "No Yellow Shampoo 350ml",
  "family": {
    "id": "42",
    "slug": "no-yellow-shampoo",
    "name": "No Yellow Shampoo",
    "line": { "id": "7", "name": "No Yellow" }
  },
  "variant": {
    "id": "410",
    "sku": null,
    "ean": null,
    "shadeCode": null,
    "sizeValue": "350",
    "sizeUnit": "ml",
    "packageLabel": null,
    "media": []
  }
}
```

Family endpoints can be introduced later as `/product-families/{slug}` and return the ordered, visible variants. Product detail routes remain on `/products/{id}` and continue to resolve the same existing row. Cart, checkout, bundle, and quick-order mutations continue to submit `product_id` until a versioned variant contract is deliberately introduced.

## 13. Risks

- Incorrect name parsing could group different colour systems, shades, or pack formats. The migration-plan CSV is a review queue, not import authority.
- A generic family image may be visually attractive but wrong for a shade or size. Keep existing image ownership until verified.
- New unique identifier constraints can fail if distributor source data contains duplicates or blanks. Validate before enforcing.
- Updating order output before snapshot fields are written would preserve the current historical-data bug.
- A half-migrated catalogue can expose family names without valid variants. All new API fields must be nullable and dual-read.
- Category remains a product type; trying to make it also represent a product line would reintroduce the current ambiguity.

## Approval Gate

This document recommends an additive `product_lines` + `product_families` layer while preserving `products` as the existing saleable variant table. **Approval is required before creating migrations, models, API fields, admin changes, backfill jobs, or order snapshot columns.**
