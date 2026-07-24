# CSCS Workflow API

This document describes the implemented CSCS staging, reconciliation, maker-checker approval, and controlled posting API.

Base URL:

```text
/api/cscs
```

All endpoints require Sanctum authentication. Each route also requires the listed CSCS permission. Uploaded source files are stored privately, financial workflow actions are rate-limited, and the maker cannot approve or post their own batch.

## Permissions

| Permission | Use |
|---|---|
| `cscs.view` | View permitted batches, rows, previews, history, and configuration |
| `cscs.upload` | Upload and stage source files |
| `cscs.reconcile` | Resolve exceptions and run reconciliation |
| `cscs.submit` | Submit or cancel a maker-owned batch |
| `cscs.review` | Raise checker queries |
| `cscs.approve` | Approve or reject the active approval step |
| `cscs.post` | Release an approved batch for queued posting |
| `cscs.export` | Export reports and download source files |
| `cscs.admin` | Maintain security mappings and approval policy |

Run `RolesAndPermissionsSeeder` after deployment so these permissions exist and are assigned to the standard roles.

## Workflow states

```text
PROCESSING
  -> DRAFT_REVIEW
       -> RECONCILED
            -> PENDING_APPROVAL
                 -> QUERY_RAISED -> DRAFT_REVIEW
                 -> REJECTED
                 -> APPROVED_AWAITING_POST
                      -> POSTING_QUEUED
                           -> POSTING
                                -> POSTED
                                -> POSTING_FAILED
                      -> STALE -> DRAFT_REVIEW
       -> CANCELLED
```

`status` in the API refers to `workflow_status`. The older batch `status` column is retained only for backward-compatible storage.

## Configuration endpoints

| Method | Endpoint | Permission |
|---|---|---|
| GET | `/security-mappings` | `cscs.view` |
| POST | `/security-mappings` | `cscs.admin` |
| PATCH | `/security-mappings/{mappingId}` | `cscs.admin` |
| POST | `/security-mappings/{mappingId}/deactivate` | `cscs.admin` |
| GET | `/approval-policy` | `cscs.view` |
| PUT | `/approval-policy` | `cscs.admin` |

Create or update a mapping:

```json
{
  "security_code": "STANBIC",
  "register_id": 12,
  "share_class_id": 4,
  "is_active": true
}
```

The share class must belong to the selected register. One normalized security code can have only one mapping.

Approval policy:

```json
{
  "name": "Default CSCS policy",
  "checker_roles": ["Internal Audit"],
  "additional_approval_quantity": "1000000.000000",
  "additional_approval_roles": ["Internal Audit", "Compliance"],
  "checker_can_post": true
}
```

The second approval step is activated when total debit is at or above the configured threshold. One user cannot approve multiple steps in the same batch revision.

## Upload and inspection endpoints

| Method | Endpoint | Purpose |
|---|---|---|
| POST | `/import` | Store files and stage parsed rows; never posts holdings |
| GET | `/uploads` | Paginated batch list |
| GET | `/uploads/{batchId}` | Batch detail, workflow history, and allowed actions |
| GET | `/uploads/{batchId}/rows` | Movement rows |
| GET | `/uploads/{batchId}/rows/{rowId}` | One staged row |
| GET | `/uploads/{batchId}/master-records` | Parsed master records |
| GET | `/uploads/{batchId}/transactions` | Transaction groups |
| GET | `/uploads/{batchId}/transactions/{transactionNumber}` | Debit and credit legs for a transaction |
| GET | `/uploads/{batchId}/account-effects` | Proposed holding effects |
| GET | `/uploads/{batchId}/preview` | Complete maker/checker preview |
| GET | `/uploads/{batchId}/exceptions` | Blocking and resolved exceptions |
| GET | `/uploads/{batchId}/files` | Private source-file metadata |
| GET | `/uploads/{batchId}/files/{fileIndex}/download` | Authorized source-file download |
| GET | `/uploads/{batchId}/related-batches` | Original/reversal batch links |

Upload uses `multipart/form-data`:

```text
register_id=12
description=CSCS daily advice for 16 June 2026
business_reference=CSCS-20260616-STANBIC
files[]=STANBICmast.txt
files[]=STANBICs6.txt
```

Rules:

- One or two files, maximum 20 MB each by default.
- At most one master and one movement file.
- A movement file is mandatory.
- Allowed extensions are `.txt` and `.csv`, but content must match a supported fixed-width layout.
- Master rows are 393 characters and movement rows are 114 characters, excluding line endings.
- Multipart file order does not matter.
- Original names are sanitized and SHA-256 file hashes are retained.

## Reconciliation endpoints

| Method | Endpoint | Permission |
|---|---|---|
| POST | `/uploads/{batchId}/exceptions/{exceptionId}/resolve` | `cscs.reconcile` |
| POST | `/uploads/{batchId}/revalidate` | `cscs.reconcile` |
| POST | `/uploads/{batchId}/reconcile` | `cscs.reconcile` |
| GET | `/uploads/{batchId}/reconciliation` | `cscs.view` |

Manual account resolution:

```json
{
  "resolution_type": "MAP_ACCOUNT",
  "register_account_id": 445,
  "reason": "Confirmed against the shareholder register"
}
```

