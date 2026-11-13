---
title: Notifications
description: Send alerts and domain events to Telegram, Discord, Slack, or a webhook, as an administrator or as a domain user.
---

# Notifications

CDNFoundry sends messages to Telegram, Discord, Slack, and (for administrators) a
generic webhook. One `notifier` container delivers every message, so it is the only
container that needs outbound access to these providers.

**You need:** an administrator account to add platform channels, or a domain-user
account while an administrator has turned domain-user channels on.

## Who receives what

| Channel owner | Receives |
| --- | --- |
| Administrator (platform channel) | Every Alertmanager alert and every domain event for all domains |
| Domain user | The events you pick, for the domains assigned to you only |

A domain user never receives an event for a domain that is not assigned to them. Platform
alerts such as a stale edge heartbeat never reach a domain user.

## Events

| Event | Sent when |
| --- | --- |
| Certificate issued | A managed certificate was issued |
| Certificate issuance failed | An order failed after its retries |
| Certificate expiring | A certificate is inside the expiry alert window |
| Domain verified | The nameservers were verified and the domain became active |
| DNS deployment failed | A zone reconciliation used its whole retry budget |
| Incident profile started or ended | A domain's [incident profile](incident-response.md#incident-profile-on-a-domain) turned on or off |
| Security state changed | The automatic security state of a domain changed |
| Origin errors above threshold | Origin errors reached the platform threshold over five minutes |
| Mitigated requests above threshold | The domain's [security alert](security.md#security-alerts) threshold was reached |
| Log push failing | A [log push](log-push.md) destination failed several deliveries in a row |
| New script or connection seen on a page | [Client-side security](security.md#client-side-security) saw a script or connection after its baseline window |
| Platform alert (administrators only) | Alertmanager sent an alert |

## Add a channel as an administrator

1. Open **Governance, Notifications**.
2. Choose a type, name the channel, and fill its fields. Tokens and webhook URLs are
   stored encrypted and are never shown again.
3. Keep every event, or narrow the list.
4. Choose **Add channel**, then **Send test**. The test is an operation; the result
   appears on the channel.

A generic webhook receives signed JSON. Its signing secret is shown once, when you add
the channel. Your receiver checks the `X-CDNFoundry-Signature` header, which holds
`sha256=` and the HMAC-SHA256 of the body.

The same actions are in the API under `/api/admin/notification-channels`.

## Add a channel as a domain user

An administrator turns this on in **Platform settings, Notifications** ("Domain users can
add notification channels"). It is off by default. When it is on, **Account, Notifications**
appears in your menu. Pick at least one event. When the switch is turned off, your channels
stop sending within one minute and are kept.

The same actions are in the API under `/api/notification-channels`.

## Limits and safety

- **Counts.** Each user may keep 5 channels, and the platform 20 (both are settings).
- **Rate.** A channel sends at most 6 messages a minute. Further events inside the minute
  are merged into one digest message that goes out when the minute ends.
- **Pause.** A channel whose deliveries fail 10 times in a row is paused, and its owner sees
  the last error. Fix the destination, then choose **Resume**.
- **Addresses.** Discord and Slack addresses must use HTTPS on the provider's own host. A
  webhook goes through the same destination checks as an origin: no loopback, private,
  link-local, metadata, or platform address. The address is resolved again and pinned when
  the notifier connects.
- **Nothing on the request path.** Delivery runs on its own `notifications` queue lane, so a
  slow or failing provider never delays another lane, and no alert is stored: only channel
  settings are saved.

## How alerts get there

Alertmanager groups alerts and posts each group to a private receiver in the control plane,
authenticated with the monitoring token. The receiver answers `404` without it. It reads no
database rows and writes none: it queues one message for the platform channels. The always
firing `Watchdog` alert is recorded as a heartbeat and never sent. See
[monitoring](../operations/monitoring.md#alert-delivery).
