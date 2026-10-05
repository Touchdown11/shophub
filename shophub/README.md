# ShopHub

A complete, modern e-commerce platform in **black and amber** — built with **PHP + MySQL**, vanilla
**JavaScript** (no frameworks, no build step) and hand-written **CSS**.

It ships as **two separate applications**:

| Folder | Who it is for | Entry point |
|---|---|---|
| `shophub/` | Customers — browse, buy, manage their account | `http://localhost/shophub/` |
| `shophub-admin/` | Staff — catalogue, orders, reports, settings | `http://localhost/shophub-admin/` |

The storefront contains **no admin login and no link to the portal**; the staff app has its own
sign-in, its own session cookie and its own `noindex` header. Deploy them side by side (the default)
or on two hosts/subdomains — both are one config line away.

Everything is production-shaped: prepared statements everywhere, CSRF tokens on every state-changing
request, server-side cart recalculation, stock re-checked inside a transaction when an order is
placed, upload validation, portal-scoped sessions, a login throttle and email verification.

---

## 1. What's included

### Storefront (customer app)

| Page | What it does |
|---|---|
| `index.php` | Editorial homepage — hero, category tiles, featured rail, promo split, best sellers, new arrivals, testimonials |
| `shop.php` | Catalogue with category / brand / price-range / availability filters, 6 sort modes, pagination |
| `product.php` | Gallery + thumbnails, size/colour variant picker with live price & stock, specs table, tabbed description/specs/reviews/shipping, rating breakdown, review form, related products |
| `cart.php` | Quantity steppers (AJAX), remove lines, coupon codes, live totals, stock caps |
| `checkout.php` | Guest **and** member checkout — contact, delivery, payment method, saved-address autofill |
| `payment-start.php` · `payment-return.php` · `payment-retry.php` | Hand-off to Stripe / PayHere and back again |
| `payment-notify.php` | Server-to-server webhooks that mark an order paid — PayHere (`md5sig`) and Stripe (`Stripe-Signature`), both signature-checked |
| `order-success.php` | Order confirmation with reference, itemised table and payment instructions |
| `login.php` · `register.php` · `logout.php` | Customer accounts |
| `verify-email.php` · `forgot-password.php` · `reset-password.php` | Email verification and password reset (tokenised) |
| `account.php` | Dashboard, order history (expandable, with status), wishlist, addresses, profile + password |
| `wishlist.php` | Works for guests (session) and syncs to the database once they sign in |
| `contact.php` | Contact form saved to the database, FAQ accordions, shipping/returns and privacy copy |

### Staff portal (`shophub-admin/`)

* **Dashboard** — revenue, orders to fulfil, AOV, 14-day sales chart, status breakdown, recent orders, top sellers, low-stock alerts.
* **Products** — search/filter, bulk activate/hide/delete, create + edit with a **WYSIWYG description editor**, unlimited specification rows, main image + **multi-image gallery with drag-to-reorder**, and a **variant editor** (any number of option groups → every combination gets its own SKU, price, stock and active flag).
* **Categories** — CRUD with parent categories, images, sort order.
* **Orders** — status tabs, search, order detail with line items, customer info, status + payment updates (**milestone emails** for processing / shipped / delivered / cancelled / refunded), internal staff notes, printable invoice.
* **Reports** — sales by date range (revenue, orders, AOV, units, discount, tax), daily trend, top products, top categories, best customers, and **CSV export** for every one of those tables.
* **Customers** — spend and order counts, detail view, suspend/reactivate, promote to staff, delete.
* **Reviews** — approve / hide / delete moderation queue (optional approval gate).
* **Coupons** — percent or fixed discounts, minimum spend, usage caps, start/expiry windows.
* **Messages** — contact inbox with replies + newsletter subscriber list.
* **Import / Export** — **CSV import for products *including variants*** (preview → commit), CSV export for products, orders, order items, customers and subscribers, plus a downloadable template.
* **Settings** — store identity, currency + exchange rate, delivery fees, tax, per-page count, **payment methods** (COD, bank transfer, Stripe, PayHere), gateway keys, bank details, mail transport (log / PHP mail / SMTP) and feature switches (email verification, review approval).

