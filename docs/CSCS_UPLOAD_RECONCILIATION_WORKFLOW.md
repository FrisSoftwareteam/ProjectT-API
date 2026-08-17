# CSCS Upload, Reconciliation, Approval, and Posting Workflow

**Document status:** Implemented technical baseline; pending business UAT  
**Prepared:** 20 July 2026; implementation updated 22 July 2026  
**Implementation status:** Core staged reconciliation, maker-checker approval, controlled posting, audit, permissions, and tests implemented  
**System:** ProjectT API

## 1. Executive summary

The CSCS upload process must be changed from an immediate-posting importer into a controlled financial workflow. Uploading a file must never directly change shareholder holdings. Uploaded data must first be parsed into staging records, validated, reconciled, previewed by the maker, submitted, reviewed by an independent checker, approved, revalidated against current holdings, and deliberately posted by an authorized user.

The target flow is:

```text
Upload
  -> Parse and validate
  -> Preview proposed effects
  -> Pre-post reconciliation
  -> Resolve every exception
  -> Submit
  -> Checker review
  -> Approve
  -> Await authorized posting
  -> Revalidate the approved snapshot
  -> Post to holdings
  -> Verify and close reconciliation
```

The sample CSCS files show that the movement file is a balanced, two-sided transfer advice. Each transaction number represents a transfer group containing a debit and a credit. The most important pre-post control is therefore to prove that every transaction group is complete and balanced before any shareholder holding is changed.

## 2. Objectives

The workflow must:

1. Give the uploader a complete preview of the proposed effect on shareholder holdings.
2. Prevent any unresolved row from reaching approval or posting.
3. Enforce maker-checker separation.
4. Allow a checker to approve the exact financial effect that will be posted.
5. Detect stale previews when holdings change during review.
6. Prevent the same CSCS movement from being posted more than once.
7. Preserve uploaded files, parsed rows, decisions, approvals, and posting outcomes for audit.
8. Support safe recovery after technical failure without duplicating transactions.
9. Keep aggregate holdings balanced for two-sided CSCS transfers.
10. Provide clear operational and reconciliation reports before and after posting.

## 3. Scope and non-goals

### 3.1 In scope

- CSCS master and movement file upload.
- Fixed-width record parsing.
- Structural, account, security, transaction-pair, and holdings validation.
- New shareholder and register-account creation proposals.
- Proposed before-and-after holding calculations.
- Exception investigation and resolution.
- Maker submission and independent checker approval.
- Optional risk-based Internal Audit or Compliance approval.
- Deliberate posting by an authorized user.
- Post-posting verification, export, notification, and audit.
- Reversal or compensating batches when a posted batch is later found to be wrong.

### 3.2 Not in scope for the first implementation

- A separate KYC approval workflow for shareholders created from the CSCS master file.
- Silently editing CSCS-supplied transaction values.
- Deleting or directly changing posted CSCS transactions.
- Treating internal calculated totals as independent CSCS closing-balance evidence.

## 4. Current implementation baseline

The current application provides endpoints to upload CSCS files, list batches, inspect rows and exceptions, reprocess failed rows, and export batch rows. It stores upload batches and movement-row results and sends administrative notifications.

However, the current importer processes movements immediately during upload. Valid rows update `share_positions` and create `share_transactions` before a maker or checker can inspect the proposed effect. This behavior must be replaced by staging and approval.

Important current limitations include:

- `SEC_CODE` is parsed but is not used to determine the target share class.
- Quantities are calculated using floating-point arithmetic.
- A `TRAN_SEQ=0` row is automatically skipped.
- File processing is synchronous and memory-heavy.
- There are no CSCS-specific automated tests.
- Notifications are part of the upload request and can cause a misleading failure response after data has already been posted.
- The existing replay fingerprint does not include `SEC_CODE`.
- The master parser is based partly on assumptions instead of an official field specification.

## 5. Findings from the supplied sample files

The following files were reviewed locally without modifying or copying them:

- `STANBICmast.txt`
- `STANBICs6.txt`

