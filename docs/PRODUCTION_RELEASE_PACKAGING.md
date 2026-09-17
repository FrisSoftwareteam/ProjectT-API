# Production application release packaging

The local Project T checkout is the migration and verification workstation. Production receives an allowlisted application archive and, separately, an approved encrypted company migration bundle.

## Application release

Create a production archive only from a clean, reviewed Git commit:

```bash
php scripts/build_production_release.php --name=projectt-api-YYYYMMDD
```

The command refuses a dirty worktree. For local packaging verification only:

```bash
php scripts/build_production_release.php \
  --name=projectt-api-local-preview \
  --allow-dirty
```

Outputs are written to `build/production/`:

- `<name>.tar.gz` — allowlisted application archive.
- `<name>.manifest.json` — Git state, file inventory, file checksums and archive checksum.
- `<name>.sha256` — checksum used to verify transfer integrity.

Verify the archive and every internal application file before transfer and again after transfer:

```bash
php scripts/verify_production_release.php \
  build/production/projectt-api-YYYYMMDD.tar.gz
```

The default archive does not contain `vendor/`. Run the following after extracting it into a new production release directory:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
```

Use `--with-vendor` only when the deployment host cannot install Composer dependencies. That option builds a production-only dependency tree inside the archive.

## Always excluded

- Estock source JSON and other source extracts.
- Local SQLite databases and backups.
- Approved or draft company data bundles.
- Workstation reports and generated outputs.
- Tests, local analysis tools and Postman assets.
- Git, IDE and Codex metadata.
- Local environment files, credentials, keys, logs and caches.
- Node development dependencies.

Production environment variables and secrets must be provisioned independently by the deployment platform. They must never be copied from the workstation archive.

## Company migration releases

Company data is not part of the code-only application archive. By explicit project decision, only the finalized Fidelity bundle, manifest and checksum are allowlisted in Git under `migration-releases/`; all other company bundles and workstation data remain ignored. A Git-based deployment can therefore retrieve this approved bundle with the application without a second upload. Repository access must remain private and tightly controlled because the committed bundle contains readable shareholder data.

The production importer is implemented through the `company-release:*` commands. It uses stable issuer/register/share-class codes, an immutable release ledger, independent approval, 1,000-row transactions, resumable imports, exact reconciliation and guarded rollback. Follow `docs/COMPANY_DATA_RELEASE_PRODUCTION_RUNBOOK.md`; the application archive alone is never authorization to load a company.

## Deployment gate

Before deployment, confirm:

1. `production_approved` is `true` in the external manifest.
2. `git_dirty` is `false`.
3. The transferred archive matches the `.sha256` file.
4. The internal `.release/files.sha256` inventory verifies after extraction.
5. No company bundle, `.env`, SQLite file, Estock source or workstation output appears in the archive.
6. A restorable production database backup exists.
7. The allowlisted Fidelity bundle is present after deployment and matches its committed checksum.
