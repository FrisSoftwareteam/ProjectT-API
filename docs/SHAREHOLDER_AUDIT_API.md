# Shareholder audit report

```
GET /api/reports/audit?shareholder_id=123&per_page=100
Authorization: Bearer <token>
```

Requires `reports.audit`. Use the shareholder database ID. Optional filters: `user_id` (admin ID), `role`, `activity_category`, `date_from`, `date_to`, `q`, `shareholder_reference`, and `page`. Read every page to obtain the complete matching result set; the maximum page size is 100. Results sort newest first, with ID as a stable tie breaker.

The existing `data` pagination wrapper is unchanged. Each `data.data` entry includes:

- `id`, `activity_type`, `activity_category`, `created_at`, `date`, `time`.
- `user`: admin ID, name, email. New logs preserve these values and roles at the time of the action; older logs use the current admin record.
- `roles`, `affected_record`, `shareholder_reference`.
- `shareholder_ids`: affected participants, including transfer senders/recipients and merge participants.
- `changes`: entity type and ID, event (`created`, `updated`, `deleted`), affected shareholder IDs, and changed `before` / `after` values.
- `request`: HTTP method, path, route parameters, response status and IP when recorded.
- `metadata`: original action-specific details, such as reasons and transaction references.

Exact JSON ID matching avoids confusing shareholder 123 with 1234. Historical logs can also be linked through existing register accounts, change requests, addresses, mandates, identities, cautions, probates, guardians and dividend records. Arbitrary related-record IDs are not treated as shareholder IDs.

For future successful API writes, Eloquent model events capture shareholder-related changes during the request. Explicit controller logs are enriched with request context and captured changes instead of generating duplicate generic logs. Hidden model fields and password/token/secret fields are excluded from captured snapshots. Existing authentication and audit permission checks remain required.

## Coverage limits

This endpoint reports recorded activity, not a reconstruction of every past action. Before/after snapshots begin after deployment; older logs return an empty `changes` array if none were recorded. Historical relationship lookup requires the related record still to exist and reflects its current relationship. New logs persist affected shareholder IDs so deletion of a related record does not erase that link.

Profile views, failed requests, writes outside the API middleware (including background jobs), and bulk SQL updates that bypass Eloquent events do not gain automatic before/after snapshots. Existing explicit logs remain searchable when their metadata identifies the shareholder. Previously deleted audit logs remain excluded under the existing soft-delete behavior.

## Deployment

Deploy the updated audit controller, API activity middleware, AppServiceProvider, ActivityLogService, and the new ShareholderAuditService together. No database migration is required. Follow the normal application cache refresh and worker restart procedure for the online environment. Local verification does not update the hosted application.

## Role filtering

Both `GET /api/reports/audit?role=Operations` and `GET /api/user-activity-logs?role=Operations` support the same single role-name filter. Use `role`, not `roles` or a numeric role ID. Encode spaces, for example `role=Super%20Admin`. A role that matches no log returns an empty page.

When `metadata.actor.roles` exists, filtering uses that historical role snapshot, including an empty role list. Logs without a role snapshot fall back to the admin's current roles. Pagination links preserve role and other query filters. The two endpoints retain their existing, different response wrappers.

Deploy `UserActivityLog`, `UserActivityLogController`, and `AuditReportController` together for this role-filter correction. The online request URL is needed to confirm which deployed endpoint and parameter the frontend sends.