No personally identifying values are reproduced in this document.

### 5.1 Master file

- 544 records.
- Every data record is 393 characters, excluding the Windows CRLF terminator.
- 544 unique master identifiers.
- Appears to contain shareholder/account profile information.
- No explicit header, trailer, control-total record, or confirmed closing-holding field was found.

### 5.2 Movement file

- 1,204 records.
- Every record is 114 characters, excluding the Windows CRLF terminator.
- All records have trade date 16 June 2026.
- All records have `SEC_CODE` equal to `STANBIC`.
- 544 unique movement identifiers.
- All 544 movement identifiers exist in the master file.
- 602 debit legs and 602 credit legs.
- Total debits are 1,119,728 units.
- Total credits are 1,119,728 units.
- Net aggregate movement is zero.
- 602 unique transaction groups.
- Every transaction group contains exactly two movement legs.
- Every transaction group balances to zero.
- No duplicate transaction-number-plus-sequence key was found.

### 5.3 Provisional movement layout

The sample supports the following provisional layout:

| Character positions | Provisional field |
|---:|---|
| 1-16 | Transaction number |
| 18-23 | Transaction sequence and padding |
| 24-31 | Trade date in `YYYYMMDD` format |
| 32-52 | Security code |
| 53-70 | Quantity |
| 73 | Record/transaction marker |
| 74 | Debit or credit sign |
| 75-114 | CHN/CSCS identifier and padding |

This layout must be confirmed against the official CSCS specification before implementation is considered complete.

### 5.4 Critical sequence-zero finding

The current rule that skips `TRAN_SEQ=0` is unsafe. In the sample, one valid transfer contains:

```text
Sequence 0  -> debit 248,889
Sequence 11 -> credit 248,889
```

The two legs balance. Skipping sequence zero would post only the credit and incorrectly create 248,889 additional units. No sequence should be ignored solely because its value is zero. Transaction-group rules must determine whether a leg is valid.

## 6. Terminology

- **Maker:** User who uploads, investigates, reconciles, and submits the batch.
- **Checker:** Independent user who reviews and approves or rejects the submitted batch.
- **Poster:** Authorized user who deliberately starts posting after approval.
- **Movement leg:** One debit or credit row in the CSCS movement file.
- **Transaction group:** All movement legs with the same CSCS transaction number.
- **Staging:** Persistent storage of parsed data that has no effect on live holdings.
- **Preview snapshot:** Frozen proposed before, movement, and after quantities reviewed by the checker.
- **Resolved exception:** A row or transaction for which a documented final disposition has been made.
- **Replay:** A CSCS transaction leg already received or posted earlier.
- **Stale batch:** An approved or submitted batch whose underlying holdings or mappings have changed since reconciliation.

## 7. Mandatory control principles

The following rules are system invariants:

1. Uploading and parsing must not change live holdings.
2. No unresolved exception may exist when a batch is submitted.
3. The maker must not approve or post their own batch.
4. Submitted data must be immutable.
5. Any material change after submission must invalidate existing approval and require resubmission.
6. The checker must approve a frozen preview snapshot, not a set of source files without calculated effects.
7. Posting must use decimal-safe arithmetic; binary floating-point is prohibited for quantities.
8. Posting must be idempotent and resumable.
9. Every posted leg must link to its source batch, staged row, shareholder account, share class, and share transaction.
10. Posted transactions must never be edited or deleted as a correction mechanism.
11. A stale preview must never be silently recalculated and posted under an old approval.
12. Notification failure must not change or misreport the financial result of an upload, approval, or posting operation.

## 8. Roles, permissions, and segregation of duties

### 8.1 Proposed permissions

- `cscs.upload`: Create a batch and upload files.
- `cscs.reconcile`: Resolve mappings and reconcile a draft batch.
- `cscs.submit`: Submit a reconciled batch.
- `cscs.review`: View submitted batches and raise queries.
- `cscs.approve`: Approve or reject a submitted batch.
- `cscs.post`: Start posting an approved batch.
- `cscs.view`: View permitted batches and rows.
- `cscs.export`: Export preview, reconciliation, and posting reports.
- `cscs.admin`: Maintain security-code mappings and risk rules.

