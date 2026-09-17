# Shareholder Category API

Shareholder categories preserve the register-specific Estock classification without misusing security share classes. The broad legal type remains on `shareholders.holder_type`; the detailed category is linked through `shareholder_register_accounts.shareholder_category_id`.

All endpoints require Sanctum authentication and the indicated shareholder permission.

## Category endpoints

| Method | Endpoint | Permission | Purpose |
|---|---|---|---|
| `GET` | `/api/shareholder-categories` | `shareholders.view` | Paginated active categories |
| `POST` | `/api/shareholder-categories` | `shareholders.edit` | Create a category |
| `GET` | `/api/shareholder-categories/{id}` | `shareholders.view` | Retrieve a category and usage count |
| `PUT/PATCH` | `/api/shareholder-categories/{id}` | `shareholders.edit` | Update category metadata |
| `DELETE` | `/api/shareholder-categories/{id}` | `shareholders.edit` | Archive an unused category |
| `POST` | `/api/shareholder-categories/{id}/restore` | `shareholders.edit` | Restore an archived category |

List filters include `search`, `default_holder_type`, `include_inactive`, `include_deleted`, and `per_page`.

Example category payload:

```json
{
  "code": "V",
  "name": "Foreign Shareholders",
  "default_holder_type": null,
  "requires_joint_holders": false,
  "requires_review": true,
  "is_active": true,
  "source_system": "ESTOCK"
}
```

`default_holder_type` may be `individual`, `corporate`, or `null`. Null means the category does not decide the legal type and the account must retain an independently reviewed `shareholders.holder_type`.

## Assign a category to a register account

```http
PATCH /api/shareholder-register-accounts/{sraId}/category
Content-Type: application/json

{
  "shareholder_category_id": 1
}
```

Use `null` to clear the category. When a category has a default holder type, the endpoint rejects assignments that conflict with the shareholder’s broad holder type.

The existing register-account creation endpoint now accepts `shareholder_category_id`:

```http
POST /api/shareholders/{shareholderId}/register-accounts
```

CSV shareholder imports may include the optional stable column `shareholder_category_code`. Lowercase Estock codes are normalized to uppercase before validation.

## Seeded Estock categories

The production-safe `ShareholderCategorySeeder` creates codes `A`, `I`, `C`, `D`, `J`, `M`, `N`, `O`, `P`, `Q`, `R`, `S`, `T`, `U`, `V`, `X`, `Y`, and `Z`. Joint categories are flagged, and ambiguous `R` and `V` categories require review.
