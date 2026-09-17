# CSCS API handoff for the Figmake flow

This is the integration contract for the implemented API, based on the “Project T CSCS” requirements review. The attachment described a prototype and a plan for a specification; its proposed routes are not the deployed API contract. Use the routes below. The frontend source was not present and has not been modified or browser-tested.

## Rollout and compatibility

- Existing API endpoints and balance fields remain available. No database migration is needed: confirmation, mapping snapshots, proposals and audit context use existing JSON columns.
- **New submissions require financial-preview confirmation by default.** Update the frontend to call `confirm-financial-preview` after the Maker reviews the reconciled preview, before `submit`. Confirmation is invalidated by revalidation; review the refreshed preview again.
- `CSCS_REQUIRE_PREVIEW_CONFIRMATION=false` is a temporary rollout compatibility switch for old clients. With it disabled, explicit user confirmation is not mandatory; enable it after the frontend is integrated. Do not represent that configuration as full confirmation enforcement.
- Previously submitted approvals are not retroactively required to have a confirmation. They still undergo the stronger posting checks. Account-identity comparison is available for batches reconciled using this version; older batches still have register/status/existence checks.
- Rebuild the configuration cache and restart queue workers during deployment using the existing deployment procedure. This change does not deploy automatically.
- Posting is atomic for the batch. A failure rolls back all its ledger writes. Retry the entire batch, not individual debit/credit rows.

## Authentication and base paths

Use the existing Microsoft/Sanctum authentication flow, Bearer token and `GET /api/user` for the current user's ID, roles and permissions. Roles are permission-based and not the prototype's fixed JWT claims. Do not add a second password/JWT authentication flow for this feature.

CSCS base: `/api/cscs`. In the tables below, `{B}` means `/api/cscs/uploads/{batchId}`. All routes require authentication and their configured CSCS permission. The Maker is the uploader; self-approval and self-posting are blocked. Additional approval steps and a separate poster may be required by the approval policy.

## Screen-to-endpoint map

| Screen/action | Endpoint | Integration behavior |
|---|---|---|
| Dashboard list | `GET /api/cscs/uploads` | `status`, `register_id`, `business_reference`, `search`, `page`, `per_page` |
| Dashboard KPI cards | `GET /api/cscs/uploads/summary` | Optional `register_id`; `data.total` and `data.counts` keyed by API workflow status |
| Create/upload/process | `POST /api/cscs/import` | Multipart `register_id`, `files[]` (one or two TXT/CSV files), optional description/business_reference; creates the batch and starts queued processing together; HTTP 202 |
| Processing modal | `GET {B}/process/status` | `data.status`: PROCESSING/DONE/FAILED; `progress`, `draft_review_ready`, `workflow_status`, `error_message` |
| Batch details | `GET {B}` | Metadata, revision, source-file information, workflow relations and allowed actions |
| Reconciliation list | `GET {B}/transactions` | Search; `balance_status=BALANCED/UNBALANCED`, `is_flagged`, security/date/resolution filters and pagination |
| Transaction details | `GET {B}/transactions/{transactionNumber}` | Complete debit/credit legs, sequence numbers, date, security, balance, risk and flags |
| Save review progress | `POST {B}/save-draft` | Optional `selected_transaction`, `selected_exception_id`, `notes`; saves UI review state only, never edits ledger/proposed quantities |
| Exception list | `GET {B}/exceptions` | `severity=CRITICAL/WARNING/INFO`, `status=PENDING/RESOLVED` or native resolution status, exception_code/search/pagination |
| Exception details | `GET {B}/exceptions/{rowId}` | Parsed movement, matched account, allowed/suggested actions and resolution history |
| Shareholder search | `GET /api/shareholders?search=...` | Existing shareholder search; use IDs, not names, for selection |
| Shareholder accounts | `GET /api/cscs/shareholders/{shareholderId}/accounts?register_id=...` | Name, total_accounts, account IDs/identifiers, register, status, per-share-class holdings and `can_map` |
| Resolve exception | `POST {B}/exceptions/{rowId}/resolve` | See resolution contract below |
| Revalidate | `POST {B}/revalidate` | Optional comment; refreshes effects/hash and requires all blocking exceptions to be cleared |
| Financial preview / checker review | `GET {B}/preview` | Batch, account_effects, proposed_new_accounts, security_totals, security_mappings, review_summary, comments and approval_timeline |
| Review transactions | `GET {B}/transactions` | Use the same paginated data as reconciliation; preview provides `transactions_endpoint` |
| Confirm reviewed preview | `POST {B}/confirm-financial-preview` | `confirmed_by_maker: true`, `snapshot_hash`; Maker only, RECONCILED state only |
| Submit | `POST {B}/submit` | Optional comment; requires reconciled, unchanged and confirmed snapshot by default |
| Add/read comments | `POST/GET {B}/comments` | POST `{ "comment": "..." }`; role is derived by the server |
| Query / answer | `POST {B}/query`, `POST {B}/respond-to-query` | See query contract below |
| Reject | `POST {B}/reject` | Required `comment` of 10–1000 characters; prototype's `reason` must map to `comment` |
| Approve | `POST {B}/approve` | Optional comment; advances configured approval steps |
| Posting checklist | `GET {B}/posting-readiness` | `ready`, named `checks`, summary and posting policy |
| Authorize posting | `POST {B}/post` | Optional operational `comment`; all readiness checks enforced before HTTP 202/queueing and again in worker |
| Posting progress | `GET {B}/posting-status` | Workflow status, posted counts, timestamps and failure reason |
| Verification | `GET {B}/verification-summary` | Comparisons, metrics, checks, duration, retry metadata |
| Retry | `POST {B}/retry-posting` | Whole-batch retry from POSTING_FAILED; same permission/readiness gates as posting |
| Reports | `GET {B}/export` or `POST {B}/download-report` | See report matrix below |
| Audit and frozen revisions | `GET {B}/events`, `/approvals`, `/snapshots` | Persisted events, approval decisions and immutable submitted snapshots |
| Single-entry transfer | `POST /api/share-transfers` | Existing `from_shareholder_id`, `to_shareholder_id`, `share_class_id`, `quantity`, optional document_ref/corporate_action_id |
| Transfer history | `GET /api/share-transfers/activity-log` | Paginated committed transfers; optional `status=POSTED`; this endpoint does not represent failed attempts as posted transfers |

