# Catalogue Product Data Audit

**Scope:** read-only audit of the staging `tessa_staging` database and current API/UI code on 2026-09-21. No products, images, schema, migrations, or application data were changed.

**Audit outputs:**

- `storage/app/catalog-audits/20260921-product-data/product_catalog_audit.csv` on the staging API host contains 335 Fanola and RR Line rows.
- The existing read-only image audit is in the same directory as `image_audit.csv`, `image_audit.json`, and `image_sources.csv`.
- Parsed size and shade values in the CSV are explicitly marked `unverified`: they are derived from product names only and must not be used as manufacturer identity data.

## 1. Current Schema

### Product identity and saleable stock

`products` is the current saleable-item table. One row represents one item in stock, including a distinct hair-colour shade. Its live columns are:

| Field | Use now |
| --- | --- |
| `id` | Only durable product identity. |
| `name` | Product name and the main location of embedded size, shade code, and shade label. |
| `brand_id`, `category_id` | Links to brand and broad category. |
| `quantity`, `price`, `stylist_price`, `stylist_only` | Stock, retail/professional price, and audience. |
| `featured`, `compare_at_price` | Merchandising/pricing flags. |
| `description`, `tags` | Physical columns, but empty for all 440 live product rows. |

There is no `sku`, `ean`, `ean13`, `barcode`, `product_code`, `shade`, `variant`, `size`, or `volume` column. A temporary `products.sku` column was added in the February migration history and deliberately dropped in the following migration. `images.variant` exists, but it identifies a media rendition, not a sellable product choice.

`product_translations` has `product_id`, `locale`, and `description`. All 335 Fanola/RR Line products have translations in `en`, `mk`, and `shq`; descriptions are marketing/usage copy rather than a reliable product-information source. The application accessor shadows the physical `products.description` with the locale-specific translation.

### Supporting relationships

| Structure | Current purpose | Variant suitability |
| --- | --- | --- |
| `brands` | Brand name, description, logo, featured flag | Brand only. |
| `categories` | Broad type, hierarchy, merchandising | Not a product line or variant family. |
| `product_collections` plus pivot | Outcome-led collection assignment | Not a product family. Fanola has 95 assigned products; RR Line has none. |
| `hair_types` / `hair_concerns` pivots | Quiz and recommendation classification | Not identity. |
| `bundle_products` | Promotion composition | Not variants. Neither audited brand is currently in a bundle. |
| `images` | Polymorphic product media | Supports multiple assets, but not a shade/pack relationship. |
| `items` / cart and order flows | Refer to a `product_id` | A sale line has no immutable SKU/EAN/shade/pack snapshot. |

The live `images` table retains a legacy nullable `product_id` alongside populated polymorphic `imageable_type` and `imageable_id`. All 443 image rows use `App\\Models\\Product` polymorphic identity; the legacy `product_id` is unused. This is a schema cleanliness risk, not a reason to modify it in this phase.

### Admin, API, and frontend contract

The admin product form and API accept only name, brand, category, stock, retail/stylist price, stylist-only status, translations, hair-profile mappings, sale data, and one image upload. They do not collect SKU/EAN, line, shade, size, or variants.

`ProductResource` returns one primary `image`, a `media` array, name, translated description, prices, stock, brand/category, collection memberships, and hair-profile data. The frontend types expose only the media rendition variants `legacy`, `original`, `card`, `detail`, and `transparent_master`. The product page can consume gallery media, but product selection remains a single product ID. A local page comment mentions a variant/size selector, but none exists in the delivered product contract.

## 2. Existing Product Identity Data

### Reliable now

- Product row ID, brand, category, current prices, stock, stylist-only status, and translated descriptions.
- The exact stored product name.
- A product-to-image association for every audited Fanola/RR Line row.
- For 185 of 200 Hair Color rows, a leading numeric token in the name is a strong *candidate* shade code, for example `7.11` or `10.003`. It remains unverified until matched to an approved manufacturer list.
- For 325 of 335 audited rows, a size-like token is present in the name, for example `350ml`, `1000ml`, `10L`, or `500gr`. It is free text, not structured data.