### 8.2 Separation rules

- The maker may upload, reconcile, and submit.
- The checker must be a different user from the maker.
- The poster must be a different user from the maker.
- Whether the checker may also be the poster should be configurable. The recommended initial policy permits it if the user holds both permissions.
- A user may act only on registers and companies within their authorized scope.

### 8.3 Additional approvals

Internal Audit or Compliance approval can be required through configurable risk rules. Possible triggers include:

- Total quantity above a threshold.
- A high number of affected shareholders.
- Backdated or unusually old movements.
- New shareholder/account creation.
- Manual high-risk flag.
- Unusual debit concentration.
- A batch previously rejected or repeatedly queried.

The threshold values and required roles remain configuration decisions.

## 9. Batch state model

```text
UPLOADED
   -> PROCESSING
        -> DRAFT_REVIEW
             -> CANCELLED
             -> PENDING_APPROVAL
                  -> QUERY_RAISED -> DRAFT_REVIEW
                  -> REJECTED
                  -> APPROVED_AWAITING_POST
                       -> STALE -> DRAFT_REVIEW
                       -> POSTING
                            -> POSTED
                            -> POSTING_FAILED
                            -> POSTED_WITH_EXCEPTIONS
```

`POSTED_WITH_EXCEPTIONS` is reserved for technical posting failures after approval, not for unresolved business exceptions. A normal business batch may not be submitted with unresolved rows.

## 10. End-to-end workflow

### 10.1 Upload

The maker selects the register and uploads the master and movement files. The system records:

- Original file names.
- File sizes and MIME/extension results.
- SHA-256 file hashes.
- Uploading user and time.
- Selected register/company.
- Optional business reference and maker note.

File limits, extensions, record encoding, line endings, and expected file combinations must be validated. Files must be stored on private storage with an explicit retention policy.

### 10.2 Parse into staging

Parsing creates staging records only. It must:

- Detect the file type without relying solely on the first line length.
- Preserve the source filename, line number, raw line, and parsed fields.
- Treat invalid dates and malformed rows as row-level validation exceptions.
- Calculate counts and calculated totals using decimal-safe methods.
- Identify headers or trailers when the official specification confirms them.
- Never create a shareholder, account, position, or share transaction during parsing.

### 10.3 Structural validation

The system validates:

- Expected record lengths.
- Required fields and allowed characters.
- Valid dates.
- Positive quantities.
- Recognized debit/credit indicators.
- Valid transaction and sequence formats.
- Duplicate lines inside the file.
- Duplicate file hash against previously uploaded batches.

### 10.4 Master-to-movement validation

Every movement identifier must have exactly one matching master record. Missing or ambiguous identifiers are blocking exceptions.

The master file may propose a new shareholder or register account. Separate KYC approval is not required, but the minimum data needed for a valid ProjectT shareholder record must be present. All proposed creations must be highlighted in the preview.

### 10.5 Security mapping

Every `SEC_CODE` must map to exactly one active register and share class:

```text
CSCS SEC_CODE -> ProjectT register -> ProjectT share class
```

For the sample:

```text
STANBIC -> configured Stanbic register -> configured share class
```

An unknown, duplicate, inactive, or conflicting mapping is a blocking exception. The importer must never select the first available share class as a fallback.

### 10.6 Internal account matching

The matching order should be deterministic and visible in the preview:

1. Existing external-identifier mapping.
2. Existing CHN or CSCS number on the register account.
3. Approved deterministic profile match from the master data.
4. Proposed creation of a new shareholder/register account.

Email and phone alone should not silently overwrite or merge existing shareholders. Ambiguous matches must require an explicit maker resolution.

### 10.7 Transaction-group reconciliation

Rows are grouped by CSCS transaction number. For the supplied S6 format, a valid transfer group must normally have:

