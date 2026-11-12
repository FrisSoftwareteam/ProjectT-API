# Fidelity CSCS two-transaction test pack

Upload these two files together against **FIDELITY BANK PLC** (production register ID `30`):

- `FIDELITYBK_test_mast.txt`
- `FIDELITYBK_test_s6.txt`

This pack deliberately excludes transaction `2606160005615005`, which depends on missing legacy account `377853`. It tests the CSCS parser, Fidelity security mapping, transaction grouping, matching, reconciliation and approval workflow using only the two production accounts already found:

| Transaction | Quantity | Debit | Credit |
| --- | ---: | --- | --- |
| `2606160005615010` | 1,000 | `RG4125842` / legacy `367705` | `C0618216AD` / legacy `367705` |
| `2606160005615012` | 5,000 | `RG4137963` / legacy `260382` | `C000677962AB` / legacy `260382` |

Expected totals: debit `6,000`; credit `6,000`; net `0`.

Do not upload `Cert.txt`. Do not upload the earlier six-row test pack or the full Daily Advice alongside this pack: the same transaction IDs would be duplicated.

Before uploading, the two existing production accounts must have these identifier links:

| Legacy account | CHN | CSCS account number |
| --- | --- | --- |
| `260382` | `C000677962AB` | `RG4137963` |
| `367705` | `C0618216AD` | `RG4125842` |

After the accompanying release is deployed, an active CSCS administrator can apply only those two verified links without attempting the unrelated `377853` restoration:

```bash
php artisan cscs:repair-fidelity-small --apply --apply-existing-links-only --actor=ADMIN_ID
```

This command does not create an account, position, share lot or share transaction, and does not change any register total.

After staging, reconcile the new batch. The success condition is four movement rows with no unresolved exceptions, balanced debit/credit totals of `6,000`, and batch status `RECONCILED` before the normal maker/checker approval process.
