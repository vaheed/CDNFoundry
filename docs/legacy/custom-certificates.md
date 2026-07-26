# Custom TLS certificates

Custom certificates are accepted only for domains with at least one proxied hostname. Upload the leaf certificate, its ordered issuing chain through a self-signed root, and the matching private key. Inputs are bounded to 16 KiB for the leaf and key and 64 KiB for the chain.

Validation rejects unparseable PEM, mismatched keys, invalid chain signatures or ordering, future not-before dates, expired certificates, missing SANs, unsupported keys, and incomplete hostname coverage. Supported keys are RSA 2048–4096 and EC P-256/P-384. A wildcard covers exactly one label.

Private keys use Laravel's encrypted cast in PostgreSQL and are never returned by the API or displayed in Filament. The accepted bundle becomes part of the signed domain revision delivered over the authenticated edge channel. OpenResty selects the certificate dynamically by SNI without a reload and rejects unknown, disabled, expired, or unavailable names before proxying.

Uploading the same still-valid custom certificate reuses its durable row. Replacing it marks the prior custom certificate superseded only after the candidate validates. Removing the custom certificate switches to a valid managed fallback when one exists; otherwise managed mode remains visibly pending. Control-plane or renewal failure never deletes an existing valid certificate from the last active edge state.
