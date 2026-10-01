# Keputusan arsitektur

- Laravel 13 / PHP 8.3+, React TypeScript, PostgreSQL dan Redis.
- Modular monolith dengan domain services dan transaksi database.
- Same-origin session cookie + CSRF; permission pada backend.
- Row locks pada ledger/PO untuk concurrency.
- Sealed bids menggunakan encryption key khusus terpisah APP_KEY; kunci/infra operator tetap masuk threat model.
- Railway Docker deploy, migrasi pre-deploy, worker/scheduler sebagai service terpisah.