### Nice details

* **Currency switcher (USD ⇄ LKR)** in the top bar — prices are stored in one base currency and converted for display; orders are always recorded in the base currency so your books never drift.
* **Email verification + password reset** — hashed single-use tokens (48 h / 2 h), automatic sign-in after verifying, non-enumerating "forgot password".
* **Real payment gateways** — Stripe hosted Checkout and PayHere, switched on per store in Settings; COD and bank transfer work with zero configuration.
* **Transactional email** for every milestone: welcome, verify, reset, order placed, payment received, processing, shipped, delivered, cancelled, refunded, contact notice.
* **Live AJAX search suggestions** with thumbnails, wishlist hearts, stock caps, "only 3 left" warnings, sold-out states.
* **Inline SVG** logo, icons and product artwork — no icon fonts, no external image host, works offline.
* **CSV in, CSV out** — seed a catalogue from a spreadsheet, export the whole order book for accounting.

---

## 2. Two applications, two sessions

ShopHub deliberately does **not** put an admin login on the customer site.

| | Customer app (`shophub/`) | Staff app (`shophub-admin/`) |
|---|---|---|
| Sign-in page | `login.php` | `login.php` |
| Session cookie | `shophub_store` | `shophub_admin` |
| Landing after login | `account.php` | `index.php` |
| Sign-out returns to | the storefront | the staff sign-in page |
| Search engines | normal | `X-Robots-Tag: noindex, nofollow` |

What is enforced (see `includes/auth.php` in the store):

1. **A staff account cannot sign in on the storefront** — the message points to the portal, and no session is created.
2. **A customer account cannot sign in to the portal** — "This account does not have staff access."
3. **The portal is recorded in the session** (`auth_context`) and the cookie is scoped to one app, so a storefront session can never open the portal — even if that user is later promoted. They must sign in again.
4. **Separate lockout counters**, stored per account **in the database** — so storefront brute-force attempts can never lock staff out (and vice versa), and clearing cookies does not reset a lockout (5 tries for staff, 6 for shoppers, 5-minute cool-off).
5. **Staff sessions idle out** after 120 minutes (`app.admin_session_minutes` in the store's `includes/config.php`; set `0` to disable). Storefront browsing is unaffected.

**URLs are automatic.** With the two folders side by side, each app finds the other by adding or
stripping `-admin`. Deploying to different hosts? Set them in `shophub/includes/config.php` (or
`config.local.php`):

```php
'urls' => [
    'store' => 'https://shophub.test',
    'admin' => 'https://staff.shophub.test',
],
```

Every link inside an email, every gateway return URL and every cross-app redirect uses those values.

---

## 3. Folder structure

```
htdocs/
├── shophub/                       ← CUSTOMER APP (the shop)
│   ├── index.php  shop.php  product.php  cart.php  checkout.php
│   ├── order-success.php  account.php  wishlist.php  contact.php
│   ├── login.php  register.php  logout.php
│   ├── verify-email.php  forgot-password.php  reset-password.php
│   ├── payment-start.php  payment-return.php  payment-notify.php  payment-retry.php
│   ├── install.php                ← installer + demo data (delete when live)
│   ├── router.php                 ← safety router for `php -S` (see §6)
│   ├── ajax/                      ← cart, wishlist, search, currency, newsletter
│   ├── includes/
│   │   ├── config.php             ← DB, currency, URLs, feature flags
│   │   ├── db.php                 ← PDO wrapper (MySQL + SQLite)
│   │   ├── functions.php          ← helpers: money, cart maths, CSV, uploads, settings
│   │   ├── auth.php               ← accounts, sessions, tokens, throttle
│   │   ├── variants.php           ← option groups, combinations, per-variant stock
│   │   ├── orders.php             ← order placement, totals, milestone emails
│   │   ├── payments.php           ← COD / bank / Stripe / PayHere
│   │   ├── mailer.php             ← templates + log/mail/SMTP drivers
│   │   └── header.php footer.php partials.php
│   ├── assets/css/style.css  assets/js/main.js
│   ├── assets/img/products|categories/   ← generated SVG artwork
│   ├── uploads/products/          ← uploaded images land here
│   ├── storage/                   ← SQLite demo DB + mail.log
│   └── tools/make_artwork.py      ← regenerates the demo artwork
│
└── shophub-admin/                 ← STAFF APP (the back office)
    ├── index.php products.php categories.php orders.php order-view.php
    ├── customers.php reviews.php coupons.php messages.php
    ├── reports.php import-export.php settings.php
    ├── login.php logout.php
    ├── router.php
    ├── includes/ bootstrap.php header.php footer.php csv.php
    └── assets/css/admin.css  assets/js/admin.js
```

The staff app shares the store's database, config, mailer and helper library (it reads
`../shophub/includes/…`), so there is exactly one place to change business rules.