- Exactly two legs.
- One debit and one credit.
- Equal quantities.
- The same trade date.
- The same `SEC_CODE`.
- Unique transaction sequences.
- Two successfully resolved internal accounts.

The system calculates and stores group-level debit, credit, and net totals. A group with a missing leg, unequal quantity, conflicting security, duplicate sequence, or unmatched account blocks the batch.

### 10.8 Proposed holdings calculation

For each resolved account and share class:

```text
Proposed closing quantity
  = current internal quantity
  + total staged credits
  - total staged debits
```

The preview must retain:

- Current quantity and its snapshot/version time.
- Total debit.
- Total credit.
- Net movement.
- Proposed closing quantity.
- Source transaction groups.
- Risk and exception flags.

A negative proposed holding is a blocking exception.

### 10.9 Pre-post reconciliation

The batch is reconciled only when all of the following hold:

- Parsed master and movement counts agree with the files.
- Every source row has a final disposition.
- Every movement identifier is resolved.
- Every `SEC_CODE` is mapped.
- Every transaction group is complete and balanced.
- Total batch debits equal total batch credits for the sample transfer format.
- Aggregate net movement is zero.
- No proposed holding is negative.
- All replays have been identified and documented.
- All proposed new accounts are valid.
- There are no unresolved blocking warnings or exceptions.

The supplied files contain no confirmed independent CSCS closing-position total. Therefore, the first implementation can reconcile transaction completeness, pairing, account matching, and proposed internal effects, but it must not claim that internal closing holdings have been independently matched to a CSCS closing-position file.

### 10.10 Maker preview

The maker must see:

- File and batch metadata.
- Master and movement counts.
- Transaction-group counts.
- Total debit, credit, and net movement by security.
- Affected shareholder and account counts.
- Current and proposed aggregate holdings.
- Proposed new shareholders/accounts/identifiers.
- Duplicate/replay findings.
- All exceptions and their resolution status.
- Per-account before, debit, credit, net, and proposed after quantities.
- Per-transaction debit and credit legs.
- Security-code mappings.

The preview and reconciliation report must be exportable.

### 10.11 Exception resolution

Partial unresolved batches are prohibited. Every source row must end in one of these dispositions:

- `READY`: Valid and included in the proposed posting.
- `CONFIRMED_REPLAY`: Verified as previously posted and excluded.
- `RULE_EXCLUDED`: Excluded by a documented and approved CSCS business rule.
- `CANCELLED_WITH_BATCH`: Not posted because the entire batch was cancelled or rejected.

Statuses such as `UNMATCHED`, `INVALID`, `UNKNOWN_SECURITY`, `UNBALANCED`, `INSUFFICIENT_HOLDING`, and `AMBIGUOUS_MATCH` are unresolved and block submission.

Any manual mapping or exclusion must record the actor, time, original value, selected resolution, and mandatory reason.

### 10.12 Submission

Submission is permitted only after successful reconciliation. Submission must:

- Freeze all staged rows and resolutions.
- Freeze security and account mappings.
- Freeze calculated holding effects.
- Create a content/snapshot hash.
- Record the maker, submission time, and reconciliation statement.
- Notify the appropriate checker role.

Any change after submission creates a new revision or returns the batch to draft, clears previous approvals, and requires reconciliation and submission again.

### 10.13 Checker review

The checker reviews the exact frozen preview and can:

- Approve.
- Raise a query against the batch or selected rows/groups.
- Reject with a mandatory reason.

The checker must see the maker identity, file hashes, totals, mappings, exclusions, proposed new accounts, risk flags, and full before-and-after effects. Approval records the checker, role, time, decision, comment, snapshot hash, and approval step.

### 10.14 Query and rejection

A query returns the batch to the maker without deleting the submitted revision. A maker response and any resulting change must be audited. Material changes require full reconciliation and a new submission.

Rejection permanently closes that batch revision. Rejected data remains readable and exportable for audit but cannot be posted.

### 10.15 Approval and posting authorization

