# FRIS Migration Initial Plan

Status: discovery started  
Data changes performed: none

## Why FRIS Is The Preferred Source

`FRIS.sqlite` is already shaped around the registrar domain:

- `companies` identifies each register by `register_code`.
- `profiles` stores shareholder account/profile data by `account_number + register_code`.
- `units` stores certificate/unit history by `acctno + regcode`.
- `combined_view` joins companies, profiles, and units for operational inspection.

This is cleaner than the former JSON source for shareholder migration because the stable source keys are explicit and the data is queryable with SQL.

## Proposed Source Keys

- Shareholder register account: `profiles.register_code + profiles.account_number`
- Unit/certificate row: `units.id`
- Human-readable certificate key: `units.regcode + units.acctno + units.cert_number`
- Company/register key: `companies.register_code`

Do not merge shareholders by name, email, phone, or address during migration.

## Initial Target Mapping

- `companies` -> `companies`
- `companies.register_code` -> `registers.register_code`
- `profiles` -> `shareholders`, `shareholder_addresses`, `shareholder_register_accounts`, `shareholder_bank_mandates`
- `units` -> `share_lots`, `share_transactions`
- Aggregated `units` or verified `profiles.holdings` -> `share_positions`

## Early Schema Concerns

- FRIS contact data can be missing, so Project T must support legacy placeholder/suppressed email and phone values or nullable legacy contact fields.
- Project T should preserve FRIS source keys in a generic crosswalk table, not only in generated account numbers.
- Certificate lifecycle fields in `units` need explicit status mapping before publish.
- `profiles.holdings` and summed `units.units` must be reconciled per `register_code + account_number`.

## First Implementation Path

1. Profile FRIS with `php artisan fris:profile --quick`.
2. Run full profiling during a longer window with `php artisan fris:profile`.
3. Add FRIS-specific staging tables or extend legacy migration records to support FRIS source table/key identities.
4. Build a read-only FRIS source adapter.
5. Pilot one small register into staging.
6. Reconcile profile counts, account counts, holdings, unit totals, and orphan records.
7. Only after reconciliation, publish to Project T domain tables through queued, chunked jobs.

## Current Implementation Status

Added read-only profiling command:

```bash
php artisan fris:profile --quick
php artisan fris:profile
```

Added FRIS staging migration:

```bash
php artisan migrate --path=database/migrations/2026_09_13_060000_create_fris_migration_tables.php --force
```

Added pilot staging command:

```bash
php artisan fris:stage-register 2 --limit=1000 --skip-sha
```

The staging command writes only to `fris_migration_*` tables. It does not publish into `shareholders`, `shareholder_register_accounts`, `share_positions`, `share_lots`, or `share_transactions`.

## Pilot Result

Applied the FRIS staging migration only:

```bash
php artisan migrate --path=database/migrations/2026_09_13_060000_create_fris_migration_tables.php --force
```

Staged a capped register `2` pilot:

```bash
php artisan fris:stage-register 2 --limit=1000 --skip-sha
```

Result:

- Batch ID: `1`
- Register: `2`
- Company: `EZEEKLICK SYSTEMS LTD - SAMPLE REGISTER`
- Staged profiles: `1000`
- Valid profiles: `1000`
- Error profiles: `0`
- Staged unit rows: `1000`
- Valid unit rows: `1000`
- Error unit rows: `0`
- Staged profile holdings: `1957095.320000`
- Staged unit quantity: `1957095.320000`

Reconciled pilot batch:

```bash
php artisan fris:reconcile-batch 1
```

Reconciliation result:

- Passed: `yes`
- Profile rows: `1000`
- Unit rows: `1000`
- Profile errors: `0`
- Unit errors: `0`
- Quantity difference: `0.000000`
- Duplicate profiles: `0`
- Duplicate unit IDs: `0`
- Units without profile: `0`
- Profiles without units: `0`
- Account quantity mismatches: `0`

## Full Register 2 Staging Result

Staged full register `2`:

```bash
php artisan fris:stage-register 2 --skip-sha
```

Result:

- Batch ID: `2`
- Register: `2`
- Company: `EZEEKLICK SYSTEMS LTD - SAMPLE REGISTER`
- Staged profiles: `3076`
- Valid profiles: `3076`
- Error profiles: `0`
- Staged unit rows: `3076`
- Valid unit rows: `3076`
- Error unit rows: `0`
- Staged profile holdings: `2186204.700000`
- Staged unit quantity: `2186204.700000`

Reconciled full register `2` batch:

```bash
php artisan fris:reconcile-batch 2
```

Reconciliation result:

- Passed: `yes`
- Profile rows: `3076`
- Unit rows: `3076`
- Profile errors: `0`
- Unit errors: `0`
- Quantity difference: `0.000000`
- Duplicate profiles: `0`
- Duplicate unit IDs: `0`
- Units without profile: `0`
- Profiles without units: `0`
- Account quantity mismatches: `0`

## Register 159 Pilot Result

Register `159` (`FBN HERITAGE FUND`) was selected as the next real-data pilot:

- Profiles in FRIS: `5498`
- Unit rows in FRIS: `94015`

Initial staging showed many negative `units.units` values. These are movement rows and must not be treated as invalid quantities. The staging rule was updated so numeric negative unit rows are valid; only non-numeric unit quantities are invalid.

Re-staged register `159`:

```bash
php artisan fris:stage-register 159 --skip-sha --chunk=5000
```

Reconciled batch `4`:

```bash
php artisan fris:reconcile-batch 4
```

Result:

