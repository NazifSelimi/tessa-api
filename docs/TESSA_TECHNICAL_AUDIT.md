# Tessa Technical Audit

**Audit date:** 12 September 2026  
**Scope:** `/Users/xix/Projects/tessa-api` and the sibling `/Users/xix/Projects/tessa-ui` repository  
**Purpose:** document the current implementation before any mobile-first professional-ordering redesign

## Audit scope and status

This is a code and checked-in data-structure audit, not a redesign. It covers the current Laravel API, React client, migrations, checked-in schema exports/database backup, tests, deployment configuration, and repository documentation.

Both working trees were being changed concurrently during the audit. In particular, they contain uncommitted work for locale-prefixed URLs, legal pages, runtime SEO metadata, static SEO generation, sitemaps, a 404 page, and legacy product redirects. Those changes are described as **working-tree/in progress** below and should not be treated as a released baseline until committed, reviewed, and deployed. No source code was changed as part of this audit.

## Executive conclusion

Tessa is currently a client-rendered retail-style catalogue with role-aware pricing and a stylist quick-order page, backed by a Laravel JSON API. The server has useful foundations for a professional ordering product: stylist accounts, professional-only visibility, server-authoritative pricing, transactional stock locking, order history, reorder, and bundles. However, its catalogue model is still **one sellable row per `products` record**.

### Product-family/variant verdict

**A product family cannot currently contain multiple selectable variants or shades.** There is no product-family entity, product-variant entity, parent product key, shade code, swatch, EAN/barcode, SKU, pack-size option, or variant sort order.

The checked-in 24 August database backup contains 440 product rows. Hair colours are stored as independent products, for example:

- Product `1`: `1.0 - Black 100ml`, brand `Fanola`.
- Product `98`: the same display name, brand `Oro Therapy`.
- Product `106`: the same display name, brand `Rr Line`.

`ProductCollection`, `bundle_products`, hair-profile pivots, and `Image.variant` do not solve this:

- a product collection is a merchandising/outcome grouping;
- a bundle groups products for a promotion;
- hair-profile pivots classify recommendations;
- `Image.variant` means an image rendition such as `card` or `detail`, not a sellable product choice.

The quick-order page therefore presents a paginated list of independent product rows, with a quantity control and Add button per row. It cannot render one colour line with a dense multi-shade quantity matrix or submit many shade quantities as one operation. This is the primary architectural blocker for professional hair-colour ordering.

## 1. Architecture summary

| Area | Current implementation | Consequence |
|---|---|---|
| Frontend | React `19.2.4`, React DOM `19.2.4`, TypeScript `5.9.3`, Vite `5.4.21`, React Router DOM `6.30.3` | Modern client application, but browser-only rendering |
| UI/state | Ant Design `6.3.2`, Redux Toolkit `2.11.2`, RTK Query, Redux Persist `6.0.0`, i18next | Shared API cache and role-aware UI; cart and bearer token live in local storage |
| Rendering | Checked-in baseline is a `createRoot` SPA with lazy pages and no hydration. Uncommitted work adds a post-Vite static HTML generator for selected public routes. | Runtime remains client-rendered; the in-progress build can emit crawlable snapshots but is not SSR and does not hydrate them |
| Backend | PHP `^8.2`; installed Laravel `12.48.1`, Sanctum `4.2.4`, Resend Laravel `1.3.1` | Conventional JSON API with bearer-token authentication and queued email |
| API shape | Controllers -> Form Requests/validation -> services/actions -> Eloquent -> API resources -> `ApiResponse` envelope | Generally understandable separation, with some controllers still containing domain validation/business logic |
| Database | Eloquent domain is MySQL-shaped; checked-in dump is MySQL; Laravel configuration can target other drivers | Checked-in migrations do not reconstruct the complete domain schema from an empty database |
| Deployment | Nginx serves the Vite `dist`, proxies `/api` into Laravel, exposes `/storage`, and falls back to `index.html` | Same-origin production topology; unknown browser URLs return the SPA document with HTTP 200 |
| Localization | UI locales `mk`, `shq`, `en`; URL locale segments `mk`, `sq`, `en` are present in uncommitted work; API uses `SetLocale` | Product descriptions can be localized, but product names and much UI copy remain unlocalized/hard-coded |

The API applies an overall `60/minute` rate limit by user ID or IP. Password reset and stylist-invitation endpoints add tighter limits. Public catalogue controllers manually resolve an optional Sanctum bearer token through `ApiUserResolver` so they can include professional-only products for an authenticated stylist without placing the route under `auth:sanctum`.

## 2. Routing architecture and current route map

### Browser routing

Routes are declared centrally in `tessa-ui/src/App.tsx`. All route components and both main layouts are lazy loaded. Storefront routes use `MainLayout`; authentication, design-lab, stylist-workspace, and activation pages are standalone; admin pages use `AdminLayout` inside a role guard.

The current UI working tree chooses a `BrowserRouter` basename from the URL's first segment. Consequently the logical routes below can also be reached beneath `/mk`, `/sq`, or `/en`, for example `/mk/shop`. Unprefixed routes remain valid, creating duplicate URL families until canonical handling is complete.

#### Public browser routes

| Logical route | Component/behavior | Notes |
|---|---|---|
| `/` | `HomePage` in `MainLayout` | Marketing/storefront landing |
| `/shop` | `ShopPage` | Paginated generic catalogue and filters |
| `/collections/:slug` | `CollectionPage` | Outcome/merchandising collection, not a product family |
| `/for-professionals` | `ForProfessionalsPage` | Professional account acquisition |
| `/privacy` | `LegalPage` | In-progress localized legal/SEO work |
| `/terms` | `LegalPage` | In-progress localized legal/SEO work |
| `/returns` | `LegalPage` | Added in the current uncommitted UI worktree |
| `/delivery` | `LegalPage` | Added in the current uncommitted UI worktree |
| `/contact` | `LegalPage` | Added in the current uncommitted UI worktree |
| `/product/:id` | `ProductPage` | Numeric-ID detail URL; no product slug |
| `/product/show-product/:id` | redirect to `/product/:id` | In-progress client redirect; Nginx also has an uncommitted real 301 for the unprefixed legacy URL |
| `/cart` | `CartPage` | Client-side cart |
| `/checkout` | `CheckoutPage` | Public guest checkout is allowed |
| `/hair-survey` | `HairSurveyPage` | Same content as `/quiz` |
| `/quiz` | `HairSurveyPage` | Comment calls this canonical, but there is no redirect and route-wide canonical metadata is incomplete |
| `/recommendations` | `RecommendationsPage` | Public result page |
| `/design-lab` | `DesignLabPage` | Publicly routable internal design exploration |
| `/login` | `LoginPage` | Standalone |
| `/register` | `RegisterPage` | Standalone |
| `/forgot-password` | `ForgotPasswordPage` | Standalone |
| `/reset-password` | `ResetPasswordPage` | Token/email are handled by the page/API |
| `/auth/social/callback` | `SocialAuthCallbackPage` | Receives OAuth result from the API |
| `/stylist/activate/:token` | `ActivateStylistInvitationPage` | Public token activation flow |
| `*` | `NotFoundPage` | In-progress proper 404 UI with runtime `noindex`; Nginx still returns HTTP 200 |

