# CSCS Frontend Integration Guide

This is the frontend implementation sequence for the CSCS upload, reconciliation, maker-checker approval, posting, and correction workflow.

Related resources:

- [API reference](CSCS_API.md)
- [Full workflow specification](CSCS_UPLOAD_RECONCILIATION_WORKFLOW.md)
- [Postman collection](postman/ProjectT-API.postman_collection.json)

## 1. API client setup

Base path:

```text
/api/cscs
```

Every request requires the application's Sanctum authentication:

```http
Accept: application/json
Authorization: Bearer <token>
```

Send `Content-Type: application/json` for JSON requests. Do not manually set `Content-Type` for file upload; the browser must generate the multipart boundary.

The API has three response shapes:

```javascript
// Mutations
{ success: true, message: '...', data: {} }

// Single-record reads
{ data: {} }

// Paginated reads
{ current_page: 1, data: [], last_page: 1, per_page: 50, total: 0 }
```

The frontend API wrapper must support all three.

## 2. Recommended screens

| Screen | Main endpoints |
|---|---|
| Batch list | `GET /uploads` |
| New upload | `POST /import` |
| Batch workspace | Batch, preview, transaction, effect, exception, file and event reads |
| Exception workbench | Exceptions and resolution endpoints |
| Checker review | Preview, reconciliation, approvals and events |
| Posting monitor | Posting action and status polling |
| Administration | Security mappings and approval policy |

## 3. One-time administration

Before operational use, an administrator configures the security mappings and approval policy.

### Security mapping

```text
GET   /api/cscs/security-mappings
POST  /api/cscs/security-mappings
PATCH /api/cscs/security-mappings/{mappingId}
POST  /api/cscs/security-mappings/{mappingId}/deactivate
```

Create/update body:

```json
{
  "security_code": "STANBIC",
  "register_id": 12,
  "share_class_id": 4,
  "is_active": true
}
```

The share class must belong to the register.

### Approval policy

```text
GET /api/cscs/approval-policy
PUT /api/cscs/approval-policy
```

```json
{
  "name": "Default CSCS policy",
  "checker_roles": ["Internal Audit"],
  "additional_approval_quantity": "1000000.000000",
  "additional_approval_roles": ["Internal Audit", "Compliance"],
  "checker_can_post": true
}
```

The UI must use `current_approval_step` and `required_approval_steps`; one approval may not complete a high-value batch.

## 4. Main operational flow

### Step 1 — List batches

```http
GET /api/cscs/uploads?status=DRAFT_REVIEW&register_id=12&per_page=15
```

Filters: `status`, `register_id`, `business_reference`, and `per_page`.

Display `workflow_status`, not the older storage `status` field.

### Step 2 — Upload and stage files

```http
POST /api/cscs/import
```

Use `FormData`:

```javascript
const form = new FormData();
form.append('register_id', String(registerId));
form.append('description', description ?? '');
form.append('business_reference', businessReference ?? '');
selectedFiles.forEach(file => form.append('files[]', file));

const response = await api.post('/api/cscs/import', form);
const batchId = response.data.data.batch_id;
```

Rules:

- One or two `.txt`/`.csv` files.
- A movement file is mandatory.
- Maximum one master and one movement file.
- Maximum 20 MB per file by default.
- Every file field must be named exactly `files[]`.
- Do not send files as JSON/base64.
- Do not manually set multipart `Content-Type`.

Success returns `202` with `data.batch_id`, `data.status`, and progress under `data.summary`. The initial status is `PROCESSING`; upload never changes live holdings. Poll `GET /uploads/{batchId}` until a terminal processing state is returned. Use `summary.processing_stage` and `summary.processing_percent` for a real progress display rather than inventing a client-side percentage.

The API validates UTF-8 encoding, samples multiple records to identify each fixed-width file, rejects duplicate hashes for the same register, and reports duplicate movement rows or ambiguous master identifiers as blocking exceptions.

### Step 3 — Load the batch workspace

