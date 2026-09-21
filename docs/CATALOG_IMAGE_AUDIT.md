# Catalogue image audit

Run the audit only against a reachable copy of the Tessa database:

```bash
php artisan catalog:audit-images
```

It is read-only: it neither changes database records nor deletes, downloads, or transforms image files. It writes `image_audit.csv`, `image_audit.json`, and an empty, review-ready `image_sources.csv` under `storage/app/catalog-audits/<timestamp>/`.

The command defaults to Fanola and RR Line. To audit another brand, pass `--brand="Brand Name"`. Rows contain the image reference, public URL, local existence, dimensions, MIME type, size, hash, and deterministic flags for missing files, images below 1000 px, non-square non-card images, pending pipeline review, and exact shared image files. A shared hash is a review signal, not proof that an image is incorrect.

The current `products` schema has no retained EAN, manufacturer SKU, explicit variant, shade, or size fields. The audit leaves these values blank rather than guessing from names. The `image_sources.csv` must be completed with a verified source URL and match evidence before importing a replacement. Match order is EAN, manufacturer SKU, exact product/shade/size, product and size, then only a clearly labelled fuzzy candidate.

Manual visual review remains required for blur, compression, crops, watermarks, unrelated products, packaging/shade/size correctness, and source rights. Do not replace anything at uncertain confidence. The current upload pipeline preserves the original, then produces card/detail WebP derivatives; its intended standard is a clean, centred 1:1 source of at least 1000×1000 px (1500 px preferred).

## Architecture found

- Backend: Laravel 12 / PHP 8.2; storefront: separate React + Vite application.
- Products belong to a brand and category; categories can have a parent. Product collections are a confirmed/uncertain many-to-many relationship.
- Images are polymorphic `images` rows ordered by `sort_order`; new uploads preserve `original` and create `card`, `detail`, and sometimes `transparent_master` assets under `public/storage/images`.
- The public API chooses a card image for listings and returns all media with variants for detail views. Search, quick order, cart, orders, and admin all load product image relations.
- No EAN/SKU/shade/size or first-class variant model is currently represented in the product schema, so colour-safe source matching cannot be automated safely yet.