#### Protected browser routes

| Route | Required access | Notes |
|---|---|---|
| `/account` | any bearer token | Account profile |
| `/account/orders` | any bearer token | Customer order history and reorder |
| `/account/orders/:id` | any bearer token | Owned order detail |
| `/stylist/request` | any bearer token | Professional-account application |
| `/complete-profile` | any bearer token, incomplete profile allowed | Social/invited-user completion flow |
| `/stylist/workspace` | exact `stylist` role | Standalone professional landing |
| `/stylist/quick-order` | exact `stylist` role | Uses `MainLayout`; includes `?restock=colors` mode |
| `/distributor` | exact `distributor` role | Currently unreachable from a real backend user |
| `/distributor/products` | exact `distributor` role | Currently unreachable from a real backend user |
| `/distributor/codes` | exact `distributor` role | Calls stylist APIs despite requiring a distributor UI role |
| `/admin` | exact `admin` role | Admin dashboard |
| `/admin/products` | exact `admin` role | Product CRUD |
| `/admin/orders` | exact `admin` role | Order administration |
| `/admin/users` | exact `admin` role | User administration |
| `/admin/coupons` | exact `admin` role | Coupon administration |
| `/admin/distributors` | exact `admin` role | Distributor-code reporting, not distributor accounts |
| `/admin/stylist-requests` | exact `admin` role | Stylist applications |
| `/admin/stylist-invitations` | exact `admin` role | Phone-first invitation management |
| `/admin/bundles` | exact `admin` role | Promotion bundles |

`ProtectedRoute` preserves the requested URL in `?continue=...` when redirecting an unauthenticated user to login. A signed-in user with the wrong role is silently redirected to `/`.

### API route map

All business API routes are under `/api/v1` and the `throttle:api` middleware. The following list is generated from `routes/api.php` and confirmed with `php artisan route:list`.

#### Public API

| Domain | Methods and paths |
|---|---|
| Authentication | `POST /auth/login`; `POST /auth/register`; `GET /auth/oauth/{provider}/redirect`; `GET /auth/oauth/{provider}/callback`; `POST /auth/forgot-password`; `POST /auth/reset-password` |
| Stylist activation | `GET /stylist-invitations/{token}`; `POST /stylist-invitations/{token}/activate` |
| Catalogue | `GET /products`; `GET /products/featured`; `GET /products/search`; `GET /products/quick-order`; `GET /products/{product}`; `GET /products/{product}/related`; `GET /product-collections`; `GET /categories`; `GET /brands` |
| Guidance/promotions | `GET /hair-profile-options`; `POST /recommendations`; `GET /bundles`; `POST /coupons/validate` |
| Checkout | `POST /checkout` |

`GET /products/quick-order` is public at the API layer even though its UI page is stylist guarded. Anonymous callers are filtered to non-`stylist_only` rows, but the response still exposes `stylistPrice`.

#### Authenticated API (`auth:sanctum`)

| Domain | Methods and paths |
|---|---|
| Account | `GET /auth/me`; `POST /auth/logout`; `PUT /auth/profile`; `PUT /auth/password` |
| Orders | `GET /orders`; `GET /orders/{order}`; `POST /orders`; `POST /orders/{order}/cancel` |
| Stylist application | `POST /stylist-requests`; `GET /stylist-requests/status` |

`POST /orders` and public `POST /checkout` invoke the same order creation service; the former is an authenticated duplicate entry point.

#### Admin API (`auth:sanctum`, exact admin role)

- Dashboard: `GET /admin/dashboard`.
- Users: `GET /admin/users`, `GET /admin/users/{id}`, `PUT /admin/users/{id}`, `DELETE /admin/users/{id}`.
- Stylist invitations: `GET|POST /admin/stylist-invitations`, `POST /admin/stylist-invitations/{id}/reissue`, `POST /admin/stylist-invitations/{id}/revoke`.
- Products: `GET|POST /admin/products`, `PUT|DELETE /admin/products/{id}`, `PUT /admin/products/{id}/stock`, `POST /admin/products/bulk-update`.
- Bundles: `GET|POST /admin/bundles`, `PUT|DELETE /admin/bundles/{bundle}`.
- Coupons: `GET|POST /admin/coupons`, `GET|PUT|DELETE /admin/coupons/{id}`.
- Categories: `POST /admin/categories`, `PUT|DELETE /admin/categories/{id}`.
- Brands: `POST /admin/brands`, `PUT|DELETE /admin/brands/{id}`.
- Orders: `GET /admin/orders`, `PUT /admin/orders/{id}/status`.
- Stylist requests: `GET /admin/stylist-requests`, `GET /admin/stylist-requests/{id}`, `POST /admin/stylist-requests/{id}/approve`, `POST /admin/stylist-requests/{id}/reject`.
- Reports: `GET /admin/reports/sales`, `GET /admin/reports/products`.
- Distributor-code reporting: `GET /admin/distributor-codes`, `GET /admin/distributor-codes/stats`.

#### Stylist API (`auth:sanctum`, exact stylist role)

- `GET /stylist/dashboard`.
- `GET /stylist/codes`.
- `POST /stylist/codes/generate`.
- `GET /stylist/codes/{code}/stats`.
- `PUT /stylist/codes/{code}`.
- `GET /stylist/orders`.

#### Non-versioned framework/infrastructure routes

