---
title: Atomic edge runtime generations
description: Durable publication, validation, recovery, retention, and rollback of edge runtime configuration.
---

# Atomic edge runtime generations

This page explains how an edge agent stores and switches the configuration its gateway and cells serve, and what you do if that storage breaks. You do not need to act in normal operation.

The edge agent publishes the gateway and every cell runtime (an OpenResty cell is one bounded web-serving process group on the edge) as one immutable generation. The control plane (Laravel) still owns desired state and signed artifacts. This local layout is derived and can be rebuilt.

## On-disk format

`EDGE_RUNTIME_DIR` must be on one local Linux filesystem that supports atomic rename, symbolic links, and directory `fsync`. The layout is:

```text
runtime/
  generations/
    <revision>-<random-id>/
      manifest.json
      summary.json
      gateway.json
      cell-01.json ... cell-32.json, cell-01.stamp ... cell-32.stamp
  current -> generations/<active-generation>
  previous -> generations/<rollback-generation>
agent state (EDGE_STATE_DIR)/
  state/
    meta.json                 the applied sequence
    domains/<domain-id>.json  one verified artifact payload per domain
```

A generation holds only compiled runtime: one file per cell (up to 32 cells), the gateway map, and a small summary. The desired state the agent keeps for restart and recovery is not inside it. That state is one file per domain.

A change to one domain rewrites that domain's file and the runtime file of the one cell that serves it. Every other domain file is untouched. Every other cell file is carried over from the previous generation as a hardlink, not rewritten.

Each cell file has a matching `.stamp` file. The stamp is the cell's content identity: a SHA-256 of what the cell serves. The cell file carries it as its `generation_id`, with the sequence at which the content last changed.

A cell polls only its few-byte stamp. It decodes its state, which can be tens of megabytes, only when the stamp changes. An idle cell, or a cell whose content did not change, costs almost no CPU however many domains it serves. A cell that finds no stamp loads the file on every tick.

The agent writes the encrypted recovery snapshot at most once a minute. After a restore it catches up from its stored sequence. A restored state can be older than the last change by at most the time since the last snapshot.

Readers use only paths below `current`. The manifest records:

- the schema version, generation ID, revision, and UTC creation time;
- the exact expected file list;
- the byte size and SHA-256 of every file;
- an aggregate SHA-256 over the ordered file metadata.

A missing, extra, symlinked, corrupt, or digest-mismatched file makes the generation invalid. Nobody edits a published generation directory.

## Activation steps

<div class="steps">

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
   replacement. Heartbeat readiness remains false if the gateway reports a different
   generation, or a cell reports an identity other than the stamp of its file in
   the current generation.

</div>

Publishing the active revision again changes nothing. The agent rejects a lower revision. Publication keeps five recent generations plus the active and rollback generations. Cleanup never removes either protected target.

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

If automatic recovery cannot find a valid pointer, follow these steps.

<div class="steps">

1. Stop the edge agent but leave gateway and cells running on their in-memory
   last-valid state.
2. Inspect `generations/*/manifest.json`; do not edit a published generation.
3. Run the edge-agent generation verification test or restore the runtime
   volume from a known-good host backup.
4. Replace `current` only with a relative link to a fully verified directory,
   synchronize the runtime filesystem, and restart the agent.
5. Confirm gateway and all assigned cell status responses show the same
   generation ID and revision before restoring public readiness.

</div>

Structured events distinguish activation start/success/failure, candidate
validation failure, recovery, reader mismatch, cleanup, and rollback. They
contain identities and reason codes, never runtime contents, signatures, or TLS
private keys.
