---
title: Signing-key rotation
description: Replace the artifact signing key without re-enrolling any PoP.
---

# Signing-key rotation

Every artifact, snapshot, and origin policy is signed with `EDGE_ARTIFACT_SIGNING_KEY`.
A PoP trusts the public key it received when it enrolled. Rotation lets you change
the key without re-enrolling a PoP: the current key endorses the next one first, and
both are accepted until the next one has signed a delivery.

## Rotate

1. Generate a new high-entropy seed and set it as `EDGE_ARTIFACT_SIGNING_NEXT_KEY`
   next to the unchanged `EDGE_ARTIFACT_SIGNING_KEY`. Recreate `core`, `web`,
   `horizon`, and `scheduler`.
2. The manifest now carries a `key_rotation` notice: the next public key and an
   endorsement signed by the current key. Each agent verifies the endorsement with a
   key it already trusts and stores the next key. Look for the
   `signing_key_endorsed` event in every agent's log. Wait until every enrolled PoP
   has logged it; a PoP that was offline during the whole window cannot verify
   deliveries signed by the new key and must be re-enrolled.
3. Move the new seed to `EDGE_ARTIFACT_SIGNING_KEY` and clear
   `EDGE_ARTIFACT_SIGNING_NEXT_KEY`. Recreate the control services.
4. The first delivery each agent verifies with the new key proves it. The agent logs
   `signing_key_rotated`, trusts only the new key from then on, and retires the old
   one. A delivery signed by the retired key is refused.

An agent holds at most two endorsed keys beside the trusted one, and a key joins the
trusted set only through an endorsement signed by a key already trusted: a response
from the control plane cannot add a key on its own.

## Recovery

Keep the previous seed until every PoP has logged `signing_key_rotated`. If you must
go back, set the previous seed as `EDGE_ARTIFACT_SIGNING_KEY` again; agents that have
not yet retired it accept it, and agents that have retired it need re-enrollment.
`EDGE_ARTIFACT_SIGNING_KEY` and the next seed belong to the secrets you back up; see
[Backup and recovery](backup-and-recovery.md).
