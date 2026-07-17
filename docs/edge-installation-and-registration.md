# Edge installation and registration

Create each edge with `POST /api/admin/edges`. Record the returned bootstrap token immediately; it is stored only as a hash and is shown once. Configure the Go agent with `EDGE_CONTROL_URL`, `EDGE_ID`, `EDGE_BOOTSTRAP_TOKEN`, an empty persistent `EDGE_STATE_DIR`, and the control-plane CA. The agent exchanges the token once at `POST /edge/v1/register`, pins the returned Ed25519 artifact-signing public key, and persists its identity with mode `0600`. Normal requests use the resulting edge identity and production ingress must require its client certificate in addition to the application identity.

Build the bounded non-root agent container with `docker build edge-agent`. In production Compose it has a separate persistent identity/state volume, a read-only root filesystem, 128 MiB memory and 0.25 CPU limits. After the first successful registration, remove `EDGE_BOOTSTRAP_TOKEN` from the environment; restarts load the persisted identity without using it.

Set `EDGE_ARTIFACT_SIGNING_KEY` to a separately backed-up high-entropy value before generating artifacts. Changing it rotates the Ed25519 key and requires controlled edge re-registration or an explicit trust update; never rotate it implicitly during an application deployment.

An edge is routable only while enabled, not drained, listener-ready, and heartbeat-fresh. Give every cell its own memory/process limits, cache volume, and temporary-storage quota. Keep the agent state outside cell volumes so a cell replacement cannot erase the last active snapshot.

To replace an identity, call `POST /api/admin/edges/{edge}/rotate-identity`, install the newly returned one-time token, and register again. The old identity stops authenticating immediately and cannot heartbeat or download artifacts. Do not reuse an identity on another host.

Fresh recovery starts with `GET /edge/v1/config/full`. Verify the pinned Ed25519 signature, SHA-256 checksum, schema and compatible version range, build and locally validate a candidate, then atomically rename it into the active directory. Preserve the previous directory. Post `config/applied` only after validation; post `config/rejected` otherwise. Acknowledgements are retained in a bounded 1,000-entry persistent queue while the control plane is unavailable.
