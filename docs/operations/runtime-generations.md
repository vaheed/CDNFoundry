---
title: Atomic edge runtime generations
description: Durable publication, validation, recovery, retention, and rollback of edge runtime configuration.
---

# Atomic edge runtime generations

The edge agent publishes the gateway and every cell runtime as one immutable
generation. Laravel still owns desired state and signed artifacts; this local
layout is derived and can be rebuilt.

## On-disk format

`EDGE_RUNTIME_DIR` must be one local Linux filesystem that supports atomic
rename, symbolic links, and directory `fsync`:

```text
runtime/
  generations/
    <revision>-<random-id>/
      manifest.json
      state.json
      gateway.json
      active.json
      <pool>.json
      cell-01.json ... cell-32.json
  current -> generations/<active-generation>
  previous -> generations/<rollback-generation>
```

Readers use only paths below `current`. The manifest records schema version,
generation ID, revision, UTC creation time, the exact expected file list, byte
size and SHA-256 for every file, and an aggregate SHA-256 over the ordered file
metadata. Missing, extra, symlinked, corrupt, or digest-mismatched files make a
generation invalid. Published generation directories are never edited.

## Activation state machine

1. The agent downloads and verifies signed control-plane artifacts and compiles
   every gateway, pool, and cell file in a same-filesystem candidate directory.
2. It validates bounds and schemas, creates the manifest, rereads and verifies
   the complete candidate, synchronizes every file, and synchronizes child
   directories.
3. It renames the candidate to its immutable generation name and synchronizes
   `generations/`.
4. It atomically replaces `previous`, then `current`, using relative symlinks
   and synchronizes the runtime root after each replacement.
5. Gateway and cell readers load through `current`, retain their last valid
   in-memory configuration on any read or validation error, and report the
   loaded generation and revision.
6. The agent acknowledges success only after durable publication and pointer
   replacement. Heartbeat readiness remains false if the gateway or a cell
   reports a different generation.

Duplicate publication of the active revision is idempotent. A lower revision
is rejected. Publication keeps five recent generations plus the active and
rollback generations; cleanup never removes either protected target.

## Crash and power-loss guarantee

For a process crash, container restart, host reboot, or power loss at any
durable boundary, `current` resolves to either the prior complete generation or
the new complete generation. It never names the candidate directory and cannot
expose a mix of gateway and cell files. A generation renamed into
`generations/` but not selected is harmless and becomes a retained or prunable
generation. On startup the agent removes abandoned `.candidate-*` directories,
verifies `current`, and falls back atomically to verified `previous` when the
active target is missing or invalid.

This guarantee assumes the runtime directory is not placed on NFS or another
filesystem that does not provide Linux local-filesystem rename and `fsync`
semantics. Hardware that falsely reports completed flushes is outside the
software guarantee.

## Rollback and operator recovery

Normal rollback verifies every manifest entry and digest, then swaps the
complete `current` and `previous` generation pointers. It never reconstructs
files individually. Repeating a completed activation is safe.

If automatic recovery cannot find a valid pointer:

1. Stop the edge agent but leave gateway and cells running on their in-memory
   last-valid state.
2. Inspect `generations/*/manifest.json`; do not edit a published generation.
3. Run the edge-agent generation verification test or restore the runtime
   volume from a known-good host backup.
4. Replace `current` only with a relative link to a fully verified directory,
   synchronize the runtime filesystem, and restart the agent.
5. Confirm gateway and all assigned cell status responses show the same
   generation ID and revision before restoring public readiness.

Structured events distinguish activation start/success/failure, candidate
validation failure, recovery, reader mismatch, cleanup, and rollback. They
contain identities and reason codes, never runtime contents, signatures, or TLS
private keys.