Approval must not automatically change holdings. A successfully approved batch enters `APPROVED_AWAITING_POST`.

An authorized poster deliberately starts posting. The posting action records the user, time, approved snapshot hash, and optional operational comment.

### 10.16 Revalidation before posting

Immediately before posting, the system must revalidate:

- The approved snapshot hash is unchanged.
- Security mappings remain active and unchanged.
- Account mappings remain unchanged.
- The movements have not already been posted.
- Current holdings equal the holdings used in the approved preview.
- Proposed debit holdings remain sufficient.

If a holding or mapping has changed, the batch becomes `STALE`. The system recalculates a new preview only after returning it to draft. The batch must then be reconciled, submitted, and approved again.

### 10.17 Posting

Posting should run as a background job. For each movement or account/security aggregate, it must:

1. Obtain the required database locks.
2. Confirm the approved staged row is still eligible.
3. Confirm the replay key is unused.
4. Apply decimal-safe quantity arithmetic.
5. Create the share transaction with a CSCS source reference.
6. Update the share position.
7. Record actual before, delta, and after quantities.
8. Link the staged row to the resulting share transaction.
9. Commit the row/group transaction.

The job must be resumable. Restarting it must not post completed rows twice.

### 10.18 Post-posting verification

After posting, the system verifies:

- Actual posted row count equals the approved ready-row count.
- Actual debit and credit totals equal the approved totals.
- Actual resulting quantities equal the approved proposed quantities.
- Every approved transaction group has all intended legs.
- Aggregate holdings changed only as approved.
- No duplicate transaction was created.

The batch becomes `POSTED` only after verification succeeds. Notifications are then sent to the maker, checker, Reconciliation, and any required oversight roles.

## 11. Replay and uniqueness rules

A CHN or CSCS number identifies an account, not a transaction. It cannot be used alone for replay prevention because one account can participate in many valid transactions.

For the supplied sample:

- Transaction-group identity: CSCS transaction number.
- Movement-leg identity: CSCS transaction number plus transaction sequence.

The defensive replay fingerprint should include:

```text
source/provider
+ transaction number
+ transaction sequence
+ trade date
+ SEC_CODE
+ identifier type and value
+ debit/credit sign
+ exact decimal quantity
```

The database must enforce a suitable unique key for posted CSCS movement legs. File hashes provide an additional duplicate-file warning but must not be the sole transaction replay control.

## 12. Preview and reconciliation views

The user interface/API should support at least these views:

### 12.1 Batch summary

- Current workflow status and active approval step.
- Maker, checker, poster, and timestamps.
- Files and hashes.
- Counts and debit/credit/net totals.
- Security mappings and aggregate proposed effects.
- Exception and warning summary.

### 12.2 Transaction-group view

- Transaction number.
- Both movement legs.
- Debit and credit accounts.
- Quantity, security, date, and sequence.
- Pairing/balance result.
- Resolution and risk flags.

### 12.3 Account-impact view

- Shareholder and register account.
- CHN/CSCS number.
- Register and share class.
- Current quantity.
- Total debit and credit.
- Proposed quantity.
- Staleness indicator.

### 12.4 Exceptions view

- Exception category and severity.
- Source row/group.
- Current resolution status.
- Allowed resolution actions.
- Resolution history and comments.

### 12.5 Approval history

- Submission revisions.
- Queries and responses.
- Approval/rejection actions.
- Roles, actors, timestamps, and comments.
- Snapshot hashes approved at each step.

## 13. Conceptual data changes

The exact schema is an implementation decision, but the design will likely require:

### 13.1 Batch additions

- Workflow status and revision number.
- Maker/submitted-by/approved-by/posted-by identifiers and timestamps.
- Current approval step.
- File and frozen-snapshot hashes.
- Reconciliation totals and reconciliation timestamp.
- Approved and actual posting totals.
- Query/rejection/stale/posting-failure reason.
- Posting job identifiers and retry metadata.

### 13.2 Row additions