---

## 4. Requirements

* **PHP 8.1+** (built and tested on 8.4; XAMPP 8.1/8.2/8.3 are fine)
* **MySQL / MariaDB** (bundled with XAMPP) — or SQLite for the instant demo
* Extensions: `pdo_mysql` (or `pdo_sqlite`), `mbstring`, `fileinfo`, `json`, `curl` (only for Stripe)
* No Composer, no npm, no build step.

---

## 5. Install on XAMPP (the real setup)

1. **Copy both folders** into your XAMPP web root:
   * Windows `C:\xampp\htdocs\{shophub, shophub-admin}`
   * macOS `/Applications/XAMPP/htdocs/{shophub, shophub-admin}`
   * Linux `/opt/lampp/htdocs/{shophub, shophub-admin}`

2. **Start Apache and MySQL** in the XAMPP Control Panel.

3. **Create an empty database** — `http://localhost/phpmyadmin` → *New* → name `shophub`,
   collation `utf8mb4_general_ci`.

4. **Run the installer** — `http://localhost/shophub/install.php` → tick the box → **Run installation**.
   It creates **20 tables** and loads the demo catalogue (27 products, 21 variants across 5 products,
   6 categories, 10 orders, 12 reviews, 4 coupons, staff + customer accounts). Re-running it is safe:
   it only adds what is missing, and stops you before touching a populated catalogue.

   Command line: `php install.php` · wipe and rebuild: `php install.php --fresh` ·
   apply pending schema changes only: `php install.php --migrate`.

5. **Open the shop** → `http://localhost/shophub/`
6. **Open the portal** → `http://localhost/shophub-admin/`
7. **Delete `install.php`** once everything works.

### Demo accounts

| Role | Where to sign in | Email | Password |
|---|---|---|---|
| Staff | `http://localhost/shophub-admin/login.php` | `admin@shophub.test` | `admin123` |
| Customer | `http://localhost/shophub/login.php` | `amara@example.com` | `password123` |
| Customer | `http://localhost/shophub/login.php` | `ruwan@example.com` | `password123` |
| Customer | `http://localhost/shophub/login.php` | `nadia@example.com` | `password123` |

Seeded variant products (size / colour combinations with their own stock):
`merino-crewneck-jumper`, `suede-chelsea-boots`, `insulated-steel-bottle-750ml`,
`yoga-alignment-mat`, `full-grain-leather-wallet`.

### Prefer one folder? (nested layout)

Some people like both apps inside a single folder under the web root:

```
htdocs/
└── shophub/
    ├── shophub/          ← customer app   → http://localhost/shophub/shophub/
    └── shophub-admin/    ← staff portal   → http://localhost/shophub/shophub-admin/
```

That works with **no configuration** — the installer lives at
`http://localhost/shophub/shophub/install.php`, and the portal finds the store one level up.
If you move things somewhere more unusual, point the portal at it explicitly from
`shophub-admin/includes/config.local.php`:

```php
<?php
return ['store_path' => 'C:/xampp/htdocs/shophub/shophub'];
```

### If your MySQL credentials differ

Create `shophub/includes/config.local.php` (denied by `.htaccess`, never served):

```php
<?php
return [
    'db' => [
        'host' => '127.0.0.1',
        'name' => 'shophub',
        'user' => 'root',
        'pass' => '',          // your MySQL password
    ],
];
```

The staff app picks the same file up automatically.

---

## 6. Zero-setup demo mode (SQLite)

