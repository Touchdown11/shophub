# ShopHub — staff portal

This is the **back office** for the ShopHub storefront. It is a separate application on purpose:
the customer site carries no admin login, and staff sign in here with their own session cookie.

## Install

1. Put this folder **next to** the store — either layout works, nothing to configure:
   * siblings: `htdocs/shophub/` + `htdocs/shophub-admin/`
   * or nested: `htdocs/shophub/shophub/` + `htdocs/shophub/shophub-admin/`
2. Run the store's installer once — it creates the shared database **and** the staff account:
   `http://localhost/shophub/install.php` (siblings) or
   `http://localhost/shophub/shophub/install.php` (nested)
3. Sign in at `http://localhost/shophub-admin/` with `admin@shophub.test` / `admin123`,
   then change the password under *Customers*.

Both apps read the same database and the same `shophub/includes/config.php`. If your MySQL
credentials differ from the defaults, put them in `shophub/includes/config.local.php` — this app
picks that file up automatically. Deploying the two apps to different hosts? Set `urls.store` and
`urls.admin` there.

## What you can do here

| Screen | Highlights |
|---|---|
| Dashboard | Revenue, orders to fulfil, AOV, 14-day chart, top sellers, low-stock alerts |
| Products | WYSIWYG descriptions, specs, main image + drag-to-reorder gallery, full variant editor (option groups → per-combination SKU / price / stock / active) |
| Categories | Parent/child tree, images, sort order |
| Orders | Status + payment updates with milestone emails, internal notes, printable invoice |
| Reports | Date-range sales, daily trend, top products/categories/customers, CSV export |
| Customers | Spend, order counts, suspend / reactivate, promote to staff |
| Reviews | Approval queue (optionally hold every review before it appears) |
| Coupons | Percent or fixed, minimum spend, usage caps, date windows |
| Messages | Contact inbox + newsletter subscribers |
| Import / Export | CSV product import *with variants* (preview then commit), exports for products, orders, order items, customers, subscribers, plus a template |
| Settings | Store, currency, shipping, tax, payment methods (COD / bank transfer / Stripe / PayHere), gateway keys, bank details, mail transport, feature switches |

**Tip:** the settings page prints the exact PayHere notify URL *and* the Stripe webhook URL to paste
into those dashboards, and lets you send yourself a test email so you can verify your SMTP settings
before going live.

Staff sign-in is throttled **per account in the database**, so closing the browser does not reset the
lockout: five wrong passwords and that account waits five minutes.

## Files

```
shophub-admin/
├── index.php products.php categories.php orders.php order-view.php
├── customers.php reviews.php coupons.php messages.php
├── reports.php import-export.php settings.php
├── login.php logout.php
├── includes/  bootstrap.php header.php footer.php csv.php
└── assets/    css/admin.css  js/admin.js
```

`includes/bootstrap.php` locates the store folder automatically — sibling first, then the nested
`shophub/shophub` shape, then a couple of deeper arrangements. Override it when your folders are
somewhere unusual, in `shophub-admin/includes/config.local.php`:

```php
<?php
return ['store_path' => 'C:/xampp/htdocs/shophub/shophub'];
```

(or set the `SHOPHUB_STORE_ROOT` environment variable). It then loads the store's helper library, so
business rules live in exactly one place.

Full documentation, including deployment, payments and troubleshooting, is in
`shophub/README.md`.