Other resolution types are `CONFIRM_REPLAY` and `RULE_EXCLUDED`. Replays must already exist as posted movement legs. Rule exclusions apply to the complete transaction group and require a meaningful reason.

Run reconciliation:

```json
{
  "comment": "All transaction pairs and proposed holdings reviewed"
}
```

Reconciliation requires:

- Every movement row has a final disposition.
- Every transaction group has exactly two unique sequences.
- Every group has one debit and one credit of equal quantity.
- Trade date and `SEC_CODE` agree within each group.
- Every security code has an active mapping for the selected register.
- Every identifier resolves to an account or an approved new credit account proposal.
- No proposed holding is negative.
- All posted replays are identified.
- There is no partially excluded or partially replayed group.

`TRAN_SEQ=0` is treated as a normal financial leg. It is not automatically skipped.

## Maker-checker endpoints

| Method | Endpoint | Permission |
|---|---|---|
| POST | `/uploads/{batchId}/submit` | `cscs.submit` |
| POST | `/uploads/{batchId}/query` | `cscs.review` |
| POST | `/uploads/{batchId}/respond-to-query` | `cscs.reconcile` |
| POST | `/uploads/{batchId}/approve` | `cscs.approve` |
| POST | `/uploads/{batchId}/reject` | `cscs.approve` |
| POST | `/uploads/{batchId}/cancel` | `cscs.submit` |
| GET | `/uploads/{batchId}/approvals` | `cscs.view` |
| GET | `/uploads/{batchId}/events` | `cscs.view` |

Submission freezes a SHA-256 snapshot of every source row, resolution, mapping, and proposed holding effect. Any query response or material change creates a new revision and clears the previous submission state.

Approval does not post holdings. The maker is prohibited from approving, rejecting as checker, or posting their own batch.

Typical action body:

```json
{
  "comment": "Balanced transaction groups and proposed holding effects reviewed"
}
```

Query body:

```json
{
  "comment": "Please confirm the selected debit account",
  "transaction_numbers": ["2606160005615022"],
  "row_ids": [501]
}
```

## Posting endpoints

| Method | Endpoint | Permission |
|---|---|---|
| POST | `/uploads/{batchId}/post` | `cscs.post` |
| POST | `/uploads/{batchId}/retry-posting` | `cscs.post` |
| GET | `/uploads/{batchId}/posting-status` | `cscs.view` |

Posting returns `202 Accepted` and dispatches a unique job to the `cscs` queue. Run a worker in production:

```bash
php artisan queue:work --queue=cscs,default --tries=1 --timeout=900
```

Before changing holdings, the job locks the batch and relevant positions and confirms:

- The approved snapshot is unchanged.
- Security mappings still match the approved share classes.
- Opening holdings still equal the checker-approved preview.
- The poster is not the maker.
- Any separate-poster policy is satisfied.
- No movement replay key has already been posted.

All movement legs are posted in one database transaction. A failure rolls back the financial changes. Quantities use BCMath at six-decimal precision. Post-posting verification must succeed before the batch becomes `POSTED`.

If opening holdings or mappings changed, the batch becomes `STALE` and must return through reconciliation and approval. Technical failures become `POSTING_FAILED` and may be retried deliberately.

## Export endpoint

```text
GET /uploads/{batchId}/export?type=rows
```

Supported types:

- `rows`
- `exceptions`
- `reconciliation`
- `preview`
- `posting`

Exports and source downloads require `cscs.export`.

## Corrections after posting

Posted financial records are immutable. Create a compensating batch instead:

```text
POST /uploads/{batchId}/create-reversal
```

```json
{
  "reason": "Correction approved for the source CSCS advice",
  "effective_date": "2026-07-22"
}
```

This requires `cscs.upload`, accepts only a `POSTED` source batch, and creates a linked `REVERSAL` batch in `DRAFT_REVIEW`. It does not immediately alter holdings: the reversal must be reconciled, submitted, independently approved, and posted through the same controls. Use `GET /uploads/{batchId}/related-batches` to trace the original and compensating batches.

## Error conventions

- `401`: unauthenticated.
- `403`: permission failure or maker-checker segregation violation.
- `404`: batch, row, mapping, or private file not found.
- `409`: reserved for client-visible workflow conflicts where added by the application exception handler.
- `422`: invalid payload, invalid workflow state, unresolved reconciliation, stale snapshot, or financial validation failure.
- `429`: rate limit exceeded.
- `500`: sanitized technical error; internal details are written only to secured logs with a reference.

Never build frontend authorization solely from button visibility. The API enforces every permission, actor-separation rule, and state transition server-side.

## Deployment checklist

1. Back up the database.
2. Run `php artisan migrate`.
3. Run `php artisan db:seed --class=RolesAndPermissionsSeeder`.
4. Assign the new permissions to any custom roles.
5. Create and verify every production `SEC_CODE` mapping.
6. Configure and review the CSCS approval policy.
7. Set `CSCS_MAX_UPLOAD_KB` and `CSCS_RETENTION_DAYS`.
8. Ensure private storage is not web-accessible.
9. Start and monitor the `cscs` queue worker.
10. Run the CSCS workflow tests and UAT with anonymized production-format files.
11. Confirm the official CSCS field layout and sequence semantics before production release.
