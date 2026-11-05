---
title: Signing-key rotation
description: Replace the artifact signing key without re-enrolling any PoP, in four steps with checks and a way back.
---

# Signing-key rotation

You will replace `EDGE_ARTIFACT_SIGNING_KEY` without re-enrolling any PoP (point of presence, one edge location). You need shell access to the control node's `.env.prod` and a way to read each edge agent's log.

The control plane signs every artifact, snapshot, and origin policy with this key. A PoP trusts the public key it received when it enrolled. During rotation, the current key endorses the next key first. Both keys are accepted until the next key has signed a delivery.

## Rotate

<div class="steps">

1. Generate a new high-entropy seed. Set it as `EDGE_ARTIFACT_SIGNING_NEXT_KEY` and leave `EDGE_ARTIFACT_SIGNING_KEY` unchanged. Recreate `core`, `web`, `horizon`, and `scheduler`.

   Expected result: the manifest now carries a `key_rotation` notice with the next public key and an endorsement signed by the current key.

2. Wait until every enrolled PoP logs the `signing_key_endorsed` event. Each agent verifies the endorsement with a key it already trusts, then stores the next key.

   Expected result: every agent log shows `signing_key_endorsed`. A PoP that was offline for the whole window cannot verify deliveries signed by the new key. Re-enroll that PoP.

3. Move the new seed to `EDGE_ARTIFACT_SIGNING_KEY` and clear `EDGE_ARTIFACT_SIGNING_NEXT_KEY`. Recreate the same four control services.

4. Wait for each agent to verify one delivery with the new key.

   Expected result: each agent logs `signing_key_rotated`, trusts only the new key from then on, and retires the old one. The agent refuses a delivery signed by the retired key.

</div>

An agent holds at most two endorsed keys beside the trusted one. A key joins the trusted set only through an endorsement signed by a key the agent already trusts, so a response from the control plane cannot add a key on its own.

## If something goes wrong

Keep the previous seed until every PoP has logged `signing_key_rotated`.

To go back, set the previous seed as `EDGE_ARTIFACT_SIGNING_KEY` again and recreate the control services. Agents that have not yet retired the old key accept it. Agents that have retired it need re-enrollment.

The signing key and the next seed belong to the secrets you back up. See [Backup and recovery](backup-and-recovery.md).