Navigate to the returned batch ID. Load these in parallel:

```text
GET /api/cscs/uploads/{batchId}
GET /api/cscs/uploads/{batchId}/preview
GET /api/cscs/uploads/{batchId}/exceptions?per_page=50
GET /api/cscs/uploads/{batchId}/transactions?per_page=50
```

Optional tabs:

```text
GET /api/cscs/uploads/{batchId}/rows
GET /api/cscs/uploads/{batchId}/rows/{rowId}
GET /api/cscs/uploads/{batchId}/master-records
GET /api/cscs/uploads/{batchId}/transactions/{transactionNumber}
GET /api/cscs/uploads/{batchId}/account-effects
GET /api/cscs/uploads/{batchId}/files
GET /api/cscs/uploads/{batchId}/events
GET /api/cscs/uploads/{batchId}/approvals
GET /api/cscs/uploads/{batchId}/snapshots
```

Show file names/hashes/encoding, record and duplicate counts, transaction groups, debit/credit totals, unresolved exceptions, opening and proposed holdings, risk flags, revision, approval step, immutable snapshots, and audit events.

### Step 4 — Render actions from the batch response

`GET /uploads/{batchId}` includes `allowed_actions`:

```json
{
  "data": {
    "id": 101,
    "workflow_status": "DRAFT_REVIEW",
    "allowed_actions": ["view", "resolve", "revalidate", "cancel"]
  }
}
```

Render a button only when its action is present and the user has the matching frontend permission. The backend remains authoritative. Refresh the batch after every mutation.

### Step 5 — Resolve all exceptions

```http
GET /api/cscs/uploads/{batchId}/exceptions?status=UNRESOLVED&per_page=50
```

Manual account mapping:

```http
POST /api/cscs/uploads/{batchId}/exceptions/{exceptionId}/resolve
```

```json
{
  "resolution_type": "MAP_ACCOUNT",
  "register_account_id": 445,
  "reason": "Confirmed against the shareholder register"
}
```

Obtain `register_account_id` from the application's existing shareholder/register-account lookup. It must belong to the batch register.

Replay or exclusion alternatives:

```json
{
  "resolution_type": "CONFIRM_REPLAY",
  "reason": "Confirmed as an already posted CSCS movement"
}
```

```json
{
  "resolution_type": "RULE_EXCLUDED",
  "reason": "Excluded under the approved documented CSCS rule"
}
```

Replay confirmation must match an existing posted movement. An exclusion affects the complete transaction group. Refresh exceptions and preview after resolution.

### Step 6 — Reconcile

Use this as the canonical endpoint:

```http
POST /api/cscs/uploads/{batchId}/reconcile
```

```json
{
  "comment": "All transaction pairs and proposed holdings reviewed"
}
```

`POST /revalidate` is an alias for the same operation. New frontend code should consistently use `/reconcile`.

Success changes the status to `RECONCILED`. Before submission confirm:

- `summary.unresolved_exceptions` is `0`.
- Debit equals credit.
- `snapshot_hash` is present.
- Status is `RECONCILED`.

A `422` means reconciliation is blocked. Show its validation errors and reload exceptions/preview.

### Step 7 — Maker submits

```http
POST /api/cscs/uploads/{batchId}/submit
```

```json
{
  "comment": "Submitting the fully reconciled CSCS advice"
}
```

Success changes the status to `PENDING_APPROVAL` and freezes the reconciled snapshot.

### Step 8 — Checker reviews

The checker must sign in as a different user from the maker. Load:

```text
GET /api/cscs/uploads/{batchId}
GET /api/cscs/uploads/{batchId}/preview
GET /api/cscs/uploads/{batchId}/reconciliation
GET /api/cscs/uploads/{batchId}/transactions
GET /api/cscs/uploads/{batchId}/account-effects
GET /api/cscs/uploads/{batchId}/files
GET /api/cscs/uploads/{batchId}/approvals
GET /api/cscs/uploads/{batchId}/events
```