- `GET /` renders the default Laravel welcome page when the API is addressed directly.
- `GET /sitemap.xml` is being added in the current uncommitted API worktree and builds a database-backed locale sitemap.
- `GET /up` is the Laravel health route.
- `GET /storage/{path}` is the local storage route.
- `GET /sanctum/csrf-cookie` exists through Sanctum even though the UI deliberately uses bearer tokens.
- `POST /resend/webhook` is supplied by the Resend package.

## 3. API/backend architecture

The backend is a Laravel JSON API organized around:

- controllers under `app/Http/Controllers/Api/V1`;
- reusable service classes under `app/Services`;
- `CheckoutAction` as a thin order-creation action;
- Eloquent models under `app/Models`;
- Form Requests for many public operations, with inline validators in several admin controllers;
- API Resources for camelCase response mapping;
- `ApiResponse` for intended `{success, data, meta?, message?}` and `{success: false, message, errors}` envelopes;
- queued listeners/mailables for order and stylist-request email;
- Sanctum personal-access bearer tokens;
- exact integer role checks in `RoleMiddleware` (`user=0`, `admin=1`, `stylist=2`).

Strengths include a versioned API prefix, query eager-loading in most catalogue operations, explicit visibility checks for professional-only products, rate limiting, request validation, a database transaction for checkout, and `lockForUpdate()` stock checks.

Important architecture gaps:

- The service boundary is inconsistent. `AdminProductController` performs validation, pricing defaults, media processing, direct model updates, and then invokes `ProductService`, while unused `StoreProductRequest` and `UpdateProductRequest` classes remain.
- `app/Exceptions/Handler.php` defines normalized JSON exceptions but is not registered in the Laravel 12 bootstrap or providers. Only `AuthenticationException` is configured in `bootstrap/app.php`; framework validation, binding, authorization, and unexpected exceptions can therefore have a different shape from `ApiResponse`.
- `SetLocale` only accepts an exact `en`, `mk`, or `shq` string. A normal header such as `mk-MK,mk;q=0.9` is not parsed.
- Repository documentation repeatedly labels old endpoints as production-ready while describing fields, routes, upload behavior, and status endpoints that no longer match the code.

## 4. Relevant database and API models

| Model/table | Current purpose and key fields | Important notes |
|---|---|---|
| `products` / `Product` | `name`, `brand_id`, `category_id`, `quantity`, retail `price`, `stylist_price`, `stylist_only` | One row is one sellable item/shade. Dump also has `description`, `featured`, `tags`, `compare_at_price`, but these are not consistently fillable, exposed, or used. SKU and slug migrations explicitly remove those columns. |
| `brands`, `categories` | Catalogue taxonomy; category has `sort_priority` | Category/brand descriptions and media were added historically but are not consistently represented by model/resource behavior. |
| `product_translations` | Locale + translated description per product | Only descriptions are translated; names and shade labels are not. `Product::getDescriptionAttribute()` shadows the physical `products.description`. |
| `images` / `Image` | Polymorphic media with `url`, `alt`, `sort_order`, rendition `variant`, transparency/review metadata | Product API returns one `image` plus a `media` array. The product page still reads `product.images`, so it normally displays only the primary image and ignores `media`. |
| `sales` / `Sale` | `sale_price`, start/end for a product | Eloquent assumes one sale; the checked-in schema does not show a unique constraint on `product_id`. Admin product validation does not expose sale management. |
| `product_collections` + pivot | Outcome-led merchandising collections and mapping provenance | Confirmed memberships are public; uncertain assignments are admin-only. This is not product family/variant structure. |
| `hair_types`, `hair_concerns` + pivots | Recommendation classification | Useful for survey/recommendations, unrelated to sellable variants. |
| `bundles` + `bundle_products` | Fixed-price, percentage, or bonus-item offers with audience and schedule | Pivot quantities compose multiple independent products. |
| `users` / `User` | Contact/delivery profile, password/social IDs, preferred locale, integer role, soft delete | Also has redundant `is_stylist` and `request_submitted`. |
| `stylist_profiles` | Business details | Uses legacy misspelled `saloon_*` columns. |
| `request_stylist` | Stylist application and approval status | Also uses `saloon_*`; separate from invitations. |
| `stylist_invitations` | Admin-created phone-first activation record with hashed token, business details, expiry/revocation | Creates role `2` users and a stylist profile. |
| `orders` / `Order` | user/guest owner, totals, discount, shipping, tax, status, payment fields, coupon references | `user_id` is nullable for guest checkout. Status integers map `1` (`PAID`) to API string `confirmed`. |
| `items` / `Item` | `order_id`, `product_id`, quantity, frozen unit price | Does not snapshot product/family/shade/SKU/name. Product FK is `ON DELETE CASCADE`, although admin code prevents deletion when items exist. |
| `order_infos` | Snapshot of delivery name, email, phone, address, city, postal code, country | Correct place for guest delivery identity. |
| `coupons`, `coupon_user` | Percentage/fixed discount, remaining quantity, expiry, usage record | A migration adds richer limits/status dates, but the model/service/resource ignore most of them. |
| `carts` / `Cart` | Historical server-side product cart rows | No controller or route uses it; the live cart is entirely in Redux/local storage. |
| `distributor_codes`, `stylist_invitation_codes` | Stylist attribution/invitation-code functionality | These do not define a distributor user role. |
| `courses` | Legacy Academy-era name/description/category table in `schema.json` and the SQL backup | No active Eloquent model, migration, controller, service, API route, or UI course page. |

### Migration integrity

The migration set cannot build the whole application database from empty state. It creates framework tables and newer features, but there are no create-table migrations for core domain tables such as `products`, `brands`, `categories`, `orders`, `items`, `carts`, `coupons`, `sales`, `images`, `product_translations`, `stylist_profiles`, `request_stylist`, or the legacy `courses` table. Several early migrations call `Schema::table(...)` on those presumed-existing tables.

`schema.json` is older than later migrations, while `backups/tessa-db-2026-08-24.sql.gz` is a fuller but point-in-time production-style snapshot and still predates the 26 August collection/media migrations. Tests use `DatabaseTransactions` against a configured MySQL database rather than proving a clean `migrate:fresh` path. Reproducible schema ownership must be fixed before a large catalogue migration.

## 5. Product variant model, especially colours and shades

### Current behavior