- Validation and resolution status.
- Transaction-group key.
- Proposed shareholder, register account, register, and share class.
- Account/security match methods.
- Proposed before, debit/credit delta, and after quantities.
- Approved snapshot values.
- Actual posting values.
- Replay key/fingerprint.
- Exception code, severity, resolution reason, resolver, and time.
- Risk flags.

### 13.3 Supporting records

- CSCS security-code mappings.
- Batch approval actions.
- Batch workflow/audit events.
- Exception-resolution events.
- Optional approval thresholds and role rules.
- Optional batch revisions.

## 14. Implemented API surface

Import now means staging rather than posting. The principal workflow endpoints are:

```text
POST   /api/cscs/import
GET    /api/cscs/uploads
GET    /api/cscs/uploads/{batchId}
GET    /api/cscs/uploads/{batchId}/rows
GET    /api/cscs/uploads/{batchId}/transactions
GET    /api/cscs/uploads/{batchId}/account-effects
GET    /api/cscs/uploads/{batchId}/exceptions
POST   /api/cscs/uploads/{batchId}/reconcile
POST   /api/cscs/uploads/{batchId}/revalidate
POST   /api/cscs/uploads/{batchId}/exceptions/{exceptionId}/resolve
POST   /api/cscs/uploads/{batchId}/submit
POST   /api/cscs/uploads/{batchId}/query
POST   /api/cscs/uploads/{batchId}/respond-to-query
POST   /api/cscs/uploads/{batchId}/approve
POST   /api/cscs/uploads/{batchId}/reject
POST   /api/cscs/uploads/{batchId}/post
POST   /api/cscs/uploads/{batchId}/retry-posting
POST   /api/cscs/uploads/{batchId}/create-reversal
GET    /api/cscs/uploads/{batchId}/export
GET    /api/cscs/uploads/{batchId}/reconciliation
GET    /api/cscs/uploads/{batchId}/events
GET    /api/cscs/uploads/{batchId}/related-batches
```

The full 37-route surface, request bodies, permissions, response states, and deployment requirements are defined in [CSCS_API.md](CSCS_API.md). Draft revalidation and controlled retry of an already approved posting job are separate operations.

## 15. Failure, recovery, and corrections

### 15.1 Parsing or validation failure

- Preserve the batch and files.
- Store row-level errors where possible.
- Allow correction through mapping/resolution or a new upload revision.
- Do not create financial records.

### 15.2 Posting failure

- Roll back the entire financial database transaction; no movement leg from the failed attempt may remain committed.
- Mark the batch/job as failed while leaving staged rows unchanged for controlled retry.
- Keep the approved snapshot immutable.
- Allow an authorized retry against the same snapshot.
- Use idempotency keys so a retry cannot duplicate a leg committed by any prior posting.

### 15.3 Incorrect batch discovered after posting

Posted transactions must not be deleted or edited. The correction process is:

1. Create a linked reversal or compensating CSCS batch.
2. Calculate and preview its effect.
3. Reconcile every correcting transaction.
4. Submit it through the same maker-checker controls.
5. Post it as new transactions linked to the original batch.

## 16. Audit and notification requirements

The audit trail must record:

- Upload, hashes, and source metadata.
- Parsing and validation completion.
- Every mapping and manual resolution.
- Reconciliation completion and totals.
- Submission and frozen snapshot.
- Query, response, rejection, and approval.
- Posting initiation, each retry, and final verification.
- Actor ID, role, timestamp, comment, and relevant before/after values.

Notifications should be sent for:

- Batch ready for maker review.
- Batch submitted for approval.
- Query raised or answered.
- Batch approved or rejected.
- Batch detected as stale.
- Posting started, failed, retried, or completed.
- High-risk threshold requiring additional approval.

Notification delivery should be asynchronous and must not roll back or misreport completed financial work.

## 17. Security and operational controls

