# Rafay POS — Local Browser Edition

A standalone, browser-only POS package for one shop per folder/browser profile.

## Run
1. Extract the ZIP.
2. Double-click `index.html`.
3. The POS opens in Chrome/Edge/Firefox.
4. Open **Admin Panel** from the POS header.

No PHP, MySQL, hosting, or internet connection is required.

## Separate shop copies
Make a separate folder/copy for every shop:

Shop A → its own folder  
Shop B → another folder  
Shop C → another folder

The application stores its data in the browser's LocalStorage under the key `rafay_pos_local_v1`.

## Important limitation
This is a local-browser edition. Data is tied to the browser/device, not the folder itself. Two copies opened in the same browser profile may share the same LocalStorage because they use the same origin/file context. For truly isolated shop data, use separate browser profiles or change the storage key before deploying each copy.

It is suitable for demos, small local/offline use, and a simple standalone edition. It is not a replacement for a server/database POS when multiple devices need to share the same live inventory.

## Features
- POS product search and category filter
- Cart quantity controls
- Discount and tax
- Cash/card/bank/other payment
- Amount received and change
- Sequential invoice numbers
- Printable receipt
- Product/SKU/category management
- Stock tracking
- Customer records
- Sales/invoice list
- Basic dashboard and revenue
- Cashier records
- Shop settings
- JSON backup/restore
- Responsive layout
- HTML escaping for displayed user data

## Default local user record
The initial local data includes:
Username: `admin`
Password: `admin`

This is only a local demo record and is not a secure authentication system. Do not use it as a production credential.

## Privacy
All data stays in the browser's LocalStorage. Clearing browser/site data can remove the POS data. Always keep backups.

## GitHub
You can upload the HTML files and README to GitHub. Do not store real private credentials in the repository.