### Step 9 — Checker approves

```http
POST /api/cscs/uploads/{batchId}/approve
```

```json
{
  "comment": "Files, balances, exceptions and holding effects verified"
}
```

Inspect the returned state:

- `APPROVED_AWAITING_POST`: all steps are complete.
- `PENDING_APPROVAL`: another approval step remains. Show `current_approval_step`; a different eligible user must approve it.

Approval never changes holdings.

### Step 10 — Release for posting

The poster cannot be the maker. Policy may also require someone other than the checker.

```http
POST /api/cscs/uploads/{batchId}/post
```

```json
{
  "comment": "Authorized release to live holdings"
}
```

The API returns `202 Accepted` and `POSTING_QUEUED`. Do not show success as posted yet.

### Step 11 — Poll posting status

```http
GET /api/cscs/uploads/{batchId}/posting-status
```

Poll every 2–5 seconds while status is `POSTING_QUEUED` or `POSTING`:

```javascript
const active = new Set(['POSTING_QUEUED', 'POSTING']);

async function waitForPosting(batchId, signal) {
  while (!signal.aborted) {
    const response = await api.get(
      `/api/cscs/uploads/${batchId}/posting-status`,
      { signal }
    );
    const result = response.data.data;
    if (!active.has(result.status)) return result;
    await new Promise(resolve => setTimeout(resolve, 3000));
  }
}
```

Stop on:

- `POSTED`: refresh holdings, transactions, preview, and events.
- `POSTING_FAILED`: show the sanitized reason and an authorized retry action.
- `STALE`: return the batch to maker reconciliation.

Cancel polling when the page unmounts.

## 5. Alternative branches

### Checker query

```http
POST /api/cscs/uploads/{batchId}/query
```

```json
{
  "comment": "Please confirm the selected debit account",
  "transaction_numbers": ["2606160005615022"],
  "row_ids": [501]
}
```

The status becomes `QUERY_RAISED`. The maker responds:

```http
POST /api/cscs/uploads/{batchId}/respond-to-query
```

```json
{
  "comment": "Account selection corrected and supporting record verified"
}
```

This creates a new revision and returns the batch to `DRAFT_REVIEW`. Reconcile and submit again; previous approval progress is not reused.

### Checker rejection

```http
POST /api/cscs/uploads/{batchId}/reject
```

```json
{
  "comment": "The source advice does not match the approved instruction"
}
```

`REJECTED` is terminal in the current API. Upload a corrected new batch.

### Maker cancellation

```http
POST /api/cscs/uploads/{batchId}/cancel
```

```json
{
  "comment": "Upload superseded by a corrected CSCS advice"
}
```

Cancelled batches remain read-only for audit.

### Stale batch

For `STALE`, reload the preview, resolve any new issue, reconcile, submit, obtain all approvals again, and post again.

### Posting retry

For `POSTING_FAILED`:

```http
POST /api/cscs/uploads/{batchId}/retry-posting
```

```json
{
  "comment": "Retry authorized after reviewing the technical reference"
}
```

Resume status polling. Never automatically loop financial posting retries in the browser.

## 6. Corrections after posting

Posted financial records are immutable. Create a compensating batch:

```http
POST /api/cscs/uploads/{batchId}/create-reversal
```

Full reversal:

```json
{
  "reason": "Correction approved for the source CSCS advice",
  "effective_date": "2026-07-27"
}
```

Selected transaction groups:

```json
{
  "reason": "Correcting the selected CSCS transaction groups",
  "effective_date": "2026-07-27",
  "transaction_numbers": ["2606160005615022"]
}
```

The response contains a new reversal `batch_id` in `DRAFT_REVIEW`. Run the complete reconcile → submit → approve → post process on it.

Trace corrections with:

```http
GET /api/cscs/uploads/{batchId}/related-batches
```

## 7. Status-to-UI mapping