There is no variant model. Product identity is simply `products.id`; stock and both prices live on that record. Hair-colour naming embeds shade code, label, and pack size in `products.name`, for example `9.11 - Very Light Blonde Intense Ash 100ml`.

This creates the following professional-ordering limitations:

- Colour line/family, shade code, shade name, and size cannot be queried independently.
- The same shade code across brands/lines cannot be disambiguated except through the joined brand and a free-text name.
- There is no deterministic shade order, tonal family grouping, swatch, or technical compatibility relationship.
- There is no SKU/EAN/barcode for fast scan/search, stock integration, fulfilment, or historical order snapshots.
- Shared family copy/media must be repeated or omitted per shade row.
- Quick order and admin product management operate on hundreds of flat rows.
- Cart identity only supports product ID and one quantity for that row.
- Orders cannot preserve a variant identity beyond a mutable product foreign key and historical unit price.

### Required data refactor before a shade-grid UX

Introduce an explicit split between shared merchandise and sellable inventory. Exact naming can be decided during implementation, but the responsibilities should be:

1. `product_families` (or `products` redefined as the family): brand, line/range, category, shared localized name/copy, professional-use flag, shared media, technical guidance, active status.
2. `product_variants`: family ID, SKU, EAN/barcode, option type, shade code, localized shade label, swatch/media, size/unit, retail price, professional price, stock, active status, and explicit display/swatch order.
3. Order items: variant ID plus immutable snapshots for SKU/EAN, family name, shade code/name, pack size, unit price, and tax/discount context.
4. Cart lines: variant ID as identity; optionally family context for rendering.
5. Family catalogue endpoint: one family payload containing ordered, role-filtered variants and their live stock/effective prices.
6. Bulk order mutation: accept a set of `{variant_id, qty}` values and validate/merge the whole set server-side.

Do not auto-group all existing colour names using a name parser alone. The dump shows repeated shade names across distinct brands, and line/range information is not normalized. Migration should combine deterministic parsing with an owner-reviewed mapping/import sheet and stable SKU/EAN data.

## 6. Authentication and customer model

### Current implementation

- Login/registration issues Sanctum personal-access tokens; configured token lifetime is 24 hours.
- The UI persists token and user JSON in local storage under Redux Persist.
- Every RTK Query request adds `Authorization: Bearer ...` and `Accept-Language`.
- A 401 with a stored token clears local authentication and navigates to login. Despite the function name/comment, no refresh endpoint or token refresh attempt exists.
- Regular registration requires first name, last name, email, phone, address, city, postcode, and password.
- Login accepts an `identifier`; email uses `users.email`, while phone uses `users.phone_login`.
- Google and Facebook OAuth are implemented server-side. The current UI exposes Google and labels Facebook as coming soon.
- Stylist status can be reached through an application/approval flow or a separate admin invitation/activation flow.

### Problems

- Regular registration writes `phone` but never `phone_login`, so a normal customer cannot log in by phone despite the UI/API login contract advertising email or phone. The invitation service is the code that populates `phone_login`.
- The OAuth callback places the new Sanctum bearer token and base64-encoded user payload in the frontend URL query string. This can leak credentials into browser history, screenshots, referrers, proxy/access logs, analytics, and support tooling.
- Local-storage bearer tokens are exposed to any successful XSS. A same-origin, secure, httpOnly cookie/BFF-style exchange would reduce exposure for the first-party app.
- The UI type system and routes include `distributor`; the backend defines only user/admin/stylist. `UserResource` maps every unknown integer role to `user`, so distributor pages are unreachable for actual API users.
- `role` and `is_stylist` duplicate authority; they can diverge. Role middleware is exact-role rather than capability/policy based.
- Invited stylists may omit email, but the frontend considers a profile incomplete without email and can immediately force them through profile completion.
- `email_verified_at` exists, but there is no normal email-verification workflow.
- Professional business data is split between stylist applications, invitations, and `stylist_profiles`, with both `business_*` and misspelled `saloon_*` vocabulary.
- `ProductResource`, quick-order responses, and bundle product responses expose `stylistPrice` to anonymous callers even when the UI hides it.

## 7. Pricing model

The current price stack is:

1. Active `Sale.sale_price` overrides every other price on the server.
2. Otherwise a stylist gets `products.stylist_price`.
3. Everyone else gets `products.price`.
4. Eligible bundle savings are calculated after line subtotals.
5. Coupon discount is calculated against the post-bundle subtotal.
6. Shipping is 150 MKD below a 3,000 MKD product subtotal and zero at/above it.
7. `tax` is stored but checkout leaves it at zero.

The order service correctly ignores client-supplied prices and resolves prices from locked product rows. However:

- `PriceDisplay` and `useCart` ignore `product.sale`; the browser freezes raw retail/professional price when the item is added. Checkout may therefore create an order whose server total differs from the price and total shown to the user.
- A sale overrides professional price rather than choosing the lower/role-specific effective price; that may or may not match the intended commercial rule, but it is currently implicit.
- Public APIs expose the professional price.
- Shipping thresholds are duplicated in Laravel, shared UI constants, and direct literals in `CheckoutPage` and legal content.
- Coupon schema supports `min_purchase`, `max_discount`, usage limits/count, active state, and start/end dates, but current business logic only uses `quantity` and `expiration_date`.
- `coupon_user` records use, but validation does not enforce one-use/per-user policy. Cancelling an order restores stock but not coupon quantity/usage.
- Sale administration is incomplete and the schema does not enforce the model's assumed one-sale-per-product relationship.

A single server-owned price quotation/checkout preview contract is needed before building a professional order sheet. It should return per-variant effective price, subtotal, promotions, shipping, and authoritative total for the exact cart, with a short validity window/version.

## 8. Cart, order models, and state management

### Current product/order flow

1. Storefront/quick-order pages query the Laravel catalogue using RTK Query.
2. `useCart.addItem()` chooses retail or stylist price from the current UI role, freezes it into a `CartItem`, and stores the entire product response snapshot.
3. Redux stores cart items by `productId`; repeated additions increment that row. Items persist to local storage. `bundleIds` do not persist.
4. Checkout derives coupon, bundle, shipping, and displayed total in the browser.
5. Checkout sends only product IDs, quantities, delivery details, coupon code, and bundle IDs to `POST /api/v1/checkout`.
6. `OrderService` starts a transaction, locks each product, validates stock, resolves the current server price, writes line items, decrements stock, validates/recalculates bundles and coupon, calculates shipping, writes the order and `order_infos`, then dispatches queued order email after commit.
7. Signed-in users can view/cancel owned pending orders and use `useReorder`, which refetches each historical product before adding it to the current cart.