- Store source files privately and authorize every download.
- Scope batch visibility by permitted register/company.
- Validate file size, extension, encoding, and content.
- Sanitize stored filenames.
- Cap pagination and export sizes appropriately.
- Avoid exposing raw database/internal errors in API responses.
- Retain raw CSCS rows according to an approved retention policy.
- Protect personally identifying data in logs and notifications.
- Use queued parsing/posting for large files.
- Monitor batches stuck in `PROCESSING` or `POSTING`.
- Provide operational metrics for processing duration, exception rate, stale rate, and posting failures.

## 18. Agreed business decisions

1. No unresolved exception is allowed at submission or approval.
2. Partial unresolved batches are not permitted.
3. Newly created shareholders do not require a separate KYC approval workflow.
4. Internal Audit/Compliance approvals may be wired into role-based risk rules.
5. Approval does not itself change holdings; an authorized posting action follows approval.
6. The checker must review proposed before-and-after holdings.
7. Pre-post reconciliation is mandatory.
8. Post-posting verification is mandatory.
9. CHN/CSCS number is an account identifier, not a unique transaction identifier.
10. Transaction number plus sequence is the sample-supported movement-leg identity.
11. `SEC_CODE` must be authoritatively mapped to a register and share class.
12. Sequence zero must not be skipped automatically.

## 19. Outstanding confirmations

The following must be confirmed before implementation is finalized:

1. Official fixed-width field specifications for both files.
2. The formal CSCS meaning of transaction sequence values, including zero, 1, 2, 11, and 12.
3. The production mapping for `STANBIC` and every other expected `SEC_CODE`.
4. Whether CSCS supplies a separate position/closing-balance report.
5. Whether a file header, trailer, portal report, or separate document supplies official control totals.
6. Minimum master-data fields required to create a new shareholder safely.
7. Risk thresholds and the roles required at each threshold.
8. Whether checker and poster may be the same user.
9. File retention and audit-retention periods.
10. Whether any formally documented movement types may be excluded from posting.

## 20. Acceptance criteria

The target process is acceptable only when automated tests prove at least the following:

1. Uploading valid files does not change holdings.
2. The supplied sample produces 544 master records, 1,204 movement legs, and 602 balanced groups.
3. Sample debit and credit totals both equal 1,119,728.
4. The valid sequence-zero leg is retained and reconciled.
5. A missing leg blocks submission.
6. Unequal debit and credit quantities block submission.
7. An unknown `SEC_CODE` blocks submission.
8. An unmatched or ambiguous account blocks submission.
9. Insufficient debit holding blocks submission.
10. The maker cannot approve or post their own batch.
11. Submitted data cannot be changed without invalidating approval.
12. A holding change after approval marks the batch stale and blocks posting.
13. Re-uploading or retrying cannot duplicate a posted movement leg.
14. Posting uses exact decimal quantities.
15. Actual posted effects match the approved preview.
16. A technical failure rolls back the whole financial transaction and can be retried without duplicating movement legs.
17. All decisions and financial effects are auditable and exportable.
18. Notification failure cannot change the financial status or API result of completed work.

## 21. Recommended implementation phases

### Phase 1: Specification and foundations

- Confirm official file layouts and sequence rules.
- Configure `SEC_CODE` mappings.
- Introduce the workflow states, permissions, audit records, and staging-only import behavior.
- Add sample-based parser and reconciliation tests.

### Phase 2: Preview and reconciliation

- Build transaction grouping and balance validation.
- Build account matching and exception resolution.
- Calculate account/security before-and-after previews.
- Add reconciliation reports and exports.

### Phase 3: Maker-checker workflow

- Add submit, query, response, approve, reject, and role-based notifications.
- Enforce immutability, revisions, segregation, and optional risk approvals.

### Phase 4: Controlled posting

- Add deliberate authorized posting, pre-post staleness validation, decimal-safe updates, idempotency, queueing, retry, and post-posting verification.

### Phase 5: Operational hardening

- Add monitoring, retention, performance controls, security review, UAT, and production runbooks.

---

This document describes the implemented workflow baseline. Production release still requires the outstanding CSCS format confirmations, production security mappings, role assignment, migration, queue-worker setup, and business UAT described above.