The upload form can collect files separately in the browser, then submit them together. There is no requirement to manufacture an empty backend batch before the user supplies files. Do not call the prototype's separate create/files/process routes.

## Preview and confirmation

Recommended sequence:

1. Resolve outstanding exceptions, then call revalidate.
2. Fetch preview and its transaction pages; display the returned `batch.snapshot_hash` and `batch.revision`.
3. On the Maker's explicit confirmation, send:

```json
{ "confirmed_by_maker": true, "snapshot_hash": "<hash actually shown to the Maker>" }
```

4. Submit; never fetch a different hash silently just to retry a rejected confirmation.

`confirmedByMaker` and `snapshotHash` aliases are accepted by CSCS middleware. `pageSize` is accepted as an alias for `per_page`. Explicit snake_case fields take precedence.

Preview includes security totals computed across all READY/POSTED movement rows, not just the visible transaction page. Replay/excluded rows do not inflate financial totals. Account effects include register/name, identifiers, share class, before/debit/credit/after quantities, proposal information and `other_accounts`. Display holdings separately by account, register and share class; do not add another register's holdings to the selected balance.

## Resolution contract

Every resolution requires `reason` (10–1000 characters):

| resolution_type | Other fields | Effect |
|---|---|---|
| MAP_ACCOUNT | register_account_id | Select an account in the batch register; revalidation still checks account status and holdings |
| RULE_EXCLUDED | — | Excludes the entire movement group with audit context |
| CONFIRM_REPLAY | — | Requires a complete two-leg group and a posted fingerprint for every leg |
| CREATE_SHAREHOLDER | profile: full_name, email, phone | Stage a new shareholder/account proposal for a credit leg; existing email/phone matches require selecting the existing shareholder |
| CREATE_ACCOUNT | shareholder_id | Stage an account for an existing shareholder; an existing account in this register must be mapped instead |

Creation actions do not create live shareholders/accounts until approved posting. New accounts cannot fund debits. If the selected identity changes or an account now exists, revalidation/posting requires another review. Proposed identity data is included in the frozen snapshot. Creation can add an oversight approval step according to the active policy.

## Queries, revisions and audit

Query body: `comment`, optional `scope`, `reference_id`, `row_ids[]`, `transaction_numbers[]`.

Scopes: BATCH, TRANSACTION, ROW, ACCOUNT, EXCEPTION, MAPPING. Non-batch scopes require a reference from the current batch. ACCOUNT refers to a proposed register-account ID; MAPPING refers to a security-mapping ID. References to rows/transactions outside the batch are rejected.

A Maker may resolve queried rows while the batch remains QUERY_RAISED. They must then call respond-to-query with a required comment; this advances the revision and returns the batch to DRAFT_REVIEW. Revalidate, review, confirm and resubmit that revision. Rejection remains final for the existing batch; the prototype should not imply a rejected batch can be resubmitted through the query-response route.