### Strengths

- Checkout supports both guest and authenticated ordering.
- Stock is checked and decremented under row locks in a database transaction.
- Line unit prices are snapshotted.
- Shipping/contact details are snapshotted separately in `order_infos`.
- The server is authoritative for product prices, bundle eligibility, coupon, and stock.
- Reorder refreshes current stock/price rather than blindly copying historical line data.

### Risks and gaps

- The live cart is not server-backed; the `Cart` model/table is orphaned. There is no cross-device cart, recoverable draft, server reconciliation, or offline/background order queue.
- A persisted product/stock/price snapshot can remain indefinitely stale. Auth/role changes invalidate product queries but do not reprice or clear existing cart lines.
- `bundleIds` are excluded from persistence, so reloading keeps bundle items but silently loses the promotion selection.
- `BundleDealRail` maps a bundle's required quantity into `Product.quantity` and sets `inStock: true`; later cart quantity clamping therefore mistakes bundle composition for inventory.
- There is no create-order idempotency key. Mobile retries, double submissions across tabs, or uncertain network outcomes can create duplicate orders.
- `StoreOrderRequest` does not validate `payment_method`, even though `OrderService` reads it. The UI currently always submits cash on delivery.
- The UI clears the cart and shows a generic success message without displaying the returned order ID or authoritative server total. A guest has no account route to retrieve the order afterward.
- Order items do not snapshot product name, SKU, shade, family, or pack size. `OrderResource` reads current product/brand/category names and can incur N+1 queries because brand/category are not eagerly loaded by normal order queries.
- The database product FK on items cascades deletes; admin guards reduce but do not eliminate the historical-data risk.
- The guest checkout path can queue a broken admin email: `NewOrderAdminMail` passes nullable `$order->user` as `$customer`, while `new-order-admin.blade.php` dereferences `$customer->first_name`. The checked-in backup's failed-job records corroborate this failure mode.
- Order email templates format monetary values with `$` even though the application prices are MKD.

## 9. Search implementation

### Storefront search

- `ShopPage` debounces text by 300 ms and only sends a search after two characters.
- The API performs `WHERE products.name LIKE '%term%'`.
- Brand, category, collection, retail price range, stock, sort, and pagination are separate server filters.
- Search/filter URL state is partly updated on blur/change, but component state is only initialized from URL parameters and does not fully resynchronize after browser back/forward navigation.
- There is no global search, suggestion index, typo tolerance, relevance score, recent searches, barcode scan, or synonym/translation layer.

### Professional quick order

- `StylistQuickOrderPage` debounces by 300 ms and aborts the previous RTK Query request.
- Backend search still matches product name only. The UI placeholder says “name or brand,” but brand is neither searched nor returned.
- `?restock=colors` is implemented as a category-name test: `LOWER(categories.name) LIKE '%color%'`. This is brittle vocabulary logic and excludes connected technical categories such as activator, peroxide, or bleach.
- The client requests 25 rows, but `QuickOrderController` neither validates nor uses `perPage`; it always returns 20.
- There is no family/line grouping, ordered shade code field, multi-row selection buffer, saved list, favourite list, barcode/SKU lookup, or one-request bulk add.

Leading-wildcard `LIKE` cannot effectively use a normal B-tree name index at scale. For the professional workflow, search should query normalized family, brand, line, shade code/name, SKU/EAN, and aliases, with exact code/barcode matches ranked first.

The static HTML also performs an early fetch using `sort=featured`, while `ProductIndexRequest` only permits `name_asc`, `name_desc`, `price_asc`, `price_desc`, and `newest`. That preload request is invalid and is relative to `/api` even though application startup requires a configurable `VITE_API_URL`.

## 10. Responsive and mobile architecture

There is meaningful mobile work already:

- CSS generally starts with small-screen defaults and adds `min-width: 768px` rules.
- The store has a 64 px header, mobile drawer, two-column product grid, mobile filter drawer, and fixed five-tab bottom navigation at `<=767px`.
- The stylist tab changes from Quiz to Order and links directly to quick order.
- Quick order swaps the desktop table for cards, uses 44 px quantity buttons, autofocuses colour-restock search, and provides a fixed cart/checkout summary.
- Safe-area insets are used in some footer/navigation/auth styles, and 16 px mobile form inputs avoid iOS zoom.
- Ant Design breakpoints are used in major pages.

It is not yet an app-grade mobile shell:

- `MainLayout` has embedded breakpoint overrides at 1,024 px, while global styles use 576/600/768/900/960/992 and other thresholds. Layout behavior is fragmented across CSS and inline JavaScript styles.
- The quick-order summary is fixed at `bottom: 0; z-index: 100`, while the mobile bottom nav is also fixed at `bottom: 0; z-index: 1100`. On phones the navigation overlays the order summary's lower 56 px and its safe area.
- A 320 px quick-order card has a 72 px image, padding/gaps, a 156 px quantity control, and an Add button in one row; this is vulnerable to crowding/overflow and leaves little room for shade/family information.
- Many components use `100vh` rather than dynamic viewport units, which is unreliable around mobile browser chrome and keyboards.
- No PWA manifest, service worker, offline shell, background sync, or install behavior exists.
- A flat paginated card list will not scale to hundreds of shades; there is no virtualization or family-level disclosure.
- `MainLayout` fetches all categories and brands on every storefront-layout route, including cart and legal pages.
- The professional workspace is a separate shell, while professional quick order returns to the consumer `MainLayout`; navigation and fixed-action ownership are inconsistent.
- Large desktop tables remain in admin and quick order with horizontal scroll fallbacks.
- Touch target rules are not consistently 44 px across all icons/buttons.
- Much user-facing quick-order, home, collection, design-lab, and admin copy is hard-coded in English despite three configured locales.

## 11. SEO, metadata, canonical URLs, and sitemap

### Checked-in baseline

