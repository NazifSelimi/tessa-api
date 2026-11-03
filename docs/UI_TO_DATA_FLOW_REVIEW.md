# UI-to-data review map

This repository is the Laravel API for the sibling React project named in the
README (`../tessa-shop-fresh`). The UI source is not in this checkout, so an
endpoint is marked **confirmed** only when the repository documentation or a
feature test establishes its purpose. Everything else is deliberately marked
**unverified**: registered routes are public contracts, but registration does
not prove a screen calls them.

Use this document in the listed order. It starts at a user-visible feature,
then follows the request through route, controller, validation, service/query,
resource, Eloquent model, and finally migrations. That is the shortest safe
path for reviewing behaviour without losing where data is shaped or filtered.

## Runtime request spine

```text
React screen / admin screen
  -> routes/api.php (method, URL, auth + role middleware)
  -> Controller (HTTP orchestration and response status)
  -> FormRequest (input contract, if present)
  -> Service or Action (transaction, policy-like business rule, query)
  -> Eloquent model + relations (persistence)
  -> Resource (camelCase response DTO)
  -> ApiResponse (success/data/meta envelope)
```

Read `routes/api.php` first for authorization boundaries, not controllers in
alphabetical order. `auth:sanctum` establishes identity; `role:admin` and
`role:stylist` constrain the back-office screens. Public product endpoints use
`ApiUserResolver` to optionally read the bearer token and hide
`stylist_only` items from anonymous and ordinary customers.

## 1. Storefront catalogue — confirmed

| UI capability | Endpoint(s) | Controller → data path | Response boundary |
| --- | --- | --- | --- |
| Product list and filters | `GET /v1/products` | `ProductController::index` → `ProductIndexRequest` → `ProductService::paginate` → `Product` relations | `ProductResource` collection + pagination metadata |
| Product detail | `GET /v1/products/{product}` | `ProductController::show` → `ProductService::canView` / `find` | `ProductResource` |
| Related-product rail | `GET /v1/products/{product}/related` | `ProductController::related` → `ProductService::related` | `ProductResource` collection |
| Featured/latest rail | `GET /v1/products/featured` | `ProductController::featured` → `ProductService::latest` | `ProductResource` collection |
| Search | `GET /v1/products/search` | `ProductController::search` → `ProductService::search` | `ProductResource` collection |
| Quick-order catalogue | `GET /v1/products/quick-order` | `QuickOrderController::__invoke` → direct `Product` query | controller-built DTO |
| Brand/category filter options | `GET /v1/brands`, `GET /v1/categories` | `BrandController` / `CategoryController` → respective list service | `BrandResource` / `CategoryResource` |
| Editorial collections | `GET /v1/product-collections` | `ProductCollectionController::index` → `ProductCollection` query | `ProductCollectionResource` |
| Bundle listing | `GET /v1/bundles` | `BundleController::index` → `Bundle` query | `BundleResource` |
| Hair-profile picker | `GET /v1/hair-profile-options` | `HairProfileOptionController::__invoke` → `HairType` / `HairConcern` | controller-built DTO |
| Recommendation quiz | `POST /v1/recommendations` | `RecommendationController::__invoke` → `RecommendationRequest` → `RecommendationService` | controller-built recommendation DTO |

For a product response, inspect `ProductResource::toArray` after the service.
It is the actual frontend field contract (`stylistPrice`, `hairTypeIds`,
`media`, `collections`, etc.), while `Product` is the database-oriented
representation. Image selection happens in `ProductResource::imageUrl`; image
creation and normalisation happen in `ImageService`, not in the resource.

## 2. Customer identity and checkout — confirmed

| UI capability | Endpoint(s) | Controller → data path | Important rule |
| --- | --- | --- | --- |
| Sign in/up, profile, password reset | `/v1/auth/*` | `AuthController` → request validators → `User` / Sanctum / mail | OAuth helper methods are private implementation details; review the public controller method first. |
| Social sign-in | `GET /v1/auth/oauth/{provider}/{redirect,callback}` | `AuthController` → provider HTTP exchange → `User` → Sanctum token | Allowed provider and redirect allow-list must be reviewed together. |
| Coupon validation | `POST /v1/coupons/validate` | `CouponController::validate` → `CouponService::validateCoupon` → `Coupon` | Price is only authoritative when checkout recalculates it. |
| Guest/customer checkout | `POST /v1/checkout` | `CheckoutController::checkout` → `StoreOrderRequest` → `CheckoutAction::execute` → `OrderService::createOrder` | This is the preferred checkout entry point. |
| Customer order history/detail/cancel | `/v1/orders*` | `OrderController` → `OrderService` → `Order` / `OrderInfo` / `Item` | Service scopes order access to the authenticated user. |

`OrderService::createOrder` is the critical data-retrieval and mutation path:
it reloads product data, resolves price and stock server-side, creates order
records, updates inventory, applies discounts, and triggers order events. Do
not infer order totals from browser state.

## 3. Stylist workspace — route exists; screen usage unverified locally

| Capability | Endpoint(s) | Data path |
| --- | --- | --- |
| Apply for stylist status / see status | `POST /v1/stylist-requests`, `GET /v1/stylist-requests/status` | `StylistRequestController` → `StylistRequestService` → `RequestStylist`, events/listeners/mail |
| Invite activation | `GET/POST /v1/stylist-invitations/{token}*` | `StylistInvitationController` → `StylistInvitationService` → `StylistInvitation` / `User` |
| Stylist dashboard | `GET /v1/stylist/dashboard` | `StylistDashboardController` → `StylistDashboardService` |
| Distributor codes | `/v1/stylist/codes*` | `StylistCodeController` → `StylistCodeService` → `DistributorCode` |
| Attributed orders | `GET /v1/stylist/orders` | `StylistOrderController` → `StylistOrderService` → `Order` |

