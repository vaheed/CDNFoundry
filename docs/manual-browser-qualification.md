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

## Phase 2 authoritative DNS

1. In `/admin`, create or inspect a DNS cluster and run its connection test. Confirm health and failure details are understandable and that the API key is never displayed after saving.
2. Create a domain user and open a domain from the administrator domain list. Assign the user and verify the assignment is shown.
3. Sign in to `/app` as that user. Confirm only assigned domains appear and an unassigned domain URL returns not found.
4. Add a domain without entering an origin. Confirm the required nameserver onboarding state is clear.
5. Request nameserver verification. For release acceptance, use a genuinely delegated public domain and wait for successful automated verification; do not use administrator force-verification as a substitute.
6. Activate the domain and confirm deployment status reaches the displayed desired revision on every enabled DNS cluster.
7. Create, edit, and delete A, AAAA, CNAME, MX, TXT, NS, CAA, SRV, and PTR records. Confirm invalid content, duplicates, and CNAME coexistence are rejected without partial changes.
8. Select and mutate records in bulk. Confirm the table remains usable at desktop and narrow mobile widths.
9. Import a BIND zone in append mode, then in replacement mode. Export it and confirm the downloaded zone can be imported again with the same desired records.
10. Run `dig` through the public DNSdist endpoint and compare A, AAAA, TXT, MX, and SOA answers with the Filament record and deployment views.
11. Disable the domain and confirm desired records remain visible. Start deletion only on a disposable test domain and confirm the delayed-deprovision state is explicit.

Record the date, commit, browser and version, operator, and pass/fail result when accepting a release. Treat any broken flow, unexpected access, missing state, or layout failure as a failed qualification.