### Missing or unsafe to infer

- No reliable EAN, SKU, barcode, or manufacturer product code was found in schema fields, product names, JSON fields, or descriptions. A broad text search initially matched words such as “cleanse”; no usable identifier must be inferred from those matches.
- There is no parent product/family identity, no product-line key, no shade name/code fields, no swatch, no pack-size field, no variant sort order, and no relationship between different sizes of the same item.
- Product names are not localized, so shade labels and name-level identity remain English/free text only.
- Product descriptions may explain use and ingredients but should not be treated as an authoritative identity source.

### Values currently embedded in names

Examples include `No Yellow Shampoo 350ml`, `1.0 - Black 100ml`, `Hydrogen 20vol - 6% 150ml`, and `Fiber Fix Professional Intro kit 70+100+100ml`. This is usable for a reviewed import worksheet, but it is not safe to automatically convert into live catalogue structure.

## 3. Fanola Catalogue Analysis

Fanola has **196** live rows across 13 categories. The largest group is **112 Hair Color** rows: **104** have a numeric leading shade candidate and **8** are named toners/correctors.

### Care, treatment, and styling families detected from names

These are name-derived groupings, not normalized database fields:

| Family | Rows | Evidence in current names |
| --- | ---: | --- |
| No Yellow | 6 | Shampoo 100/350/1000 ml, Mask 350/1000 ml, Incredible Foam 250 ml. |
| No Orange | 4 | Shampoo and Mask, each 350/1000 ml. |
| No Red | 4 | Shampoo and Mask, each 350/1000 ml. |
| Fiber Fix | 7 | Numbered professional steps, shampoo, mask, leave-in, and intro kit. |
| Nutri Care | 10 | Shampoo, conditioner, mask, lotion, fluid crystals, and split-ends cream; 350 ml, 500 ml, 1000 ml, and 10 L packs. |
| Botugen | 7 | Shampoo/mask 300/1000 ml, filler spray, filler, and set. |
| Keraterm | 6 | Shampoo/mask 300/1000 ml, spray, and set. |
| Energy/Energising | 9 | Shampoo and lotion entries use inconsistent family spelling. |
| Volume | 4 | Shampoo and conditioner in 350/1000 ml. |
| Curly Shine | 3 | Shampoo 350/1000 ml and mask 500 ml. |
| Wonder Curl | 2 | Shampoo and mask 1000 ml. |
| Fantouch | 4 | Hairspray, volumising hairspray, and mousse. |
| Frequent | 2 | Shampoo 350 ml and mask 1500 ml. |

Other present but not yet a clean product family include Color Mask (9 named colour masks, all 200 ml), White Bleaching Powder 500 gr, Perm products, Styling Tools Curl Control Fluid, and Easy Curl cream.

### Colour range

Fanola Hair Color uses independently saleable rows such as `7.11 - Blonde Intense Ash 100ml`, `10.2F - Blonde Platinum Fantasy Violet 100ml`, and `R.66 - Red Booster 100ml`. The current database cannot state which colour-system line these belong to, nor link the 104 numeric shades to one parent range. Eight rows are named correctors/toners, including Blue, Giallo, Natural, Red, Silver, and `Color 11.13`; they require reviewed classification rather than a generic numeric-shade parser.

## 4. RR Line Catalogue Analysis

RR Line has **139** live rows across 10 categories. It has **88 Hair Color** rows: **81** numeric leading shade candidates and **7** named toners.

### Care, technical, and styling families detected from names