These routes require both Sanctum authentication and the `stylist` role. The
customer product endpoints can return stylist-only inventory only for this
role (or admin), via `ProductService::applyVisibilityFilter`.

## 4. Admin workspace — route exists; screen usage unverified locally

| Admin feature | Endpoint family | Primary path |
| --- | --- | --- |
| Dashboard | `GET /v1/admin/dashboard` | `DashboardController` → `DashboardService` |
| Customers | `/v1/admin/users*` | `AdminUserController` → `AdminUserService` → `User` |
| Catalogue product management | `/v1/admin/products*` | `AdminProductController` → `ProductService` / `ImageService` → `Product` and related records |
| Brand/category management | `/v1/admin/brands*`, `/categories*` | admin controllers → `BrandService` / `CategoryService` |
| Bundles | `/v1/admin/bundles*` | `AdminBundleController` → `Bundle` + pivot sync |
| Coupon management | `/v1/admin/coupons*` | `AdminCouponController` → `CouponService` |
| Order operations | `/v1/admin/orders*` | `AdminOrderController` → `AdminOrderService` / `OrderService` |
| Stylist approvals and invitations | `/v1/admin/stylist-requests*`, `/stylist-invitations*` | admin controller → `StylistRequestService` / `StylistInvitationService` |
| Reporting | `/v1/admin/reports/{sales,products}` | `AdminReportController` → `ReportService` |
| Image candidate review | `/v1/admin/catalog-image-candidates*` | `CatalogImageCandidateController` → audited files + `CatalogImageCandidateInstaller` |
| Distributor-code reporting | `/v1/admin/distributor-codes*` | `AdminDistributorController` → `DistributorCode` query |

Every route in this section belongs behind `auth:sanctum` plus `role:admin`.
The preview route is unusually public in `routes/api.php`; it should receive a
specific access-control review before production release.

## 5. Data ownership map

| Data concern | Write owner | Read owner / API DTO |
| --- | --- | --- |
| Product, translations, sale, image assets, hair profile links | `ProductService` | `ProductService` → `ProductResource` |
| Product collection mapping | migrations/seeders and catalogue commands | `ProductCollectionController` / `ProductResource` |
| Prices, stock, order lines, discount | `OrderService` inside a DB transaction | `OrderResource` |
| User identity/role/social identity | `AuthController`, invitation and stylist-request services | `UserResource` |
| Email notifications | domain events → listeners → mail classes/views | not a UI DTO |
| External image discovery | console commands → discovery/installer services | admin candidate-review endpoints |

## 6. Code without a proven UI route — start here for extra review

These items have either no route reference or only an operational caller in
this checkout. They need stronger comments/tests than UI-backed code because a
future maintainer cannot discover their intent by following a screen.

| Item | Evidence | Review action |
| --- | --- | --- |
| `CartController` | Empty class and no route registration | Delete after confirming no external consumer, or implement and document its contract. |
| `ProductService::activeSales` | No in-repository caller | Add a route/job caller and test, or remove it. |
| `StoreContactRequest`, `StoreSaleRequest` | No in-repository caller | Treat as dead request contracts until a route is added. |
| `CatalogImageCandidateDiscovery` | Console-command caller only | Document network source, file lifecycle, failure policy, and ownership. |
| `ImageService` GD helpers | Indirectly reached through admin catalogue/image flow | Keep algorithm-level comments beside edge/mask/crop decisions; do not merely restate GD calls. |
| Mail views, listeners, events | Event-driven side effects rather than HTTP response flow | Review event dispatch sites and test delivery/queue configuration. |

## 7. Review sequence and cleanup roadmap

1. Lock the external UI contract: compare `tessa-shop-fresh` API calls with
   this route list, then change every “unverified” label above to confirmed,
   unused, or external-only. Do not remove a route based on this API checkout
   alone.
2. Stabilise public DTOs: write contract tests around each Resource and the
   common `ApiResponse` envelope. Avoid returning hand-built arrays where a
   Resource already exists.
3. Split oversized controllers, beginning with `AuthController` and
   `AdminProductController`, into focused actions/services. Keep controllers
   responsible for HTTP concerns only.
4. Make all request validation explicit. Several admin controllers validate
   inline while storefront paths use FormRequests; choose FormRequests for
   reusable, testable contracts.
5. Remove or wire proven dead code in section 6, one item per small change
   with a regression test.
6. Add database-level integrity constraints and transactional tests around
   checkout, stock, coupon limits, and product deletion.
7. Audit public/admin boundaries, especially catalogue-image preview and any
   endpoint accepting raw request data.

## Commenting standard for subsequent changes

Add comments only for a decision a reader cannot infer from names and types.
Every nontrivial public method should say: **feature/API consumer**, **input
invariant**, **side effect/query boundary**, and **why any special branch
exists**. Private methods need comments when they encode a business rule or
algorithm; they do not need narration of PHP syntax. Keep route comments about
authorization and UI ownership in `routes/api.php`, and keep data-shape
comments in Resources, not duplicated across controllers.