One line switches both apps to a file database — handy for a demo or a machine without MySQL:

```bash
cd /path/to/htdocs            # the folder that contains shophub/ and shophub-admin/
php install.php --fresh        # (from inside shophub/) creates storage/shophub.sqlite
php -S localhost:8080 shophub/router.php
```

Then: shop `http://localhost:8080/shophub/` · portal `http://localhost:8080/shophub-admin/`.

`router.php` is only needed because PHP's built-in server ignores `.htaccess`; Apache users can
ignore it. It stops accidental downloads of `storage/`, `includes/`, `*.sqlite` and `*.log`.

Prefer an environment variable over editing `config.php`? `SHOPHUB_DB_DRIVER=sqlite php -S …`.
Both drivers run the same code path — no feature is missing in either mode.

---

## 7. Day-to-day use

**Add a product** — *Products → New product*. Price, optional sale price, stock, short description and
a rich description (bold, headings, lists, quotes, links — sanitised on save). Add specification rows
and they become the Specifications table on the product page. Upload a main image plus any number of
gallery images, then drag them into order — the first one leads the product page.

**Sell sizes or colours** — in the same screen, add an option group per choice
(`Size` → `S, M, L`; `Colour` → `Black, Camel`). Every combination appears as a row: give it its own
SKU, optional price override, stock and active flag. The product's stock shown in the catalogue is the
sum of its active combinations, and a combination with zero stock shows as sold out.

**Work an order** — *Orders →* open a reference. Move it Pending → Processing → Shipped → Delivered
(or cancel/refund), mark the payment, add an internal note, print the invoice. Each real status change
emails the customer once — no duplicates if you re-save the same status.

**Import a catalogue** — *Import / Export → Import products*. Start from the template, keep one row
per variant (repeat the product SKU, pipe-separate the option names/values), upload and review the
preview (created / updated / skipped are listed), then commit. Unknown categories are created for you.

**Report on a period** — *Reports* → preset (`today`, `7d`, `30d`, `mtd`, `90d`, `ytd`, `all`) or a
custom `from`/`to`, then export any table as CSV. Cancelled and refunded orders are excluded.

**Take payments** — *Settings → Payment methods*. Cash on delivery and bank transfer are on by
default; add your Stripe and/or PayHere keys to switch the gateways on. The PayHere notify URL to
paste into their dashboard is printed on the settings page.

**Change prices/currency** — *Settings*. `currency`, `currency_symbol`, `secondary_rate` (LKR per USD),
delivery fee, free-delivery threshold, tax and per-page count all live there — no code edits.

**Regenerate the demo artwork** — `python3 shophub/tools/make_artwork.py`.

---

## 8. Going live checklist

- [ ] Set `'debug' => false` in `shophub/includes/config.php`.
- [ ] Delete `install.php` from both apps and clear `shophub/storage/`.
- [ ] *Settings → Mail*: switch the driver from **log** to **SMTP** and set a real from-address.
- [ ] *Settings → Payment methods*: add live gateway keys, set PayHere to **live**, and paste the
      notify URL into the PayHere dashboard. Test one real (small) order end-to-end.
- [ ] Keep COD/bank transfer only if you actually accept them.
- [ ] Create a dedicated MySQL user with rights on `shophub` only — don't ship `root`.
- [ ] Change the staff password, and delete any demo accounts you don't want.
- [ ] Turn on **Require a verified email before checkout** if you want verified customers only.
- [ ] Serve over **HTTPS** and enable `session.cookie_secure` in PHP (the cookies already use
      `SameSite=Lax` and `HttpOnly`).
- [ ] `uploads/` must be writable but must never execute code — the bundled `uploads/.htaccess`
      blocks script execution; keep it in place.
- [ ] Update the footer contact details and the bank details in *Settings → Payment methods*.
- [ ] Restrict `shophub-admin/` by IP or VPN if it doesn't need to be public
      (a ready-made `<RequireAll>` block is commented into its `.htaccess`).

---

## 9. Troubleshooting

