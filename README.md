<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>       

## About Laravel

## Local Dev (API + Web)

### What was broken
- CORS preflight failed for credentialed requests.
- `/api/v1/products/{id}/related` returned 500.
- API responses did not match the frontend expectations for catalog endpoints.
- Frontend was using product sizes and slugs that do not exist in the DB.
- Auth, orders, and checkout endpoints were missing or inconsistent.

### What I changed
- Added explicit CORS config with credentials support and local origins.
- Implemented API endpoints for related, featured, and search in the product controller.
- Standardized catalog responses to `{ data: ... }` and pagination meta for products.
- Removed size/slug usage from frontend and aligned filters to `brand_id`/`category_id`.
- Implemented auth register/profile endpoints with consistent envelopes.
- Implemented orders + checkout endpoints and normalized order DTOs.

### How to run (local)
Backend:
1) `composer install`
2) `php artisan key:generate`
3) `php artisan serve`

Frontend:
1) `npm install` (in tessa-shop-fresh)
2) `npm run dev`

Optional frontend env (defaults are already set in code):
- `VITE_API_URL=http://127.0.0.1:8000/api`

### Sample API checks
```
GET http://127.0.0.1:8000/api/v1/products?page=1&perPage=1
-> { "success": true, "data": [ { "id": 1, "name": "...", "price": "...", ... } ], "meta": { "current_page": 1, "per_page": 1, "total": 0, "last_page": 0 } }

GET http://127.0.0.1:8000/api/v1/categories
-> { "success": true, "data": [ { "id": 4, "name": "Activator" }, ... ] }

GET http://127.0.0.1:8000/api/v1/brands
-> { "success": true, "data": [ { "id": 1, "name": "Fanola" }, ... ] }

POST http://127.0.0.1:8000/api/v1/auth/register
Body: { "name": "Jane Doe", "email": "jane@example.com", "phone": "+15550001111", "password": "Secret123" }
-> { "success": true, "data": { "token": "...", "user": { "id": "1", "name": "Jane Doe", "email": "jane@example.com", "role": "user" } } }

POST http://127.0.0.1:8000/api/v1/auth/login
Body: { "email": "jane@example.com", "password": "Secret123" }
-> { "success": true, "data": { "token": "...", "user": { "id": "1", "name": "Jane Doe", "email": "jane@example.com", "role": "user" } } }

GET http://127.0.0.1:8000/api/v1/orders
Authorization: Bearer {token}
-> { "success": true, "data": [ { "id": 10, "status": "pending", "total": 120.00, ... } ], "meta": { "current_page": 1, "per_page": 15, "total": 1, "last_page": 1 } }

POST http://127.0.0.1:8000/api/v1/checkout
Authorization: Bearer {token}
Body: { "shipping_address": { "firstName": "Jane", "lastName": "Doe", "email": "jane@example.com", "phone": "+15550001111", "address": "123 Main", "city": "Austin", "state": "TX", "zip": "78701" }, "payment_method": "online", "items": [ { "product_id": 1, "qty": 2 } ] }
-> { "success": true, "data": { "id": 10, "status": "pending", "total": 120.00, ... } }
```

## Full‑Stack Review (Reviewer Notes)

### Formal checklist
Backend
- [ ] Single response envelope `{ success, data, meta? }` on every API endpoint.
- [ ] Pagination meta only `{ current_page, per_page, total, last_page }`.
- [ ] CORS allows localhost with credentials.
- [ ] Auth endpoints consistent and guarded by Sanctum.
- [ ] Public catalog routes not behind auth.
- [ ] Service layer owns query logic (controllers are thin).
- [ ] Resources map fields once (no duplication in controllers).
- [ ] Error responses use a single schema.

Frontend
- [ ] One API layer (RTK Query or Axios), not both.
- [ ] API client always unwraps the envelope consistently.
- [ ] Types reflect backend fields exactly.
- [ ] Filters use ids (`brand_id`/`category_id`), not slugs.
- [ ] Cart/checkout uses product‑level pricing and quantity only.
- [ ] Product routes use id everywhere.
- [ ] No stale references to removed fields (sizes/slugs).

### Module walkthrough

Backend (Laravel)
- Routing: [routes/api.php](routes/api.php) defines `/api/v1` endpoints.
- Response envelope: [app/Support/ApiResponse.php](app/Support/ApiResponse.php) is the single JSON wrapper.
- Catalog: [app/Http/Controllers/Api/V1/ProductController.php](app/Http/Controllers/Api/V1/ProductController.php), [app/Http/Controllers/Api/V1/CategoryController.php](app/Http/Controllers/Api/V1/CategoryController.php), [app/Http/Controllers/Api/V1/BrandController.php](app/Http/Controllers/Api/V1/BrandController.php).
- Services: [app/Services/ProductService.php](app/Services/ProductService.php) handles filtering/pagination/related queries.
- Resources: [app/Http/Resources/ProductResource.php](app/Http/Resources/ProductResource.php) maps model fields to API shape.
- Auth: [app/Http/Controllers/Api/V1/AuthController.php](app/Http/Controllers/Api/V1/AuthController.php) uses Sanctum and the standard envelope.
- Orders/Checkout: [app/Http/Controllers/Api/V1/OrderController.php](app/Http/Controllers/Api/V1/OrderController.php), [app/Http/Controllers/Api/V1/CheckoutController.php](app/Http/Controllers/Api/V1/CheckoutController.php).

Frontend (Vite/React)
- App routing: [tessa-shop-fresh/src/App.tsx](../tessa-shop-fresh/src/App.tsx) defines product routes using ids.
- RTK Query base: [tessa-shop-fresh/src/store/api/baseApi.ts](../tessa-shop-fresh/src/store/api/baseApi.ts).
- Products API: [tessa-shop-fresh/src/store/api/productsApi.ts](../tessa-shop-fresh/src/store/api/productsApi.ts) unwraps `{ success, data, meta }`.
- Types: [tessa-shop-fresh/src/types/index.ts](../tessa-shop-fresh/src/types/index.ts) uses product‑level fields only.
- Cart/Checkout: [tessa-shop-fresh/src/store/slices/cartSlice.ts](../tessa-shop-fresh/src/store/slices/cartSlice.ts), [tessa-shop-fresh/src/pages/CheckoutPage.tsx](../tessa-shop-fresh/src/pages/CheckoutPage.tsx).

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework. You can also check out [Laravel Learn](https://laravel.com/learn), where you will be guided through building a modern Laravel application.

If you don't feel like reading, [Laracasts](https://laracasts.com) can help. Laracasts contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

## Laravel Sponsors

We would like to extend our thanks to the following sponsors for funding Laravel development. If you are interested in becoming a sponsor, please visit the [Laravel Partners program](https://partners.laravel.com).

### Premium Partners

- **[Vehikl](https://vehikl.com)**
- **[Tighten Co.](https://tighten.co)**
- **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**
- **[64 Robots](https://64robots.com)**
- **[Curotec](https://www.curotec.com/services/technologies/laravel)**
- **[DevSquad](https://devsquad.com/hire-laravel-developers)**
- **[Redberry](https://redberry.international/laravel-development)**
- **[Active Logic](https://activelogic.com)**

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