`index.html` supplies one global English title/description, basic Open Graph title/description/type, theme colour, and Store JSON-LD. It contains no route-specific canonical URL, product structured data, collection metadata, social image, or language alternates. Since rendering is client-only, crawlers and link unfurlers initially receive this same shell for every route.

### Current uncommitted working-tree work

The concurrent uncommitted work substantially extends this baseline:

- `src/shared/components/Seo.tsx` mutates title, description, robots, Open Graph, Twitter, canonical, `hreflang`, HTML language, and JSON-LD after React mounts.
- It is now used by home, shop, for-professionals, hair survey, product, collection, legal, and not-found pages. Cart, checkout, recommendations, account/auth, stylist, distributor, admin, and design-lab routes still have no explicit head owner and can inherit stale metadata from the previous SPA route.
- `scripts/generate-seo.mjs` runs after Vite and writes localized static HTML for a fixed set of public pages, configured collections, and every product returned by a build-time catalogue request. It also writes `dist/sitemap.xml`.
- A separate Laravel `SitemapController` and `/sitemap.xml` web route are being added. Nginx's exact sitemap location routes production traffic to Laravel, which means the concurrently generated static sitemap is shadowed in that deployment topology.
- Nginx adds real 301 redirects for both unprefixed and locale-prefixed legacy product URLs.

This is static snapshot generation rather than SSR. React still starts with `createRoot`, not `hydrateRoot`, so it replaces the generated body after JavaScript loads. More importantly, the build generator catches a failed catalogue fetch and succeeds with zero products; a green build can therefore omit every static product page. The site URL and contact details are also hard-coded in the script instead of sharing runtime/server configuration.

Additional issues:

- The checked-in baseline referenced a missing sitemap. The uncommitted work introduces both a static sitemap and a dynamic database sitemap; one source of truth should be selected, tested, and monitored before release.
- Nginx's SPA fallback gives unknown URLs HTTP 200. The in-progress `NotFoundPage` visually renders 404 and applies runtime `noindex`, but the response remains a soft 404.
- `/hair-survey` and `/quiz` still render the same application route. In-progress metadata points both at `/quiz`, but there is no HTTP redirect.
- Product URLs use mutable numeric identity only; there is no stable readable family/variant slug strategy.
- Shop search pages are runtime `noindex`, but filter combinations reuse the generic `/shop` canonical and are not represented as landing pages.
- Product and collection JSON-LD now exist in uncommitted code, but product ID is presented as `sku` even though the catalogue has no SKU. Both runtime and static generators use `product.sale.price` without checking sale dates, so expired/future sale data can be advertised as the current offer price.
- Collection configuration/copy is not a fully localized database contract, so locale-prefixed static pages can still contain English collection content.
- Static `html lang="en"` and global English head content remain the fallback for any route not generated by the in-progress script.

For a crawlable commerce catalogue, finish and test one deterministic prerender/SSR strategy, fail builds or monitoring when catalogue generation is incomplete, select one locale-aware sitemap owner, and return correct 301/404 status codes at the edge/server.

## 12. Obsolete Tessa Academy code/routes

No active `/academy` or `/courses` browser route or API route exists. No `Course` model/controller/service is present.

The following remnants do exist:

- `schema.json` describes a `courses` table.
- `backups/tessa-db-2026-08-24.sql.gz` contains an empty `courses` table with name, description, and category.
- There is no current migration that creates, migrates, or drops that table, so its ownership is ambiguous.
- The publicly routable `/design-lab` contains “Curated by Tessa Academy,” “Backed by Tessa Academy,” and “Tessa Academy support” in `VersionThree.tsx`, `VersionFour.tsx`, `DesignLabPage.tsx`, and `mockData.ts`.

The design lab is an internal concept surface and should not be public/indexable in production. Academy database remnants should be removed through an explicit migration only after confirming that no external reporting/integration still depends on them.

## 13. Error handling and 404 behavior

### Frontend

- A root `ErrorBoundary` catches render exceptions and offers retry/reload. It only logs details in development and has no external error reporting.
- RTK Query centralizes bearer injection, handles session-expiry 401s, and logs server errors only in development.
- Pages use a mixture of `extractErrorMessage`, Ant messages/notifications, `Alert`, `Empty`, and generic hard-coded errors.
- A failed product fetch and a true 404 both become the same `!product` not-found state; the page cannot distinguish offline, 500, forbidden, and missing.
- Wrong-role routes silently return home.
- The current working tree has a real catch-all `NotFoundPage`, but deployment still returns HTTP 200.

### Backend

- Explicit controller/service failures often use `ApiResponse` consistently.
- Framework-thrown validation/model binding/HTTP exceptions are not demonstrably routed through the unregistered custom `App\Exceptions\Handler`.
- Authentication has a separate bootstrap response without the full `errors` field.
- There is no correlation/request ID, structured public error code, or application observability integration.
- Order email dispatch failure is caught/logged after order commit, so the customer can receive success even when notifications fail. That is a reasonable transaction boundary, but failed-job monitoring/retry and guest-safe templates are essential.

## 14. Reusable design system and components

### Existing reusable foundation

- Global CSS variables for brand colours, role colours, typography, spacing, radii, shadows, transitions, z-index, and layout.
- Fonts: DM Sans and Fraunces.
- Ant Design provider and components.
- `MainLayout`, `AdminLayout`, `MobileBottomNav`, `MobileFilterDrawer`, and `AuthPageHeader`.
- `ProductCard`, `PriceDisplay`, `CartDrawer`, `BundleDealRail`, `ShippingProgress`, and `StatusBadge`.
- `Logo`, `LanguageSwitcher`, `LoadingScreen`, and `ErrorBoundary`.
- Storefront `CollectionDirectory` and home `HScrollRail`.
- Shared utilities for error extraction/notification, price formatting, shipping, site config, locale routing, and current in-progress SEO/legal content.
- Two isolated shadcn-style components (`calendar`, `input-otp`) and mixed Ant Design/Lucide icon use.

### Limitations

- `AntdProvider` only enables Ant CSS variables; it does not map the Tessa design tokens into an Ant Design theme.
- `App.css` is a multi-thousand-line global stylesheet containing several generations of rules, stale classes, many breakpoints, and compressed blocks.
- Page-level inline styles and embedded `<style>` blocks frequently bypass tokens and component variants.
- Many colours, strings, sizes, and z-index values are hard-coded.
- Product price display has no single “effective price” abstraction and does not consume sales.
- No reusable family/variant selector, shade swatch, quantity grid, bulk-selection state, order quote, sticky-action coordinator, empty/error contract, or professional app shell exists.

