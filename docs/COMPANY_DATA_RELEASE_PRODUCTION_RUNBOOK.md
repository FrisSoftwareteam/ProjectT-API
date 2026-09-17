# Company data release production runbook

This runbook publishes one finalized workstation company release into Project T production. Company source JSON, workstation databases, reports and migration tools are never deployed.

## Fidelity approved release

- Bundle: `fidelity-bank-register-87-production-v1.tar.gz`
- SHA-256: `3f80c5f32d9c04892c7531331a2c14c8b2cac9cd85ce977b44ad3d75c1157220`
- Bundle release ID: `1dd3c531727ef54b3d6099f7a47b7045c63e08a1290ace7bcdf269fa61599684`
- Target: issuer `FIDELITYBK`, register `87`, share class `ORDINARY`
- Rows: `402770`
- Units: `28962585692.000000`
- Holding mode: `paper` for every row
- Categories: `A`, `C`, `I`, `V`, `Z`
- Contacts: unique deterministic placeholders, suppressed and unverified

By explicit project decision, these three finalized Fidelity files are committed in the private repository under `migration-releases/`, allowing the normal Git deployment to deliver them with the code. No other company bundle, Estock source, workstation database or report is allowlisted. Treat repository access as production-data access because this bundle contains readable shareholder data.

## 1. Deployment and backup gate

1. Deploy a clean, reviewed application release whose external manifest says `production_approved: true` and `git_dirty: false`.
2. Install production Composer dependencies and run the normal cache commands for the deployment platform.
3. Put the application in the agreed migration maintenance window. Prevent Fidelity activity until import and reconciliation pass.
4. Create and test a restorable production database backup. Record its identifier in the change ticket.
5. Run schema migrations and seed the authoritative shareholder categories:

```bash
php artisan migrate --force
php artisan db:seed --class=ShareholderCategorySeeder --force
```

6. Confirm two different active administrators are available: a maker and an independent checker. Never use the same account for both steps.

## 2. Deployed bundle verification

After the production Git deployment, restrict the checked-out files and verify the committed checksum:

```bash
cd migration-releases
chmod 600 fidelity-bank-register-87-production-v1.*
sha256sum -c fidelity-bank-register-87-production-v1.sha256
cd ..
```

The output must say `OK`.

Also compare the checksum with the value recorded above through a separate communication channel.

## 3. Production preflight and maker submission

The maker runs:

```bash
php artisan company-release:verify \
  migration-releases/fidelity-bank-register-87-production-v1.tar.gz \
  --sha256=3f80c5f32d9c04892c7531331a2c14c8b2cac9cd85ce977b44ad3d75c1157220 \
  --actor=MAKER_ADMIN_EMAIL \
  --comment='Verified Fidelity release against independently recorded workstation checksum'
```

This scans every record, validates every row hash and total, resolves the target by stable codes, confirms all categories exist, rejects identifier collisions, requires an empty target share class, checks constant capital and creates a `PENDING_APPROVAL` ledger entry. It does not import shareholders.

Record the returned release ID. Review it with:

```bash
php artisan company-release:status RELEASE_ID
```

## 4. Independent approval and import

The checker reviews the manifest, expected totals, target codes and verification result, then runs:

```bash
php artisan company-release:approve RELEASE_ID \
  --actor=CHECKER_ADMIN_EMAIL \
  --comment='Independent approval of immutable Fidelity company release and totals'

php artisan company-release:import RELEASE_ID \
  --actor=CHECKER_ADMIN_EMAIL
```

The importer verifies the artifact and approved snapshot again. It commits in 1,000-record transactions and records exact target IDs in the release ledger. If the process is interrupted after some chunks commit, inspect status and resume only after the cause is understood:

```bash
php artisan company-release:import RELEASE_ID \
  --actor=CHECKER_ADMIN_EMAIL \
  --resume
```

Do not start a new release or manually insert replacement rows.

## 5. Mandatory reconciliation and release

Run:

```bash
php artisan company-release:reconcile RELEASE_ID
php artisan company-release:status RELEASE_ID
```

Production may leave the maintenance window only when all of these are true:

- Status is `IMPORTED`.
- Reconciliation result is `PASS`.
- Imported rows and position rows are both `402770`.
- Imported and position quantities are both `28962585692.000000`.
- Missing lineage links are `0`.
- Unsafe contacts are `0`.
- Register capital validation passes.

Attach command output and database backup identifier to the change record.

## 6. Controlled rollback

Rollback is an emergency action for the period before users or integrations begin working with the imported data. It refuses to proceed if imported data has been edited or if supported downstream tables reference the imported holders/register accounts.

```bash
php artisan company-release:rollback RELEASE_ID \
  --actor=ROLLBACK_ADMIN_EMAIL \
  --comment='Approved production rollback reason and change-ticket reference'
```

After rollback, confirm status is `ROLLED_BACK`, the rolled-back row count is `402770`, Fidelity’s target positions are empty and outstanding units are restored. Audit ledger, approvals and events remain available. If rollback reports `ROLLBACK_BLOCKED`, do not delete data manually; restore the database backup or follow an approved remediation plan.

## Rehearsal evidence

The exact Fidelity artifact completed a clean production-like rehearsal on 31 July 2026: verification, independent approval, full import, exact reconciliation and full rollback. The rehearsal produced 402,770 distinct holders, addresses, register accounts and positions, zero missing links, zero unsafe contacts, zero foreign-key violations, and a clean database integrity check.
