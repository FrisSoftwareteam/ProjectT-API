# Testing database schema repair — 30 July 2026

Database: `database/database.sqlite`  
Scope: schema reconciliation only; the staged Fidelity batch was not submitted, approved or published.

## Recovery point

A byte-for-byte pre-repair copy is available at:

`/private/tmp/projectt-testing-validated-before-schema-repair-2026-07-30.sqlite`

The repair sequence was first completed and verified on:

`/private/tmp/projectt-schema-repair-rehearsal-2026-07-30.sqlite`

## Drift repaired

- Reconciled historical migrations whose tables existed while their migration-ledger rows were absent.
- Created the missing dividend workflow audit table.
- Rebuilt the empty legacy `dividend_payments` and `dividend_entitlements` tables to the current application schema.
- Applied SQLite-safe equivalents for two historical MySQL-only enum-alter migrations.
- Restored the missing `users` table expected by the recorded base migration.
- Added the current nullable shareholder name-part fields.
- Applied every remaining pending migration.

The guarded additive repair is implemented in `database/migrations/2026_07_30_000001_repair_legacy_schema_gaps.php`. It refuses to rebuild the legacy dividend tables if either table contains data, preventing silent data loss in another environment.

## Verification

- Pending migrations: zero.
- Duplicate migration-ledger entries: zero.
- Current model fillable fields missing from their tables: zero.
- SQLite integrity check: `ok`.
- Foreign-key violations: zero.
- PHP syntax and Git whitespace checks: passed.
- Application tests: passed, 156 assertions. The existing missing-`.env` warnings remain warnings only.
- Fidelity batch remains `VALIDATED` with 402,770 valid rows and zero validation errors.
- Target shareholders, register accounts and positions remain empty.
