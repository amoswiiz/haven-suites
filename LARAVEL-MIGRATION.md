# Haven Suites: Laravel 13 migration

The migration lives in `laravel/` on branch `migration/laravel-13`. The project was not previously a Git repository; `main` contains the original CodeIgniter baseline. No remote was configured or pushed.

## Run locally on this machine

Run `powershell -ExecutionPolicy Bypass -File tools/start-laravel.ps1` from the project root, then open **http://127.0.0.1:8001/login**. The launcher uses Laragon PHP 8.3 and a project-local extension configuration; it does not change XAMPP's PHP 8.2 installation.

Existing staff emails and password hashes were imported. The previously created demo email and password work in Laravel, with the same manager permissions. Staff permissions are now tenant-specific. Sign out uses a CSRF-protected POST.

For local role testing, `php artisan haven:demo-accounts --tenant=haven` creates one account per configured role with generated passwords and linked staff identities. Existing accounts are not reset. Passwords are printed once and are not committed. Restricted actions are hidden using the destination route's permission checks; direct unauthorized requests still return 403. Cleaner accounts land on housekeeping, and Owner/Standard User accounts land on a limited account page rather than the management dashboard. Owner-specific reporting remains unconfigured. Login throttling applies per email/IP, with an additional overall IP limit, so testing different roles does not share one six-attempt bucket.

Laravel 13 requires PHP 8.3+. Do not serve this application with XAMPP PHP 8.2. The production document root must be `laravel/public`, not the repository root. The built-in local server is for development only.

## What moved

- Laravel 13 HTTP routing, sessions, authentication, authorization middleware, CSRF protection, throttling, console commands, and Blade views.
- Properties, managed units, partner reservations, availability, quotations and payment claims, customers, KYC documents/reviews, invoices, allocations and receipts.
- Housekeeping, cleaner earnings/payments, maintenance, expenses, host agreements/payments, partner-owner payments, owner remittances/closings/reopening, dashboard and reports, including CSV/PDF exports.
- The dashboard's clickable operational counts are retained.
- The missing owner-statement export route and cleaner-payment details endpoint are implemented in Laravel.

The business controllers and calculations were deliberately preserved rather than rewritten simultaneously. `app/Support/Migration` implements their existing array/query/request contracts **using Laravel's database, validation, request, and session services**. There is no CodeIgniter or Shield dependency in Laravel's Composer manifest. Models under `app/Models/Haven` currently use this compatibility contract rather than Eloquent. Refactor these module by module after reconciling behavior; this is a framework migration with a transitional API layer, not a claim that every model was redesigned.

`tools/port-laravel.php` records the deterministic initial port and `tools/laravel-overrides` supplies skeleton replacements. Do not rerun the port after manually improving generated controllers/templates without reviewing it: it overwrites generated files. The Laravel application is self-contained at runtime and does not load source files or dependencies from the CI application.

## Database and company isolation

- The central database contains Laravel users, tenants, memberships, queues, and sessions/cache tables. Local development uses `laravel/database/database.sqlite`.
- Every company has a separate MySQL operational database. The imported company is `haven`, using `haven_suites_laravel13`.
- The original `haven_suites` database is unchanged. The import copied 42 tables and 135 rows into the new database. Private KYC files, if present, are copied to tenant-specific private storage.
- URLs use `/t/{company}/...`. Each staff request checks authentication, active company membership, and the required permission. Public quotation/KYC URLs are company-scoped and still require their random tokens.
- Native account IDs are mapped to tenant-local user IDs on memberships, preserving `staff.user_id` and recorded-by foreign keys when one account belongs to several companies.
- Tenant connections and identity context are cleared after requests. Tests switch between two company databases with overlapping record IDs to check isolation.

Environment files, copied data, browser profiles, screenshots, session files and uploaded documents are excluded from Git. `database/schema/tenant.mysql.sql` contains **schema only**, enabling fresh operational databases even where the original CI migration files were incomplete.

## Import another CI installation

On PHP 8.3+, from `laravel/`:

```powershell
composer install
Copy-Item .env.example .env
php artisan key:generate
# Configure central DB and TENANT_DB_* connection credentials first.
php artisan migrate
php artisan haven:import-ci source_database --destination=new_company_database --slug=company-name --name="Company Name"
```