| Family | Rows | Evidence in current names |
| --- | ---: | --- |
| Real Argan | 6 | Shampoo 350/1000 ml, Mask 500/1000 ml, conditioner spray, fluid. |
| Real Keratin | 6 | Shampoo 350 ml/1000 ml/10 L, Mask 500/1000 ml, lotion. |
| Real Macadamia | 9 | Shampoo 350/1000 ml, Mask 500/1000 ml, fluid, spray, and tester rows. |
| Real Color | 5 | Goji shampoo 350/1000 ml, mask 500/1000 ml, and fluid. |
| Real Silver | 4 | Shampoo and mask, 350/1000 ml. |
| Real Energy | 3 | Shampoo 350/1000 ml and 12x10 ml lotion. |
| Hydrogen Peroxide | 9 | 3%, 6%, 9%, and 12%; several 150 ml/1000 ml packs. |
| Styling | 6 | Anti-frizz glossing spray, curl cream, hair spray, paste, thermal spray, root spray. |

RR Line also has Blue Bleaching Powder 500 gr and a group of five Fluid entries. The exact line for several colour, fluid, and bleach rows is not encoded in the current database.

### Colour range

Numeric examples include `7.11 - Intense Blonde Ash 100ml`, `10.003 - Natural Blonde Platinum Warm 100ml`, and `12.8 - Super Blonde Pearl Extra 100ml`. Named toner rows include Argento, Beige, Blu, Irise, Neutro, Perla, and Rosso. They need explicit variant data; a missing leading number is not evidence that these are duplicates or invalid products.

## 5. Duplicate and Problem Findings

### Product data

- No exact duplicate `(brand, category, name)` rows were found among Fanola and RR Line.
- There are likely duplicate or inconsistent Fanola Energy/Energising records: `Energy - Energizing Shampoo` and `Energising Shampoo` exist at both 350 ml and 1000 ml; the 12 x 10 ml lotion also appears with both spellings. These require a supplier-sheet match before merge or retirement.
- `Rr Line` is inconsistent in display capitalization; it should be normalized only after confirming the preferred brand spelling.
- Examples of name-quality issues: `11.0 ... 100m` (likely missing `l`), `12 x 10mil` (nonstandard unit spelling), `5.4 - Light Chesnut Copper` (spelling), `10.3 Blonde Platinum Golden` and `6.4 Dark Blonde Copper` (missing separator), and `Real Color  Goji` (double space).
- Ten audited products have no recognizable size token. Some are legitimate sets/testers, but the following individual sellable-looking rows need pack-size confirmation: RR Line `8.2 - Light Blonde Violet`, `Blu - Blue Toner`, and `Extra Strong Hair Spray`.
- All 200 Hair Color rows are independent product IDs. They are functionally variants but are not structurally modelled as variants.
- Physical `products.description` and `products.tags` are empty for all 440 products. The descriptions used by the API come from translations.

### Images

- All 335 audited Fanola/RR Line products have one image record; none is missing at the relationship level.
- All 335 current assets are legacy media, below the 1000 px standard, and non-square. This is a review flag, not a directive to replace them.
- No duplicate image path was found. Four file-content groups are shared between more than one product:
  - RR Line 6.4 Dark Blonde Copper and 8.4 Light Blonde Copper.
  - RR Line 6.3 Dark Blonde Golden and 6.32 Dark Blonde Beige.
  - RR Line Real Keratin 1000 ml and Real Keratin 10 L.
  - The three Real Macadamia tester combinations.
- The first three shared-hash groups are potentially wrong-image matches because the products differ in shade or pack size. The tester group may be intentional, but still requires visual review.

## 6. Image Matching Risks and Current Architecture

The current system can support multiple images per product through the polymorphic `images` table. The upload service supports the following rendition labels:

- `original` for a manufacturer source.
- `transparent_master` for the cleaned master asset.
- `card` and `detail` WebP derivatives.
- `legacy` for existing assets.

It also has `alt`, `sort_order`, `background`, `review_status`, and JSON `metadata`. The API returns `media`; the product page can render a gallery. Therefore, original manufacturer images, transparent masters, card/detail derivatives, and multiple gallery images are already supportable without an image-table redesign.

Not yet first-class: asset role (`shade_chart`, `before_after`, `technical`), linkage to a particular sellable variant, source-rights/provenance, and manufacturer packaging revision. `metadata` can temporarily record such data, but an asset-role field should be added only if the approved asset intake proves the values need querying or validation.