## 15. Technical debt that blocks an app-like professional UX

| Priority | Issue | Why it blocks the new product |
|---|---|---|
| P0 | No product family/variant/SKU/EAN/shade model | Cannot build fast multi-shade ordering, reliable search, stock integration, or useful historical lines |
| P0 | Core schema cannot be recreated from migrations | Makes a high-risk catalogue migration and repeatable CI/staging setup unsafe |
| P0 | Browser and server can show/charge different effective prices | Professional trust and order accuracy failure |
| P0 | OAuth bearer token is placed in a query string | Credential-leak risk |
| P0 | Guest admin-notification email dereferences a null user | Guest orders can be accepted without a usable admin notification |
| P0 | Professional prices are exposed in anonymous API payloads | Commercial confidentiality problem |
| P1 | Local-only stale cart, no idempotency, bundle persistence bug | Poor reliability on intermittent mobile connections and repeat submission risk |
| P1 | Flat name-only search and brittle “color” category detection | Does not support salon lookup by line, shade code, SKU, barcode, or technical system |
| P1 | UI `distributor` role has no backend role | Dead/unreachable routes and misleading authorization model |
| P1 | Quick-order fixed bar collides with mobile bottom nav | Primary professional action can be obscured on the target device |
| P1 | Order lines do not snapshot variant/product identity | Historical reorders, fulfilment, audit, and future catalogue migration are fragile |
| P1 | Uncommitted dual SEO/sitemap pipeline, build-time catalogue dependency, and soft 404s | Public catalogue output can be incomplete or contradictory despite a green build |
| P1 | Coupon/sale/schema behavior drift | Promotions are difficult to reason about and test |
| P2 | Global CSS/inline-style/breakpoint fragmentation | Slows consistent mobile iteration and creates overlay regressions |
| P2 | API/TypeScript/doc contract drift | Creates silent missing fields and false confidence in old handoff documents |
| P2 | Test environment depends on an existing MySQL database | CI cannot prove migrations and end-to-end domain behavior from a clean state |
| P2 | Public design lab and Academy strings/table remain | Leaks internal concepts and preserves unowned legacy schema |

Other concrete drift found during the audit:

- `/products/featured` returns latest products, not rows whose `featured` flag is true.
- The UI declares fields the API does not consistently return (`featured`, `compareAtPrice`, `tags`, `metaTitle`, `metaDescription`) and roles the API cannot return (`distributor`).
- API pagination omits `from`/`to` although TypeScript marks them required.
- `ProductResource` returns `media`; `ProductPage` expects `images`.
- `Product::orders()` declares a many-to-many relationship even though actual order lines use `Item`.
- `axios` is installed but the canonical client is RTK Query/fetch.
- Old handoff documents refer to endpoints/methods/fields that do not match current routes.
- API registration feature tests omit address/city/postcode required by the current request contract.

## Recommended refactors before UX redesign

These are architectural prerequisites and sequencing recommendations, not a visual redesign.

### 1. Establish a reproducible baseline

- Create authoritative baseline migrations or a reviewed schema dump plus forward migrations for every live domain table.
- Add a clean-database CI job that runs migrations, seed/minimal fixtures, API feature tests, UI typecheck/tests/build, and lint.
- Reconcile `schema.json`, the SQL backup, Eloquent models, resources, TypeScript, and handoff documentation; mark snapshots as snapshots rather than current contracts.
- Define OpenAPI/JSON Schema contracts and generate or validate frontend types.

### 2. Introduce family and sellable-variant identity

- Add family and variant tables with SKU/EAN, shade fields, pack size, stock, prices, visibility, status, and ordering.
- Decide whether existing `products` become families or variants. A staged compatibility layer is safer than changing every endpoint atomically.
- Build a reviewed import/mapping process for existing colour rows and ambiguous product lines.
- Move shared translations/media/guidance to family scope while allowing variant swatches/media overrides.
- Snapshot complete variant identity on new order items; preserve old product IDs during migration.

### 3. Create professional catalogue and bulk-order APIs

- Return families with ordered variants, role-appropriate effective prices, stock, and technical compatibility.
- Add search fields/ranking for brand, line, family, shade code/name, SKU/EAN/barcode, and aliases.
- Add a bulk cart/quote operation accepting many variant quantities, merging duplicates, validating stock, and returning line-level errors without losing the whole selection.
- Replace string-based colour detection with normalized product type/capability relationships.

### 4. Centralize commercial calculations

- Define one price engine/quote response used by product cards, cart, checkout, bundles, order creation, and reorder.
- Decide and test sale-versus-professional-price precedence.
- Hide professional pricing from anonymous resources.
- Move shipping rules into server configuration and expose them through an API/config response.
- Complete or remove unused coupon fields; enforce per-user use and reverse coupon usage when a qualifying order is cancelled.

### 5. Make cart and checkout resilient

- Store only stable line identity/quantity locally and reconcile against a server quote; optionally persist authenticated drafts server-side.
- Add idempotency keys to checkout and return the same order for a replay.
- Present the returned order number and authoritative total before clearing all recoverable state.
- Fix bundle stock/quantity semantics and persist applied offers or derive them from the quote.
- Add guest order lookup/confirmation strategy and guest-safe MKD email templates.

### 6. Consolidate professional identity and authorization

- Remove or implement the distributor role; use explicit capabilities/policies rather than matching UI strings to integers.
- Make `role` the single authority and migrate/remove `is_stylist`.
- Unify stylist application, invitation, and profile fields with correctly spelled business vocabulary.
- Populate a normalized phone login for all eligible users and document whether email is optional.
- Exchange OAuth results without putting bearer credentials in a URL; evaluate secure same-origin cookies for the first-party app.

### 7. Stabilize the mobile application shell

- Give the professional workspace, shade order sheet, cart summary, and bottom navigation one owner for safe-area and sticky offsets.
- Consolidate breakpoints/tokens and replace layout-critical inline styles with tested components.
- Use dynamic viewport units where appropriate and test 320/360/390/430 px widths, keyboard-open states, landscape, and slow/intermittent networks.
- Consider virtualization/windowing only after the API returns family-grouped variants; family grouping will reduce list size first.
- Decide explicitly whether offline read/draft behavior and installability are product requirements before adding PWA infrastructure.

