# ProjectT API Postman Collection

Import `ProjectT-API.postman_collection.json` into Postman.

## Setup

1. Set the collection variable `base_url`, for example `http://localhost:8000`.
2. Run `Authentication > POST - Auth - Simulate Login`.
3. The simulate-login test script automatically stores the returned bearer token in the collection variable `token`.
4. Update ID variables such as `company_id`, `register_id`, `shareholder`, and `declaration_id` to records available in the target environment.

The collection uses inherited Bearer authentication for protected routes. Public authentication routes override it with `noauth`.

## Regeneration

Regenerate the collection after API route changes:

```bash
php scripts/generate_postman_collection.php
```

The generator reads Laravel's registered API routes and produces all method/URI combinations with module grouping, example payloads, query parameters, middleware requirements, and route variables.

## Known Registered But Unimplemented Routes

These routes exist in `routes/api.php`, but their referenced controller methods are currently missing:

- `POST /api/admin/registers/{id}/capital-check`
- `GET /api/admin/registers/{id}/capital-status`
- `POST /api/probates/beneficiaries/{id}/execute`
- `POST /api/share-transactions`

They remain in the collection for completeness and are marked with warnings.

## Notes

- CSCS imports and probate documents use multipart form-data and require selecting local files in Postman.
- Empty `{}` bodies indicate actions with no defined request payload or external integrations whose detailed payload validation is not currently implemented.
- Destructive requests are included but should only be run against appropriate test data.
