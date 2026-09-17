# Controlled legacy migration framework

This framework moves approved legacy shareholder snapshots into Project T through a reusable, maker-checker workflow. It uses new additive database objects; no historical Project T migration is modified.

## Safety model

The API is a control plane. Source parsing and database writes run in the `legacy-migrations` queue so large registers are not submitted as hundreds of thousands of HTTP requests.

Every package fixes the source checksum, transformation policy, expected row count and expected quantity. Every staged source row has a source hash, row hash and idempotency key. The approved snapshot hash covers all staged row hashes plus the package and reconciliation result.

Publishing is allowed only when:

- the source file still has its approved SHA-256 checksum;
- the share class belongs to the selected register;
- every staged row is valid;
- row and unit totals match exactly;
- every shareholder category exists and is active;
- generated account, email and phone identifiers do not collide with Project T;
- an administrator other than the maker approves the unchanged snapshot.

Fidelity's package is `estock_fidelity_87_v1`. Its expected result is 402,770 paper positions totalling 28,962,585,692 units. Category V uses `TEMP TYPE A` as individual and `TEMP TYPE C` as corporate.

The loader does not infer legal residency from shareholder category V. The existing Project T SRA default remains in effect, while the exact foreign-shareholder category is retained for later KYC verification.

## Workflow

```text
CREATED -> STAGING -> STAGED -> VALIDATED -> PENDING_APPROVAL
        -> APPROVED -> PUBLISHING -> PUBLISHED
                                      |
                                      +-> PUBLISHING_FAILED -> retry

PUBLISHED -> ROLLING_BACK -> ROLLED_BACK
                         -> ROLLBACK_BLOCKED
```

An unpublished batch can be cancelled. A publishing retry processes only records that were not committed by an earlier successful chunk. Each chunk is transactional.

When the latest batch for the same register and source checksum is active, repeated create requests return that same batch. After it reaches `ROLLED_BACK` or `CANCELLED`, a create request opens the next numbered attempt while preserving every earlier batch, approval, event and record-level lineage row.

Rollback is deliberately limited to the cutover verification window. Before deleting anything, it checks for transactions, lots, dividends, cautions, probate, transfers, external identifiers and other downstream references. If activity exists, rollback is blocked and the migrated records remain intact. After live activity begins, corrections must use auditable business adjustments rather than destructive rollback.

## API endpoints

All routes require Sanctum authentication, activity logging and the listed `legacy_migrations.*` permission.

| Method | Endpoint | Purpose |
|---|---|---|
| `GET` | `/api/legacy-migrations/packages` | List registered packages without server file paths |
| `POST` | `/api/legacy-migrations/batches` | Bind a package to a target register and share class |
| `POST` | `/api/legacy-migrations/batches/{id}/stage` | Queue checksum-verified streaming staging |
| `POST` | `/api/legacy-migrations/batches/{id}/reconcile` | Run release-gate reconciliation |
| `POST` | `/api/legacy-migrations/batches/{id}/submit` | Maker submits the immutable snapshot |
| `POST` | `/api/legacy-migrations/batches/{id}/approve` | Independent checker approves it |
| `POST` | `/api/legacy-migrations/batches/{id}/publish` | Queue publish, or safely retry an interrupted publish |
| `POST` | `/api/legacy-migrations/batches/{id}/cancel` | Cancel an unpublished batch |
| `POST` | `/api/legacy-migrations/batches/{id}/rollback` | Queue a guarded pre-opening rollback |
| `GET` | `/api/legacy-migrations/batches/{id}` | Batch, reconciliation, approvals and aggregate record status |
| `GET` | `/api/legacy-migrations/batches/{id}/events` | Immutable workflow history |

Create payload:

```json
{
  "package_key": "estock_fidelity_87_v1",
  "register_id": 123,
  "share_class_id": 456
}
```

Approval requires a meaningful `comment`. Rollback requires a comment of at least 20 characters.

## Placeholder-contact protection

Imported placeholder contacts are unique and deterministic. The shareholder is stored with:

- `email_is_verified = false`
- `phone_is_verified = false`
- `contact_suppressed = true`

Any future shareholder email or SMS feature must require both a verified contact and `contact_suppressed = false` before sending.

## Production operating procedure

1. Back up the production database and record the restore point.
2. Run the queue worker for the dedicated queue: `php artisan queue:work --queue=legacy-migrations --tries=1 --timeout=7200`.
3. Create and stage the batch.
4. Reconcile and export the aggregate evidence for review.
5. Submit and obtain independent approval.
6. Publish during the controlled cutover window.
7. Verify Project T screens/APIs and compare final row, category and unit totals.
8. Either sign off and open the register, or roll back before any downstream activity is allowed.

Production must never bypass the checksum, reconciliation or maker-checker stages. A changed source file requires a new reviewed package/version rather than editing an approved batch.
