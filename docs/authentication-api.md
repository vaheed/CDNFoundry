# Authentication and token API

The generated route contract is [openapi.json](openapi.json). JSON validation failures use `code=validation_failed`; other failures include a stable `code` and HTTP status.

## Login and profile

`POST /api/auth/login` accepts `email`, `password`, and optional `device_name`. It returns a Sanctum bearer token once. Disabled users receive `403 account_disabled`.

- `POST /api/auth/logout` revokes the current token.
- `GET /api/me` returns the profile.
- `PATCH /api/me` changes name/email and audits the mutation.
- `PUT /api/me/password` requires the current password and confirmation, revokes other tokens, and audits the mutation.

Send API tokens as `Authorization: Bearer <token>`. Browser panels use session authentication but the same access rules.

## Personal access tokens

- `GET /api/me/tokens` returns cursor-paginated token metadata, including the final six characters for identification. Tokens created before this metadata was introduced have no suffix.
- `POST /api/me/tokens` creates a named token; plaintext appears only in this response.
- `DELETE /api/me/tokens/{token}` revokes an owned token; foreign identifiers return 404.

Sanctum hashes token secrets at rest. Disabling an account revokes every token and denies existing sessions on their next request. Enabling does not recreate tokens.

## Idempotency

Mutating routes accept an `Idempotency-Key` UUID. A completed JSON response is retained for 24 hours and replayed with `Idempotency-Replayed: true`. Reusing a key for a different method, path, or payload returns `409 idempotency_conflict`. Expired responses are pruned hourly.

Administrators cannot disable, demote, or delete themselves. A user with active API tokens cannot be permanently deleted. Domain assignments add another deletion guard in the domains module.