| Symptom | Fix |
|---|---|
| "Database connection failed" | MySQL isn't running, the `shophub` database doesn't exist, or your root password differs — see §5. |
| Blank white page | Set `'debug' => true` in `shophub/includes/config.php` to see the error. |
| Portal says "This account does not have staff access" | You signed in with a customer account. Use `admin@shophub.test` or promote the user (*Customers → Make staff*). |
| CSS/JS 404s behind a subfolder | Leave `urls.store` / `urls.admin` empty (auto-detected); set them only for custom paths or separate hosts. |
| Images uploaded but not showing | Make sure `shophub/uploads/products/` exists and is writable, and that `php.ini` has `file_uploads = On` with `upload_max_filesize` ≥ 4 MB. |
| Emails never arrive | In demo mode that's expected — read `shophub/storage/mail.log`. For real delivery set *Settings → Mail → SMTP*. |
| Verification link does nothing | Links are single-use and expire (48 h). Ask the customer to request a new one from the sign-in page. |
| Stripe says "Invalid API Key" | The keys in *Settings → Stripe keys* are still the demo values — paste your own (test keys start `sk_test_`). |
| PayHere order stays unpaid | Check the notify URL in their dashboard matches the one shown in *Settings*, and that the merchant secret is correct — the callback signature must match. |
| Staff session keeps expiring | Raise or disable the idle timeout: `'admin_session_minutes' => 0` in the store's `includes/config.php`. |

---

## 10. How it's built (notes for whoever maintains this)

* **Two portals, one codebase.** `SHOPHUB_PORTAL` is set by each app's bootstrap. `base_url()` and
  `asset()` stay inside the running app; `store_url()` / `admin_url()` (and `store_link()` /
  `admin_link()` for absolute URLs in emails and gateway callbacks) cross over. Session cookies are
  named per portal so the two apps can never share a login.
* **Money** is stored as `DECIMAL(12,2)` in the base currency; `money()` formats at display time and
  `product_price()` centralises sale-price logic.
* **Variants** live in `product_options` (an option group + JSON values) and `product_variants`
  (JSON combination, SKU, price override, stock, active flag). `variants.php` owns every read/write —
  `save_variants()` replaces the grid on save, `sync_product_stock()` keeps the parent total honest,
  and the cart stores `v{variant_id}` keys so a line always knows its exact combination.
* **The cart** lives in the session as keys (`p{id}` / `v{id}`); `cart_contents()` re-reads every row
  from the database on each page load, so prices and stock caps are always current.
* **Order placement** (`place_order()`) re-validates stock, writes the order + line items, decrements
  the variant, resyncs the parent, increments coupon usage and saves the address — inside one
  transaction. Gateway orders are placed unpaid; `mark_order_paid()` is idempotent and sends the
  receipt exactly once.
* **Payments** are a small adapter layer in `payments.php`: Stripe uses hosted Checkout (no card data
  ever touches your server), PayHere is a signed auto-submitting form plus an md5-signature webhook.
  `payment-notify.php` always answers 200 so a gateway stops retrying.
* **Rich text** is sanitised on save *and* on render (`sanitize_html()` / `rich_text()`), so a pasted
  `<script>` can never reach the page.
* **Escaping**: `e()` wraps `htmlspecialchars` with `ENT_QUOTES` on every echoed value; all SQL uses
  bound parameters.
* **CSRF**: `csrf_field()` in every form, `require_csrf()` in every POST handler, plus an
  `X-CSRF-Token` header for AJAX. Gateway webhooks verify their own signatures instead.
* **Extending**: add a table to `schema()` in `install.php` plus a migration entry, add helpers to
  `shophub/includes/`, then follow the portal page pattern (`require_admin()` → POST handling →
  query → `includes/header.php` … `includes/footer.php`).

---

## 11. Where to take it next

1. Shipping providers and live tracking numbers on the order page.
2. Product questions & answers, and verified-purchase badges beyond "confirmed order".
3. Multi-currency checkout (charge in LKR *and* USD, not just display conversion).
4. A REST API for a mobile app, reusing `functions.php` + `orders.php`.
5. Abandoned-cart emails and a wishlist price-drop alert.
6. Two-factor authentication for staff accounts.

---

Built as a complete, self-contained starter — no frameworks, no dependencies, nothing to `npm install`.
Read it, rename it, ship it.
