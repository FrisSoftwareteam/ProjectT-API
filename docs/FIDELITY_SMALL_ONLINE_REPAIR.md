# Deploy only the small Fidelity repair

This release adds `cscs:repair-fidelity-small` for legacy accounts 260382, 367705 and 377853. It does not perform a full-register import or post any CSCS batch. The approved release record for the missing account is included in a checksum-verified three-record evidence file; source transaction IDs and unit IDs support the identifier crosswalks.

## Deploy

Push the reviewed repair commit to main to trigger the existing Azure deployment workflow. New runtime files are the command, service, database/repairs/fidelity-small-v1.json and the guarded actor-FK migration. The parser fix is already in main. The local snapshot trial script is not needed online.

## Preview on the server

In the Azure SSH console, from the application root, run:

```bash
php artisan cscs:repair-fidelity-small
```

This changes nothing. Save the JSON output. It resolves online IDs by register code 87 and legacy account numbers, not workstation IDs. It reports the before/after share-class totals, missing account, identifier conflicts, existing holder mismatches and basic posting-schema checks.

The preview now returns `share_class`, including all active classes on register 87 and how one was selected. It uses a unique `ORDINARY` code when present; if Fidelity has exactly one active class under a different code, it uses that sole class and records `SOLE_REGISTER_CLASS`. If there are multiple classes and no unique `ORDINARY`, it stops without changing anything and prints their IDs, codes and names. After independently confirming the ordinary-equity class, rerun the preview with its ID:

```bash
php artisan cscs:repair-fidelity-small --share-class-id=CONFIRMED_ID
```

## Schema checks

If `schema_checks.approval_snapshot_table` is false, take a production backup and apply the existing missing-table migration:

```bash
php artisan migrate --path=database/migrations/2026_08_13_000001_harden_cscs_workflow.php --force
```

Run it only when the table is absent and that migration is pending. An existing table with an absent migration-ledger row requires separate schema-ledger reconciliation.

If `schema_checks.transaction_actor_fk` is `users`, use the guarded correction:

```bash
php artisan migrate --path=database/migrations/2026_11_05_000001_correct_empty_share_transaction_actor_fk.php --force
```

It stops if any historical created_by values need review; it does not remap user IDs or disable constraints. If it reports admin_users, no correction is needed.

## Repair application

Take a restorable database backup and pause Fidelity writes during the preview/reconciliation/apply window. Verify that the third account is absent rather than renamed or represented on another account. The command refuses exact-name/deterministic-account collisions, differing identifiers and identifier owners elsewhere. Independently reconcile the intended final share-class total and migration cutover before adding the missing 5000-share opening position. Do not treat the command's arithmetic alone as evidence that the balance should be increased.

Once the preview is reviewed and the final total is established, an active administrator with `cscs.admin` runs:

```bash
php artisan cscs:repair-fidelity-small --apply --actor=ADMIN_ID --restore-missing --expected-final-total=VERIFIED_FINAL_TOTAL
```

Replace ADMIN_ID and VERIFIED_FINAL_TOTAL with reviewed values. Without those prerequisites, the command stops before making any changes. Existing positions remain unchanged; only an explicitly approved missing account gets its source opening position. Constant-capital totals must remain balanced. Register outstanding-unit metadata is synchronized only for restoration. All three account changes and provenance are audited inside one transaction; audit failure rolls everything back. Re-running does not create duplicate shareholders or positions.

## Verify the existing upload

Revalidate batch 7 as its maker through `POST /api/cscs/uploads/7/reconcile`. All six rows must be READY, zero unresolved exceptions, debit/credit totals 11000 each. Complete the normal maker/checker approval process afterward. Historical June instructions were present in the source ledger; production posting still requires confirmation they were not already included in the migration cutover. This repair does not automatically post or exclude any movement.
