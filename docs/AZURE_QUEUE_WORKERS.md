# Azure Queue Workers (CSCS and Notifications)

## Outcome

CSCS imports and queued notifications run in the background without an SSH session and without keeping the frontend page open. The API only creates the queued work; Azure runs it independently.

This setup does not change API endpoints, response formats, CSCS workflow rules, database tables, or the legacy migration queue.

## Production layout

Two Azure Continuous WebJobs are shipped inside every API deployment:

| WebJob | Queue | Purpose | Worker timeout |
|---|---|---|---:|
| `projectt-cscs-worker` | `cscs` | CSCS import and posting jobs | 3,600 seconds |
| `projectt-notification-worker` | `default` | Email, database, and broadcast notifications | 120 seconds |

The workers are separate so a large CSCS file cannot prevent notifications from being processed. Both jobs use `is_singleton`, which runs one instance even if the App Service scales out. This is the conservative setting for controlled financial processing and can be reviewed later if measured demand requires parallel workers.

Each worker exits cleanly after one hour. Azure Continuous WebJobs automatically starts it again. This allows deployments and configuration changes to be picked up without manual intervention.

## Required Azure configuration

The GitHub deployment workflow automatically:

1. enables App Service **Always On**;
2. sets `CSCS_QUEUE=cscs`;
3. sets `NOTIFICATION_QUEUE=default`;
4. sets `DB_QUEUE_RETRY_AFTER=3900`, which is longer than the maximum 3,600-second CSCS worker timeout; and
5. deploys both jobs under `App_Data/jobs/continuous`.

The deployment does not pre-build Laravel's configuration cache. Azure App Service settings therefore remain the runtime source of truth instead of being replaced by values from the GitHub build machine.

Always On requires an App Service tier that supports it. The deployment deliberately fails if the setting cannot be enabled, because deploying an API whose workers can silently sleep would be incomplete.

The production queue connection must be a real asynchronous driver. The current application default is `database`; do not set `QUEUE_CONNECTION=sync` in production.

## Deployment verification

After the first deployment, open Azure Portal:

1. Go to **App Service > Project-TAPI > WebJobs**.
2. Confirm `projectt-cscs-worker` and `projectt-notification-worker` are present.
3. Confirm both show **Running**.
4. Open each job's logs and confirm there is no repeated startup error.

Then perform these API checks:

1. Submit a small valid CSCS upload.
2. Close the frontend page immediately.
3. Poll the existing batch details endpoint from a new session.
4. Confirm `summary.processing_percent` advances and `workflow_status` leaves `PROCESSING`.
5. Trigger an action that creates an internal notification and confirm the notification appears without running Artisan over SSH.

## Normal operation

No operator should run `php artisan queue:work` for routine uploads or notifications. The frontend may poll to display progress, but polling does not perform the import and closing the browser does not stop it.

Useful operational checks:

- The existing scheduled `cscs:health` command reports stuck CSCS processing.
- Azure WebJob logs show worker startup and job failures.
- Laravel failed jobs remain available through the normal failed-job tooling.

## Rollback

If a worker-specific deployment problem is found, stop the affected WebJob in Azure Portal while leaving the API available, then redeploy the previous known-good application package. Do not start a second manual worker against the same queue while the Continuous WebJob is running.