### 8. Finish error, SEO, and legacy cleanup

- Register one Laravel exception-rendering policy with stable machine-readable codes and request IDs.
- Give product/search/order pages distinct offline, retryable server, forbidden, validation, and not-found states.
- Render public catalogue metadata through SSR/SSG/prerendering, generate a real sitemap, enforce locale/canonical redirects, and return real 404 status codes.
- Gate or remove `/design-lab` from production and migrate/drop `courses` after dependency confirmation.

## Files likely to require modification for the new UX

This is a scope forecast, not an instruction to edit every file.

### API: catalogue and schema

- `database/migrations/*` — baseline core schema; new family/variant/order snapshot/search indexes; legacy cleanup.
- `app/Models/Product.php` plus new family/variant models.
- `app/Models/Item.php`, `app/Models/Order.php`, `app/Models/Cart.php`.
- `app/Http/Resources/ProductResource.php`, `OrderResource.php`, `BundleResource.php`, plus new family/variant/quote resources.
- `app/Services/ProductService.php`, `OrderService.php`, `RecommendationService.php`, `ImageService.php`, and a new pricing/quote service.
- `app/Http/Controllers/Api/V1/ProductController.php`, `QuickOrderController.php`, `CheckoutController.php`, and `Admin/AdminProductController.php`.
- `app/Http/Requests/Api/V1/ProductIndexRequest.php`, `StoreOrderRequest.php`, and new bulk/variant requests.
- `app/Support/ProductCatalogGuidance.php` and collection matcher/classification commands once compatibility becomes normalized.
- `routes/api.php`.

### API: identity, pricing, errors, notifications

- `app/Models/User.php`, `StylistProfile.php`, `RequestStylist.php`, `Coupon.php`, `Sale.php`, `Bundle.php`.
- `app/Http/Controllers/Api/V1/AuthController.php`, stylist invitation/request controllers, coupon/bundle controllers.
- `app/Services/StylistInvitationService.php`, `CouponService.php`, and related admin services.
- `app/Http/Middleware/RoleMiddleware.php`, `SetLocale.php`, `bootstrap/app.php`, and either removal or real registration of `app/Exceptions/Handler.php`.
- `resources/views/emails/new-order-admin.blade.php`, `order-confirmation.blade.php`, and order mail/listener tests.
- `config/sanctum.php`, `config/tessa.php`, and authentication deployment settings.

### Frontend: domain contracts and state

- `tessa-ui/src/types/index.ts` — replace flat, drifting interfaces with validated family/variant/quote contracts.
- `src/api/baseApi.ts` and feature APIs under `src/features/products`, `quickorder`, `orders`, `cart`, `auth`, `coupons`, and `admin`.
- `src/features/cart/slice.ts`, `src/hooks/useCart.ts`, `src/hooks/useDiscounts.ts`, `src/hooks/useReorder.ts`.
- `src/store/index.ts` — persistence/versioning/migrations and possibly server-backed drafts.

### Frontend: professional ordering surfaces

- `src/pages/stylist/StylistWorkspacePage.tsx`.
- `src/pages/stylist/StylistQuickOrderPage.tsx` or its replacement family/variant order sheet.
- `src/pages/ProductPage.tsx`, `ShopPage.tsx`, `CollectionPage.tsx`, `CartPage.tsx`, `CheckoutPage.tsx`.
- `src/pages/account/OrdersPage.tsx`, `OrderDetailPage.tsx`.
- `src/pages/admin/AdminProductsPage.tsx`, `AdminOrdersPage.tsx`, and import tooling.
- New reusable family header, shade swatch/row, quantity matrix, bulk-selection tray, quote summary, and line-error components.

### Frontend: shell, design system, routing, SEO

- `src/App.tsx`, `src/components/MainLayout.tsx`, `MobileBottomNav.tsx`, `CartDrawer.tsx`, `MobileFilterDrawer.tsx`, `PriceDisplay.tsx`, `ProductCard.tsx`, `AntdProvider.tsx`.
- `src/App.css` and a future split into token/component/layout layers.
- `src/i18n/index.ts`, locale dictionaries, and locale routing utilities.
- `src/shared/components/Seo.tsx`, `scripts/generate-seo.mjs`, `index.html`, `public/robots.txt`, sitemap generation, and `nginx-tessa.conf`.
- `app/Http/Controllers/SitemapController.php`, `routes/web.php`, and the shared site-URL configuration if the dynamic sitemap is retained.
- `src/pages/design-lab/*` and obsolete distributor pages/routes after product decisions.

### Tests and contracts

- API feature tests for clean migrations, family/variant visibility, role pricing, bulk shade selection, quote parity, stock concurrency, idempotency, guest email, coupon cancellation, and search ranking.
- UI tests for family expansion, multi-shade quantities, keyboard/scan input, local persistence migrations, server repricing, fixed-action safe areas, checkout replay, route metadata, and real error states.
- End-to-end mobile tests at narrow viewports and with throttled/offline transitions.

## Verification performed

- `php artisan route:list --json`: succeeded and was used to confirm the API route map.
- API unit suite: **7 tests passed, 17 assertions**.
- API feature suite: 1 framework example passed; 64 database-backed tests could not run because the sandbox denied the configured MySQL connection (`SQLSTATE[HY000] [2002] Operation not permitted`). This is an environment limitation, not evidence that all 64 behaviors are broken.
- UI `npm run typecheck`: passed.
- UI `npm run build`: passed with Vite `5.4.21`.
- UI `npm test -- --run`: **5 files, 11 tests passed**; output includes Ant Design deprecation and JSDOM stylesheet warnings.
- UI `npm run lint`: failed with **5 errors and 191 warnings** at the audit snapshot. The lint scope also traverses a nested `.claude/worktrees` copy, duplicating many findings; actual current-tree errors still include unused imports/types.

## Recommended decision before implementation

Treat the professional ordering unit as a **product family with many sellable variants**, not as a retail product card per shade. Approve the family/variant/SKU/EAN schema and the migration/import ownership first. Building the new mobile shade-selection experience on the current flat `products` model would encode the wrong domain into the UI and require a second rewrite when stock, search, fulfilment, and reorder need true variant identity.
