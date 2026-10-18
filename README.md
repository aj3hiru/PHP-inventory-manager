# PHP Inventory Manager

Shop-counter software for a retail store, in plain PHP + MySQL: products and stock, billing / POS with invoices,
customers, udhaar (credit) and due collection, coupons, GST, sales reports and staff logins.

It runs on any normal PHP hosting (Apache or LiteSpeed). No framework, no Composer, no build step.

## Features

**Billing / POS**
- Scan a barcode or type a product name, adjust quantity or price, apply a coupon
- Split payment across Cash, UPI and Card; anything unpaid becomes a due with a promised date
- Keyboard shortcuts: F2 complete sale, F3 print, F4 new sale
- Tax invoice in A4 or thermal (58 / 80 mm), with your logo, GSTIN, PAN and FSSAI
- Sales history with filters and a printable sales report

**Products and stock**
- SKU, HSN code, barcode, price and sale price, stock quantity, GST rate, image and gallery, variants and specifications
- Categories, sub-categories, brands, badge tags and item types
- Out-of-stock list with quick restock, barcode label printing, CSV import and export
- Campaign offers (temporary discounts)

**Customers and money**
- Customer list and profile with every bill and payment
- Udhaar ledger and due page: record a payment, print a receipt
- Dashboard: bills, sales, items sold, cash / UPI / card received, new dues, dues collected, out-of-stock count

**Staff**
- Owner, Manager and Billing staff roles, or tick permissions one by one
- Billing staff only see billing, customers and dues
- Activity log of logins, sales and changes

## Requirements

- PHP 8.1 or newer with `pdo_mysql`, `mbstring`, `gd`, `json`, `fileinfo`
- MySQL 8 (or MariaDB 10.5+)
- Apache or LiteSpeed with `mod_rewrite` (rules are in `.htaccess`)
- An internet connection in the browser: Bootstrap, jQuery, DataTables and icons load from public CDNs

## Install

1. Upload the files to the folder your domain or sub-domain points to (for example `store.yourdomain.com`).
2. Create an empty MySQL database and import the tables:
   ```bash
   mysql -u DB_USER -p DB_NAME < install/schema.sql
   ```
3. Copy the settings file and fill it in:
   ```bash
   cp includes/config.example.php includes/config.php
   ```
   Set `APP_NAME` (your shop name), `APP_URL`, `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER` and `DB_PASS`.
   `includes/config.php` is in `.gitignore`, so your password never goes into git.
4. Create the owner account (command line):
   ```bash
   php install/create-admin.php owner you@example.com 'a-strong-password'
   ```
   No SSH? Run the same command once from your hosting panel's terminal, or ask your host.
5. Make `uploads/` writable by the web server.
6. Open `https://your-domain/login`, sign in, and fill in **Business Setting** first (shop name, address, phone,
   GSTIN, logo, invoice format). Then add categories and products, and start billing.

## Try it on your computer

```bash
cp includes/config.example.php includes/config.php   # point it at a local database
mysql -u root -p store_test < install/schema.sql
php install/create-admin.php owner owner@example.com 'test-pass-123'
php -S localhost:8000 router.php
```
Open http://localhost:8000/login. `router.php` is only for PHP's built-in server; on real hosting `.htaccess` does its job.

## Folder map

| Path | What it is |
|---|---|
| `admin/ecommerce/` | Billing / POS, products, stock, customers, dues, coupons, GST, reports, business settings |
| `admin/` | Login, dashboard, staff, my profile, activity log |
| `includes/` | Settings (`config.example.php`), helpers, staff roles and permissions |
| `install/` | `schema.sql` (database tables) and `create-admin.php` |
| `uploads/` | Product, brand, category and logo images (empty in git) |

## Security notes

- Never commit `includes/config.php`.
- `includes/`, `install/` and dot-files are blocked from the browser by `.htaccess`.
- Serve the panel over HTTPS; the login cookie is marked Secure automatically on HTTPS.
- Five wrong passwords lock the login form for 15 minutes.
