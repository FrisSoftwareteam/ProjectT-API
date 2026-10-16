# Fidelity CSCS sample

Upload `FIDELITYmast.txt` and `FIDELITYs6.txt` together under the Fidelity Bank Register (register code `87`). The movement file contains security code `FIDELITY`; the register code `87` is not the security code.

Before importing, configure an active CSCS security mapping using `POST /api/cscs/security-mappings`:

```json
{
  "security_code": "FIDELITY",
  "register_id": 1,
  "share_class_id": 1,
  "is_active": true
}
```

The IDs above match the local Fidelity setup. In another environment, use that environment's Fidelity register ID and its Ordinary Shares class ID. Check `GET /api/cscs/security-mappings` first; update an existing mapping instead of creating a duplicate.

`UNKNOWN_SECURITY` means the movement security code has no active mapping to the selected register. A valid sample file still requires this configuration. After correcting the mapping, revalidate the existing batch with `POST /api/cscs/uploads/{batchId}/revalidate` instead of uploading the same files again.

This sample uses synthetic shareholder identifiers. Account or holding exceptions require separate reconciliation before approval and posting.
