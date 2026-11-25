# What CampaignsHub reads from Salla and Zid — and why

Owner, 2026-11-25: Salla and Zid ask which data the CampaignsHub app reads. This is the answer, written from the
connectors themselves (`backend/app/Domains/Commerce/Providers/SallaConnector.php`, `ZidConnector.php`) and from what
the store analytics page draws (STORE-ANALYTICS-DEPTH-001, STORE-INSIGHTS-001). **Everything is read-only.** CampaignsHub
never creates, edits or deletes anything in a merchant's store.

## The data, by permission group

| Permission group (as the partner dashboard names it) | Read or write | What CampaignsHub reads | Why it needs it (what the merchant sees) |
|---|---|---|---|
| **Store information** | Read | Store name, id, currency, timezone | To name the connected store and count every day on the merchant's own clock and currency |
| **Orders** | Read | Order id and reference, date and time, status, payment method, amounts (subtotal, shipping, tax, discount, total, refunded), coupon code, line items (product, quantity, price), the UTM / click-id / referrer the order carried | Sales, orders, average order, cancellations and refunds, order states, payment methods, when customers buy (day × hour), basket sizes, coupons, best sellers, and tying each order to the ad campaign that brought it (attribution) |
| **Products** | Read | Product id, name, SKU, status, price, stock quantity, category, product page URL, main image | Best and weakest sellers with their pictures, sales by category, active products with no sale in the period |
| **Customers** | Read | Customer id, city, country, first-seen date, order count | New vs returning customers, repeat purchase, customer value, sales by city. **Name, e-mail and phone are not shown anywhere** in the analytics and never leave the server in an analytics answer |
| **Abandoned carts** | Read | Cart id, time, total, item count, the UTM / click id it carried | Abandoned carts and their value in the period |
| **Webhooks / app events** | — | Install, uninstall and order events | To keep the figures current; on Salla, to stop syncing and purge the data 30 days after the merchant uninstalls |
| `offline_access` (Salla) | — | A refresh token | To sync on a schedule without asking the merchant to sign in again |

## What CampaignsHub does NOT need

- Any **write** permission: products, orders, customers, coupons, shipping, settings, themes, payments.
- Payment card data, bank details or wallet balances.
- Shipping-company accounts, invoices issued by the store, or employee accounts.

## Notes for the reviewer form

- **Purpose, in one line:** «تحليل أداء المتجر وربط الطلبات بالحملات الإعلانية المدفوعة التي جلبتها، للقراءة فقط.»
  / «Store performance analytics and tying each order to the paid ad campaign that brought it — read-only.»
- **Personal data:** customers' contact fields are read only because the customers endpoint returns them with the
  record; they are not displayed in the analytics, not exported in the analytics files, and not shared with ad platforms.
- **Retention:** data is kept while the app is installed. On Salla it is purged 30 days after uninstall (`SallaInstalls`); the same rule for Zid is not built yet.
- **Where it lives:** the merchant's own workspace in CampaignsHub, isolated per workspace and per project.
