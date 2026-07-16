# Manual browser qualification

Browser qualification is intentionally performed by the project owner, not by coding agents. Run this checklist after UI, authentication, authorization, or Filament changes. There is no browser-automation dependency or script in the repository.

## Prerequisites

1. Start the development stack with `make dev-up`.
2. Apply migrations with `make dev-migrate`.
3. Ensure an administrator and an unassigned domain-user account exist.

## Checklist

1. Sign in to `/admin` as an administrator.
2. Create a domain user, edit it, disable access, verify login is rejected, then enable access.
3. Update the administrator profile.
4. Create an API token, verify it is shown only once, then revoke it.
5. Sign in to `/app` as the unassigned domain user and verify the empty-domain state.
6. Verify no administrator navigation is visible and directly opening `/admin/users` is forbidden.
7. Check the layout at desktop and narrow mobile widths.

Record the date, commit, browser and version, operator, and pass/fail result when accepting a release. Treat any broken flow, unexpected access, missing state, or layout failure as a failed qualification.
