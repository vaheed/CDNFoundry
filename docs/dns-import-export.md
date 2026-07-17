# DNS import and export

Import a BIND-compatible zone with `POST /api/domains/{domain}/dns/import`:

```json
{
  "zone": "$ORIGIN example.test.\n$TTL 3600\n@ IN A 192.0.2.10\nwww IN CNAME example.test.\n",
  "replace_existing": true
}
```

`replace_existing` defaults to `false`, which appends records and validates them with the existing zone. When it is `true`, the imported records atomically replace existing desired records. SOA input is accepted but ignored because CDNFoundry owns serial generation. Unsupported directives and record types are rejected.

The parser supports `$ORIGIN`, `$TTL`, comments, parenthesized multiline records, omitted owners, relative names, and second/minute/hour/day/week TTL suffixes. It supports the record types listed in [DNS record reference](dns-record-reference.md). TXT quoted segments are joined according to zone-file conventions.

Input is bounded to 1 MiB and 5,000 supported records. Imports over 64 KiB or 100 physical lines return `202 Accepted` with an operation ID and run on the bounded bulk-maintenance queue. Smaller imports complete synchronously. A failed import leaves the previous desired zone and revision unchanged; administrators can retry a failed asynchronous operation through the operations API.

Use `GET /api/domains/{domain}/dns/export` to download a deterministic zone file. Export includes `$ORIGIN`, a default TTL directive, and desired records in stable owner/type order. Importing that output with replacement recreates the same desired records. Platform-managed SOA data is deliberately not exported.

Imports require update permission for the domain; exports require view permission. Supply `Idempotency-Key` for import requests when a client may retry the HTTP mutation.
