# Rafay POS — Standalone Shop Edition

A standalone PHP + MySQL point-of-sale system designed to be **sold and deployed separately for each shop**.

## How the product works

Every shop receives its own copy of this package and its own database. For example:

- Shop A → its own hosting/domain → its own database → its own Admin Panel
- Shop B → its own hosting/domain → its own database → its own Admin Panel

There is **no shared shop database and no tenant dependency** between installations. A shop owner can manage their own products, stock, customers, invoices, users and settings without seeing another shop's data.

You can keep the master ZIP/repository as your product and make a fresh deployment for every customer.

## Included

- Shop Admin and Cashier roles
- Password hashing with PHP `password_hash()` / `password_verify()`
- CSRF protection, secure sessions and same-origin checks
- Login throttling backed by the database
- Product/SKU/category management
- Stock tracking and inventory movement history
- Cart quantity controls and validation
- Discount and tax support
- Cash/card/bank/other payments
- Amount received and change calculation
- Sequential invoice numbers
- Receipt view, 58mm/80mm print layouts and TXT export
- Invoice history and search
- Invoice cancellation/refund with stock restoration
- Customer records
- User management
- Dashboard and date-range reports
- Payment breakdown and gross-profit reporting
- CSV-safe report export
- Audit log
- On-demand SQL backup
- Responsive desktop/mobile UI
- XSS-safe HTML rendering
- MySQL transactions and row locking for stock/invoice consistency
- Product archive/restore
- One-time installation lock

## Requirements

- PHP 8.1 or newer
- MySQL 8+ or MariaDB 10.5+
- PHP PDO MySQL extension
- Apache with `.htaccess` support, or equivalent web-server rules
- HTTPS strongly recommended for every real deployment

## Installation for a shop

1. Create a fresh hosting account/domain for the shop.
2. Copy `config.local.php.example` to `config.local.php`.
3. Enter that shop's MySQL host, database name, username and password.
4. Upload the complete package.
5. Open `setup.php` once.
6. Enter the shop name, owner/admin details and shop contact information.
7. The installer creates the database/tables and the first **Shop Admin** account.
8. Open `index.php` and sign in.
9. Delete `setup.php` from the server after installation.

If the hosting MySQL account is not allowed to create databases, create the database manually first and keep the same database name in `config.local.php`; the installer can then create the tables if the account has table-creation permission.

## Selling to many shops

Do **not** give every customer the same database credentials.

For each customer, create a fresh deployment:

`Customer → separate hosting/domain → separate package copy → separate MySQL database`

This makes the installations independent. Updating your master product later can be done by testing the update on a staging copy first, then applying it to customer deployments.

## Security before selling

- Use HTTPS.
- Use a separate MySQL user/database for every shop.
- Never publish `config.local.php` to GitHub.
- Delete `setup.php` after installation.
- Keep PHP, MySQL/MariaDB and the hosting server patched.
- Keep regular off-server backups and periodically test restoring them.
- Use strong, unique admin passwords.
- For higher-risk deployments, add server-level firewall/WAF/rate limiting and monitoring.

## Backup

A Shop Admin can download an SQL backup from the Admin Panel. This is an extra convenience, not a replacement for scheduled hosting/database backups.

Treat backup files as sensitive because they contain the shop's operational data and password hashes.

## Important limitation

The application code has been checked for PHP syntax and package integrity, but no code-only package can guarantee production security, performance or compatibility with every hosting provider. Before selling widely, deploy a staging copy and test login, checkout, stock concurrency, printing, restore-from-backup and the exact hosting environment you plan to support.
