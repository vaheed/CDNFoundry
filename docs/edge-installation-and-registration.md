# Edge installation and registration

Create each edge with `POST /api/admin/edges`. Record the returned bootstrap token immediately; it is stored only as a hash and is shown once. Configure the agent with the edge UUID, control-plane URL, bootstrap token, an empty persistent state directory, and the control-plane CA. The agent exchanges the token once at `POST /edge/v1/register`. Normal requests use the resulting edge identity and production ingress must require its client certificate in addition to the application identity.

An edge is routable only while enabled, not drained, listener-ready, and heartbeat-fresh. Give every cell its own memory/process limits, cache volume, and temporary-storage quota. Keep the agent state outside cell volumes so a cell replacement cannot erase the last active snapshot.

To replace an identity, call `POST /api/admin/edges/{edge}/rotate-identity`, install the newly returned one-time token, and register again. The old identity stops authenticating immediately and cannot heartbeat or download artifacts. Do not reuse an identity on another host.

Fresh recovery starts with `GET /edge/v1/config/full`. Verify the HMAC signature, checksum, schema and compatible version range, build and locally validate a candidate, then atomically exchange the active-state symlink. Preserve the previous directory. Post `config/applied` only after listener validation; post `config/rejected` otherwise. Queue acknowledgements on bounded persistent disk while the control plane is unavailable.