The importer refuses an existing destination database or company slug. It opens a consistent, read-only source transaction, creates a separate destination, copies schema/data, verifies row counts, imports memberships/password hashes, and copies private KYC files. Run during a maintenance window for a cutover: the source can continue changing after the snapshot, and this is not an ongoing synchronization mechanism. If an import fails, its partial destination is retained for inspection and is never automatically deleted or overwritten.

Keep PHP's password verification algorithm compatible with imported hashes. Test the actual staff login before cutover. Existing CI public links continue to point to CI; newly issued Laravel links use the tenant-scoped Laravel URL. Review imported quotation snapshots that contain old absolute asset URLs.

## Provision a new SaaS company

```powershell
php artisan haven:tenant-create acme "Acme Apartments" admin@acme.example
```

This creates a new, empty database from the operational schema, assigns a company administrator, and seeds expense categories. A new account's password is requested privately; an existing email is assigned a company membership without changing its password. Configure cleaning rates, properties, units, staff and host agreements before operational use. Provisioning requires MySQL database-creation privileges; use a dedicated provisioning account in production rather than giving normal web workers unrestricted database-creation access.

SaaS signup is available at `/register`, followed by email verification, company creation, and first-apartment onboarding. Company administrators can update company settings, invite and revoke members, change company roles, and request subscription plans. A separate platform administrator can manage company access and subscriptions at `/platform`; company Super Administrators do not automatically receive platform access. Password recovery uses Laravel's reset-token flow. Password resets of accounts shared across companies remain blocked in the tenant staff workflow; the account holder can use password recovery instead.

New companies receive a 14-day Starter trial. Monthly prices are Starter KES 300 (3 apartments/3 members), Team KES 1,500 (15 apartments/10 members), and Scale KES 2,500 (100 apartments/50 members). Expired subscriptions keep read access but block operational writes; requesting a plan does not automatically grant higher limits. Haven's imported company retains its existing legacy access.

Stripe test-card checkout, M-Pesa sandbox checkout, and sandbox manual invoices are implemented, including callback validation and idempotent settlement. Billing is disabled by default, requires explicit `SAAS_BILLING_SANDBOX_ENABLED=true`, accepts only Stripe test keys and Safaricom sandbox endpoints, and is blocked in production. Prices and test credential variables are listed in `laravel/.env.example`. No live charges have been made, and live billing is not enabled. Sandbox payments simulate one month of access; automatic recurring charges are not implemented.

Mail defaults to the local log transport, so signup, verification, reset, and invitation emails are written to Laravel's private logs until a real mail transport is configured. Configure SMTP before external user testing. Grant platform access to an existing account using `php artisan haven:platform-admin email@example.com`; for a separate local test account, append `--create-local`. Platform accounts can manage company metadata but do not gain access to company booking/guest/financial records without an explicit company membership.

The one-time `tools/port-laravel.php` source port is now guarded against rerunning after SaaS development, to prevent it overwriting these native Laravel modules.

## Verification

From this machine's repository root:

```powershell
& 'C:/laragon/bin/php/php-8.3.33-Win32-vs16-x64/php.exe' -c tools/php83.ini tools/test-laravel.php
```

Integration tests require the imported `haven_suites_laravel13` copy and MySQL credentials. They use an in-memory central database. Business write tests roll back their changes; provisioning tests create and remove only a randomly named `haven_test_*` database. They must never be pointed at the original CI database.

Coverage includes guest/authenticated access, permission rejection, company membership and database isolation, migrated directory/form/detail pages, report exports, reservations and overlap rejection, guest overpayment rejection, quotation claim review without accounting side effects, private KYC uploads, and fresh-company provisioning. These tests establish migration behavior; they do not replace production accounting reconciliation or a complete business acceptance review.

## Before production cutover

Reconcile booking totals, guest-payment ledgers, cleaner allocations, and owner balances. The migration intentionally retains existing business rules, including the pre-existing separation between reservation `amount_paid` and the payment ledger; it does not silently invent payment entries or correct historical balances. Existing concurrency and accounting-rule gaps identified in the CI review require separate fixes and tests before production use.

Configure HTTPS, production mail, backups/restores for central and tenant databases plus private uploads, scheduled tasks/queue workers, and least-privilege database credentials. Set `APP_DEBUG=false`. Verify public links and private documents for each company. Update the web-server document root only after acceptance; this migration does not change Apache or switch production traffic.

Rollback before cutover is simply continuing to use CI and its unchanged database. After accepting writes in Laravel, rollback requires reconciling those writes; switching branches alone does not synchronize databases.
