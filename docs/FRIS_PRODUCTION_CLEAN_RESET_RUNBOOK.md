# FRIS Production Clean Reset Runbook

Status: planning  
Data changes performed by this runbook: none

## Purpose

Before a clean FRIS migration, remove existing shareholder, company, register, share class, holding, CSCS, dividend, probate, IPO, caution, and legacy migration data so Project T receives FRIS as the fresh source dataset.

This is destructive and must only be run after a verified production backup.

## Preserve These Tables

Do not wipe platform/admin/configuration tables:

- `admin_users`
- `users`
- `departments`
- `positions`
- `roles`
- `permissions`
- `model_has_roles`
- `model_has_permissions`
- `role_has_permissions`
- `personal_access_tokens`
- `cache`
- `cache_locks`
- `jobs`
- `job_batches`
- `failed_jobs`
- `sessions`
- `migrations`
- `password_reset_tokens`
- `instrument_types`
- `shareholder_categories`

`instrument_types` and `shareholder_categories` are reference/setup tables needed after the reset.

## Wipe These Tables

These tables hold shareholder/company/register/share-class data or operational records tied to them.

### FRIS Migration Staging And Crosswalks

- `fris_migration_crosswalks`
- `fris_migration_units`
- `fris_migration_profiles`
- `fris_migration_batches`

### Former Legacy Migration Data

- `legacy_migration_events`
- `legacy_migration_approvals`
- `legacy_migration_records`
- `legacy_migration_batches`
- `company_data_release_events`
- `company_data_release_approvals`
- `company_data_release_records`
- `company_data_releases`

### CSCS Data

- `cscs_workflow_events`
- `cscs_approval_actions`
- `cscs_batch_snapshots`
- `cscs_upload_rows`
- `cscs_upload_batches`
- `cscs_security_mappings`
- `cscs_approval_policies`

`cscs_approval_policies` may be preserved if the policies are generic and still desired. Wipe it only if production should be completely reset for CSCS workflow policy too.

### Dividend Data

- `dividend_workflow_events`
- `dividend_approval_actions`
- `dividend_approval_delegations`
- `dividend_payments`
- `dividend_entitlements`
- `dividend_entitlement_runs`
- `dividend_declaration_share_classes`
- `dividend_declarations`

### IPO Data

- `ipo_offer_allotments`
- `ipo_offers`

### Probate/Estate Data

- `estate_case_representatives`
- `probate_beneficiaries`
- `probate_cases`

### Shareholder Workflow/Change/Caution Data

- `shareholder_change_approvals`
- `shareholder_change_requests`
- `shareholder_caution_logs`
- `shareholder_cautions`
- `shareholder_audit_events`
- `shareholder_import_rows`
- `shareholder_import_batches`

### Shareholder Holdings And Relationships

- `share_transfer_events`
- `shareholder_merge_events`
- `share_transactions`
- `share_lots`
- `share_positions`
- `sra_external_identifiers`
- `sra_proxies`
- `sra_guardians`
- `sra_joint_holders`
- `shareholder_register_accounts`
- `shareholder_bank_mandates`
- `shareholder_identities`
- `shareholder_addresses`
- `shareholders`

### Company/Register/Share Class Core

- `share_classes`
- `registers`
- `companies`

## Recommended Deletion Order

Use this order to avoid foreign-key failures:

```sql
DELETE FROM fris_migration_crosswalks;
DELETE FROM fris_migration_units;
DELETE FROM fris_migration_profiles;
DELETE FROM fris_migration_batches;

DELETE FROM legacy_migration_events;
DELETE FROM legacy_migration_approvals;
DELETE FROM legacy_migration_records;
DELETE FROM legacy_migration_batches;
DELETE FROM company_data_release_events;
DELETE FROM company_data_release_approvals;
DELETE FROM company_data_release_records;
DELETE FROM company_data_releases;

DELETE FROM cscs_workflow_events;
DELETE FROM cscs_approval_actions;
DELETE FROM cscs_batch_snapshots;
DELETE FROM cscs_upload_rows;
DELETE FROM cscs_upload_batches;
DELETE FROM cscs_security_mappings;

DELETE FROM dividend_workflow_events;
DELETE FROM dividend_approval_actions;
DELETE FROM dividend_approval_delegations;
DELETE FROM dividend_payments;
DELETE FROM dividend_entitlements;
DELETE FROM dividend_entitlement_runs;
DELETE FROM dividend_declaration_share_classes;
DELETE FROM dividend_declarations;

DELETE FROM ipo_offer_allotments;
DELETE FROM ipo_offers;

DELETE FROM estate_case_representatives;
DELETE FROM probate_beneficiaries;
DELETE FROM probate_cases;

DELETE FROM shareholder_change_approvals;
DELETE FROM shareholder_change_requests;
DELETE FROM shareholder_caution_logs;
DELETE FROM shareholder_cautions;
DELETE FROM shareholder_audit_events;
DELETE FROM shareholder_import_rows;
DELETE FROM shareholder_import_batches;

DELETE FROM share_transfer_events;
DELETE FROM shareholder_merge_events;
DELETE FROM share_transactions;
DELETE FROM share_lots;
DELETE FROM share_positions;
DELETE FROM sra_external_identifiers;
DELETE FROM sra_proxies;
DELETE FROM sra_guardians;
DELETE FROM sra_joint_holders;
DELETE FROM shareholder_register_accounts;
DELETE FROM shareholder_bank_mandates;
DELETE FROM shareholder_identities;
DELETE FROM shareholder_addresses;
DELETE FROM shareholders;

DELETE FROM share_classes;
DELETE FROM registers;
DELETE FROM companies;
```

## Production Safety Steps

1. Put the app in maintenance mode.
2. Stop queue workers.
3. Take and verify a production database backup.
4. Capture before-counts for every wipe table.
5. Run the wipe inside a transaction where supported.
6. Reset auto-increment sequences if the database engine requires it.
7. Capture after-counts.
8. Run reference seeders if needed for preserved lookup tables.
9. Run FRIS staging migration.
10. Stage, reconcile, publish, and readiness-check FRIS registers.
11. Restart queue workers.
12. Bring the app out of maintenance mode.

## First FRIS Commands After Reset

If the full `FRIS.sqlite` file is too large to upload to the server, export one register package locally and upload only that ZIP:

```bash
php artisan fris:export-register-package 2 --output=storage/app/fris-packages
```

Upload the generated `storage/app/fris-packages/fris_register_2_*.zip` to the server, then stage from the package:

```bash
php artisan fris:stage-register-package storage/app/fris-packages/fris_register_2_YYYYMMDD_HHMMSS.zip
```

The rest of the flow is the same: reconcile, dry-run publish, publish, readiness-check, and rollback if needed.

If the full SQLite file is available on the server, use the direct SQLite path:

```bash
php artisan migrate --path=database/migrations/2026_09_13_060000_create_fris_migration_tables.php --force
php artisan fris:profile --quick
php artisan fris:stage-register 2 --skip-sha
php artisan fris:reconcile-batch {batch_id}
php artisan fris:publish-batch {batch_id} --dry-run
php artisan fris:publish-batch {batch_id}
php artisan fris:readiness {batch_id}
```