| Status | Frontend treatment |
|---|---|
| `PROCESSING` | Processing indicator |
| `PROCESSING_FAILED` | Show reference; allow a new upload |
| `DRAFT_REVIEW` | Preview, resolve, reconcile, or cancel |
| `RECONCILED` | Maker can submit |
| `PENDING_APPROVAL` | Active checker step |
| `QUERY_RAISED` | Maker responds and reconciles again |
| `APPROVED_AWAITING_POST` | Authorized posting release |
| `POSTING_QUEUED` | Poll status |
| `POSTING` | Poll; disable actions |
| `POSTING_FAILED` | Deliberate authorized retry |
| `STALE` | Reconcile and approve again |
| `POSTED` | Read-only success; reversal if needed |
| `REJECTED` | Read-only; corrected new upload |
| `CANCELLED` | Read-only audit view |

## 8. Filters, exports, and downloads

```text
GET /uploads/{batchId}/rows?status=READY&identifier=C123&tran_no=2606160005615022&security_code=STANBIC&sign=-&per_page=50
GET /uploads/{batchId}/transactions?page=1&per_page=50
```

Exports:

```text
GET /uploads/{batchId}/export?type=rows
GET /uploads/{batchId}/export?type=exceptions
GET /uploads/{batchId}/export?type=reconciliation
GET /uploads/{batchId}/export?type=preview
GET /uploads/{batchId}/export?type=posting
```

Downloads and exports require `responseType: 'blob'` and the authorization token:

```javascript
const response = await api.get(url, { responseType: 'blob' });
```

Source download:

```text
GET /uploads/{batchId}/files/{fileIndex}/download
```

Use the index returned by `GET /uploads/{batchId}/files`; it is not a database ID.

## 9. Errors and mutation safety

| Status | Frontend behavior |
|---|---|
| `401` | Refresh/clear authentication and return to login |
| `403` | Show permission or maker-checker restriction; reload batch |
| `404` | Show not found and return to list |
| `422` | Map the `errors` object to fields; show workflow message |
| `429` | Prevent repeated submission and show retry guidance |
| `500` | Show only the sanitized server message/reference |

For mutations:

- Disable the button while pending and prevent double clicks.
- Show the API `message` on success.
- Reload the batch after success or workflow conflict.
- Never optimistically advance a financial state.
- Keep existing screen data visible on failure.

## 10. Permission mapping

| Capability | Permission |
|---|---|
| View | `cscs.view` |
| Upload/reversal | `cscs.upload` |
| Resolve/reconcile | `cscs.reconcile` |
| Submit/cancel | `cscs.submit` |
| Query | `cscs.review` |
| Approve/reject | `cscs.approve` |
| Post/retry | `cscs.post` |
| Export/download | `cscs.export` |
| Mapping/policy administration | `cscs.admin` |

Frontend permission checks improve usability only. The API enforces every permission and state transition.

## 11. Frontend acceptance checklist

1. Upload two files using repeated `files[]` fields.
2. Confirm upload does not change holdings.
3. Review transaction balance and account effects.
4. Resolve an account exception and reconcile.
5. Confirm an unresolved exception blocks reconciliation.
6. Submit as maker; confirm the maker cannot approve or post.
7. Raise/respond to a checker query and resubmit.
8. Complete all configured approval steps with eligible users.
9. Post, poll, and finish at `POSTED`.
10. Verify actual holdings/transactions match the preview.
11. Confirm stale posting is blocked.
12. Retry a controlled posting failure without duplication.
13. Create and approve a reversal.
14. Test pagination, filters, exports, downloads, `403`, `422`, and `429`.

## 12. Suggested build order

```text
Batch list
  -> Upload
  -> Overview/preview
  -> Transactions/exceptions
  -> Resolution/reconciliation
  -> Submission
  -> Checker actions
  -> Posting/polling
  -> Events/approvals/exports
  -> Reversal
  -> Administration
```

Always refresh the batch and use `allowed_actions`. Do not maintain a separate client-side state machine that can drift from the API.
