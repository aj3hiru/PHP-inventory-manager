# PHP Inventory Manager

A plain PHP + MySQL website with a full e-commerce and shop-management system: products and stock, billing / POS,
orders, customers, credit (udhaar) and dues, GST invoices and an online store. It also contains the blog / news CMS it
was built on (posts, categories, authors, push notifications).

No framework: PHP 8.1+, MySQL 8 and Apache or LiteSpeed (`.htaccess`). Composer is used only for PHPMailer and Web Push.

## Features

**Inventory and products** (`admin/ecommerce/`)
- Products with SKU, HSN code, barcode, price and sale price, stock quantity, GST rate, main image and gallery, variants and specifications
- Categories, sub-categories, brands, badge tags and item types
- Stock-out list, barcode label printing, CSV import and export
- Product reviews

**Billing and money**
- Billing / POS screen with keyboard shortcuts (F2 complete sale, F3 print, F4 new sale), A4 and thermal (58 / 80 mm) printing
- Tax invoices with GSTIN, PAN, FSSAI and your logo; payment receipts
- Sales history and printable sales report
- Customer list and customer profile
- Credit ledger (udhaar), dues and due-payment recording
- Coupons and campaign offers
- GST / tax rates, payment methods (Cash on Delivery, Paytm, PhonePe, Razorpay, bank transfer), business settings

**Online shop** (`shop/`)
- Home page (built in the admin Homepage Customizer), category pages, product pages, search
- Cart, checkout, customer register / login, account, wishlist and order page

**CMS** (the original site): posts, categories, tags, authors, comments, media and file manager, users with detailed
permissions, activity log, cache manager, web push notifications, sitemaps and RSS.

## Requirements

- PHP 8.1 or newer with `pdo_mysql`, `mbstring`, `gd`, `curl`, `openssl`, `json`, `fileinfo`
- MySQL 8 (or MariaDB 10.5+)
- Apache or LiteSpeed with `mod_rewrite` (the URL rules are in `.htaccess`)
- [Composer](https://getcomposer.org/)

## Install

1. Upload the files to your web root (the folder your domain points to).
2. Install the PHP libraries:
   ```bash
   composer install --no-dev
   ```
3. Create an empty MySQL database and import the structure:
   ```bash
   mysql -u DB_USER -p DB_NAME < install/schema.sql
   ```
   This creates all tables (no data) plus three small lists: Indian states, GST rates and payment methods.
4. Copy the settings file and fill it in:
   ```bash
   cp includes/config.example.php includes/config.php
   ```
   Set `APP_URL`, `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`, `CONTACT_EMAIL` and change `PUSH_SECRET` to a long
   random string. `includes/config.php` is in `.gitignore`, so your passwords never go into git.
5. Create the first admin account (command line):
   ```bash
   php install/create-admin.php admin you@example.com 'a-strong-password'
   ```
6. Make these folders writable by the web server: `uploads/`, `cache/`, `cj_smart_cache/`, `files/`.
7. Open `https://your-domain/login`, sign in, then go to **Admin → E-commerce**. Fill in **Business Setting** first
   (name, address, GSTIN, logo, invoice format), then add categories and products.

### Web push (optional)

Create your own VAPID keys and put them in `includes/config.php`:
```bash
php -r 'require "vendor/autoload.php"; print_r(Minishlink\WebPush\VAPID::createVapidKeys());'
```
Also set the same public key in `push_notifications/push.js` and `assets/js/app.bundle.min.js`.

### Email (optional)

The contact form (`api/2c4a5d7f8h.php`) and comment mails (`mailers/CommentMailer.php`) send through SMTP. Put your SMTP
host, user and password there before turning them on.

## Try it locally

```bash
composer install
cp includes/config.example.php includes/config.php   # point it at a local database, APP_URL=http://localhost:8000
php install/create-admin.php admin admin@example.com 'test-pass-123'
php -S localhost:8000 router.php
```
`router.php` copies the main `.htaccess` URL rules for PHP's built-in server. It is for local testing only.

## Folder map

| Path | What it is |
|---|---|
| `admin/ecommerce/` | Inventory, billing / POS, orders, customers, dues, reports, settings |
| `shop/` | Online store pages and cart / checkout logic |
| `admin/` | CMS admin: dashboard, posts, users, files, cache |
| `includes/` | Settings (`config.example.php`), shared functions, page minifier, session cookie |
| `api/` | AJAX endpoints used by the site |
| `push_notifications/` | Web push sending and queue |
| `install/` | `schema.sql` (database structure) and `create-admin.php` |
| `uploads/` | Product, brand, category and business images (empty in git) |

## Security notes

- Never commit `includes/config.php`; it holds your database password and keys.
- Use your own `PUSH_SECRET` and VAPID keys; the ones in the example file are placeholders.
- Serve the site over HTTPS; the session cookie is marked secure automatically when the request is HTTPS.