New workflow events carry immutable `metadata.revision` and `metadata.actor_role`; `actor_role` is also exposed at the top level. Group resolutions appear in both legs' histories. Historic events without a recorded role remain unknown rather than being assigned a guessed historical role.

## Posting and retry semantics

Both queue acceptance and the worker enforce snapshot integrity, security mappings, account mappings, current opening holdings, replay protection and absence of blocking exceptions. Account snapshots capture owner, register, identifiers and status. The worker locks relevant rows/accounts/positions/security mappings before rechecking and posting.

`verification-summary.data.retry` contains `scope: BATCH`, `can_retry`, `endpoint`, `failure_reason`, `unposted_rows`. A failed atomic batch can legitimately report `failed_rows: 0`; use batch state/retry metadata to show the retry action. Permission checks still apply; `can_retry` describes batch state, not the current user's authorization. A STALE batch must be reconciled and reapproved. Already posted jobs are safe no-ops on redelivery.

The active database unique replay fingerprint remains the final duplicate-write guard. SQLite tests cannot certify MySQL production concurrency behavior; run the normal staging deployment checks before release.

## Notifications

Use `/api/notifications`, `/unread-count`, `POST /{notificationId}/read`, `POST /read-all`, and new `PATCH /read` with `{ "ids": ["uuid", "..."] }`. Selected-ID updates affect only notifications owned by the authenticated user. Existing notification IDs are strings, not numeric IDs.

CSCS events include approval-required/submitted, query-raised, query-responded, approved, rejected, posting-queued, posting-started, posted, posting-failed, stale and additional-approval-required. The new posting-start event indicates worker execution has begun. Channels and recipients continue to use existing notification configuration and approval roles; queue workers must be running for delivery.

## Reports

`POST {B}/download-report` accepts `type` and `format`. It returns a signed URL expiring in ten minutes, `expires_at`, and `requires_authentication: true`. Fetch with Bearer authentication and handle the file as a blob. The signed route also checks CSCS export permission. The existing direct export endpoint remains available.

| Format | Supported report types |
|---|---|
| csv | rows, exceptions, reconciliation, preview, posting, activity |
| pdf | audit, reconciliation |
| xls / xlsx | activity |

Unsupported combinations return validation errors before a download URL is generated. The prototype must offer the supported combinations, rather than assume every report supports every format.

## State and error mapping

| Prototype label | API workflow state(s) |
|---|---|
| DRAFT | DRAFT_REVIEW, RECONCILED (keep their different allowed actions) |
| SUBMITTED_FOR_APPROVAL | PENDING_APPROVAL |
| PENDING_POSTING | APPROVED_AWAITING_POST, POSTING_QUEUED, POSTING (keep their different controls) |
| POSTED | POSTED |
| FAILED | PROCESSING_FAILED, POSTING_FAILED (different recovery paths) |
| QUERY_RAISED / REJECTED | QUERY_RAISED / REJECTED |
| Additional API states | PROCESSING, STALE, CANCELLED |

Do not collapse STALE into an ordinary retryable failure. Do not use balance status as exception resolution status. An unresolved WARNING can still block submission: use `is_blocking`, not severity alone.

CSCS exception responses add `error: { code, message, details }` while retaining existing top-level message/errors. Codes include PRE_POSTING_CHECK_FAILED, SNAPSHOT_HASH_MISMATCH, BATCH_STATE_CONFLICT, SELF_APPROVAL_FORBIDDEN, UNAUTHORIZED, FORBIDDEN, RESOURCE_NOT_FOUND, VALIDATION_ERROR and RATE_LIMITED. Validation/state conflicts retain HTTP 422 for compatibility; do not assume the prototype's proposed 400/409 statuses. List responses retain existing Laravel pagination; principal CSCS lists also expose the additive `pagination` object.

## Optional validation policies

No date window, holdings-age threshold or name-matching policy was specified conclusively in the attachment. Configuration is explicit:

- `CSCS_HOLDINGS_MAX_AGE_HOURS=0`: disabled by default; positive hours require a recent last_updated_at. Quantity equality is always checked.
- `CSCS_TRADE_DATE_MIN` / `CSCS_TRADE_DATE_MAX`: optional YYYY-MM-DD processing boundaries, producing DATE_OUT_OF_RANGE when configured.
- `CSCS_VALIDATE_HOLDER_NAMES=false`: when enabled, compare names after whitespace/case normalization. NAME_MISMATCH requires an audited manual mapping; it does not automatically merge people with similar names.
- Non-active register accounts are blocked by default as ACCOUNT_FROZEN. This does not replace the application's separate caution policy.

These policies should be configured from approved business rules. The sample 24-hour threshold in the prototype is not silently imposed on existing holdings.