- Passed: `yes`
- Profile rows: `5498`
- Unit rows: `94015`
- Profile errors: `0`
- Unit errors: `0`
- Profile holdings: `24292865.830000`
- Unit quantity: `24292865.830000`
- Quantity difference: `0.000000`
- Duplicate profiles: `0`
- Duplicate unit IDs: `0`
- Units without profile: `7722`
- Units without profile quantity: approximately `0`
- Profiles without units: `0`
- Account quantity mismatches: `0`

Migration rule learned: unit rows without a staged current profile are not publish-blocking when their net quantity is zero. They should be retained in staging/source lineage as historical balanced movements.

## First Publish Result

Published reconciled batch `2` for sample register `2`:

```bash
php artisan fris:publish-batch 2 --dry-run
php artisan fris:publish-batch 2
```

Result:

- Batch status: `PUBLISHED`
- Project T company created: `FRIS-2`
- Project T register created: `2`
- Project T share class created: `ORD`
- Published profiles: `3076`
- Published unit rows: `3076`
- Crosswalk rows:
  - `shareholder_register_accounts`: `3076`
  - `share_transactions`: `3076`
- Project T share positions: `3076`
- Project T position total: `2186204.700000`
- Project T share lots: `3076`
- Project T lot total: `2186204.700000`
- Project T transactions:
  - `transfer_in`: `3076` rows, `2186204.700000` quantity

The first publish path is intentionally conservative. It creates placeholder suppressed contacts, preserves FRIS source links in `fris_migration_crosswalks`, publishes current positions from profile holdings, publishes positive unit rows as lots, and publishes unit rows as transactions.

## Operational Readiness Setup

Future FRIS publishes now initialize records needed by downstream workflows:

- `registers.instrument_type` set to `equity`
- `registers.instrument_type_id` set to `ordinary_share`
- `registers.capital_behaviour_type` set to `constant`
- `registers.paid_up_capital` set from published position total
- `registers.total_units_outstanding` set from published position total
- `registers.remaining_outstanding_units` set from published position total
- `registers.unit_precision_type` set to `decimal`
- `registers.decimal_precision` set to `6`
- `cscs_security_mappings.security_code` created as `FRIS{register_code}`
- FRIS bank account data is staged as `shareholder_bank_mandates` with `pending` status when present

Added readiness command:

```bash
php artisan fris:readiness {batch_id}
php artisan fris:readiness {batch_id} --repair
```

Applied readiness repair to already-published batch `2`:

```bash
php artisan fris:readiness 2 --repair
```

Result:

- Company exists: `yes`
- Register exists: `yes`
- Share class exists: `yes`
- Instrument type set: `yes`
- Capital totals set: `yes`
- CSCS mapping exists: `yes`
- Position count: `3076`
- Position total: `2186204.700000`
- Paid-up capital: `2186204.700000`
- Active bank mandates: `0`
- Pending bank mandates: `0`

Dividend note: the approved business rule is to trust FRIS bank mandate data as active legacy mandate data. Future FRIS publishes import available bank mandates with `active` status.

## Rollback Test Result

Added rollback command:

```bash
php artisan fris:rollback-batch {batch_id} --dry-run
php artisan fris:rollback-batch {batch_id}
```

Dry-run rollback for published batch `2` reported:

- Shareholders: `3076`
- Addresses: `3076`
- Bank mandates: `0`
- SRAs: `3076`
- Positions: `3076`
- Lots: `3076`
- Transactions: `3076`
- Crosswalks: `6152`

Executed rollback for batch `2`, verified cleanup, then republished batch `2`.

Final readiness check after republish:

- Batch status: `PUBLISHED`
- Company exists: `yes`
- Register exists: `yes`
- Share class exists: `yes`
- Instrument type set: `yes`
- Capital totals set: `yes`
- CSCS mapping exists: `yes`
- CSCS security code: `FRIS2`
- Position count: `3076`
- Position total: `2186204.700000`
- Paid-up capital: `2186204.700000`
- Active bank mandates: `0`
- Pending bank mandates: `3076`

## Register 159 Publish Result

Published reconciled real-data batch `4`:

```bash
php artisan fris:publish-batch 4 --dry-run
php artisan fris:publish-batch 4
php artisan fris:readiness 4
```

Result:

- Batch status: `PUBLISHED`
- Company created: `FRIS-159`
- Register created: `159`
- Share class created: `ORD`
- Profiles published: `5498`
- Unit rows published to current accounts: `86293`
- Historical balanced orphan unit rows retained in staging: `7722`
- Crosswalk rows:
  - `shareholder_register_accounts`: `5498`
  - `share_transactions`: `86293`
- Project T positions: `5498`
- Project T position total: `24292865.830000`
- Project T paid-up capital: `24292865.830000`
- Project T share lots: `52174`
- Project T lot total: `168001888.960000`
- Project T transactions:
  - `transfer_in`: `52175` rows, `168001888.960000` quantity
  - `transfer_out`: `34118` rows, `143709023.130000` quantity
- CSCS readiness: `passed`
- CSCS security code: `FRIS159`
- Active bank mandates: `0`
- Pending bank mandates: `1870`

For registers with movement history, `share_lots` represent positive inflow lots while `share_transactions` represent both inflows and outflows. Net transactions reconcile to the current `share_positions` total.

## Mandate Activation Decision

Approved rule: **Option B - Auto-activate FRIS mandates**.

Future FRIS publishes create available FRIS bank mandates as `active`.

Added command for already-published batches:

```bash
php artisan fris:activate-mandates {batch_id} --dry-run
php artisan fris:activate-mandates {batch_id}
```

Applied to existing published batches:

```bash
php artisan fris:activate-mandates 2
php artisan fris:activate-mandates 4
```

Readiness after activation:

- Batch `2` active mandates: `3076`
- Batch `2` pending mandates: `0`
- Batch `4` active mandates: `1870`
- Batch `4` pending mandates: `0`