**Do not match or replace an image by a parsed name alone.** The required match order is EAN, manufacturer SKU, verified product/size/shade, then owner-reviewed visual confirmation.

## 7. Recommended Schema

Use a small product-family plus `product_variants` model. Adding nullable fields directly to `products` would preserve the current one-row-per-SKU design, but it cannot represent shared family content with several sizes and shades cleanly. A family/variant split is better for colours and also handles normal care products.

### `products` becomes the family/catalogue product

Keep the existing primary key and brand/category relationships. Add only family-level data that must be queried:

- `product_line` (nullable string initially, or a future normalized line entity if the supplier intake shows lines are shared across more brands).
- `is_active` if a staged migration needs to hide retired legacy rows while preserving order history.

Move stock, live price, professional price, and current sellable identity to variants after migration. Do not remove the old fields until all carts, orders, admin flows, and integrations have been migrated and verified.

### `product_variants`

| Field | Why needed |
| --- | --- |
| `id`, `product_id` | Variant identity and family relationship. |
| `sku`, `ean` | Manufacturer/distributor matching and barcode scanning; each nullable until verified, unique when present. |
| `shade_code`, `shade_name`, `shade_sort_order`, `swatch_hex` | Hair-colour and toner ordering; nullable for non-colour products. |
| `size_value`, `size_unit` | Structured packs such as 100 ml, 1 L, or 500 g; nullable for kits. |
| `variant_label` | Human-readable exception label for kits, testers, or a named toner. |
| `price`, `stylist_price`, `quantity`, `stylist_only`, `is_active` | Per-pack/per-shade saleability. |
| `sort_order` | Stable display order in selectors and professional grids. |

Use a partial/conditional unique constraint for non-null SKU/EAN where the database supports it, or enforce uniqueness in application validation plus normal unique indexes according to the target database. Do not invent identifiers to satisfy an index.

### Related changes in a later implementation phase

- Move product images to `product_variants` for pack- or shade-specific packaging. Keep a family gallery for shared education, technical, and before/after assets.
- Add `asset_role` only if required by the approved asset workflow: `packshot`, `gallery`, `shade_chart`, `before_after`, `technical` are sufficient initial roles.
- Have cart/order lines store `product_variant_id` and immutable snapshots for SKU/EAN, family name, variant label, shade, size, and unit price. This preserves historical orders when catalogue wording changes.

## 8. Migration Plan

1. Obtain official Fanola and RR Line line sheets with EAN/SKU, pack size, shade codes/names, retail/professional status, and packaging revision.
2. Create a reviewed import worksheet from this audit CSV. Treat all parsed values as proposed values, not source truth.
3. Add family/variant tables and read-only import validation. Do not write variants from name parsing alone.
4. Create family rows and variants in a transaction with an explicit legacy `products.id` mapping. Keep current product rows and images intact during the transition.
5. Add family and variant API resources, admin editing, variant-aware search, and a professional shade grid/bulk order contract.
6. Migrate carts and orders to variants with immutable snapshots; retain legacy product references during a verified compatibility window.
7. Associate approved images only after EAN/SKU and pack/shade validation. Preserve originals and review metadata.
8. Reconcile stock, pricing, bundles, collections, permissions, and historical orders before turning off legacy product-row sales.

## 9. Risks

- Auto-parsing names can join the wrong shade, size, or product line. It must be used only to accelerate human review.
- The same product title is not enough to identify a pack: Real Keratin 1000 ml and 10 L currently share the same image hash.
- Existing colour rows have no confirmed parent range, so assigning all Fanola or RR Line colour rows to one family would be an unsupported assumption.
- Current order items do not preserve a variant-level identity or snapshot; a migration must preserve historic reporting and fulfilment semantics.
- The old `images.product_id` column and all-legacy media state should be cleaned only in a separately tested migration after the new media relationships are proven.
- The audit found no missing image records, but it did not approve product correctness, packaging revision, licensing, or visual quality. Those remain manual review work.
