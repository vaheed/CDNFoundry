# Geo-DNS records

Geo-DNS is available for `A` and `AAAA` records. It returns user-provided IP targets in this fixed order: country override, continent override, then the required default set. It does not proxy, redirect, block, or select CDN edges.

Create it through the normal DNS record API with `mode: "geo_dns"` and a `geo` object:

```json
{"type":"A","name":"www","ttl":60,"mode":"geo_dns","geo":{"default":["203.0.113.10"],"continents":{"EU":["203.0.113.20"]},"countries":{"IR":["203.0.113.30"]}}}
```

Each target set contains 1–8 unique addresses. A record may contain at most 64 country overrides and all 7 supported continent overrides. Targets must match the record family. One Geo-DNS record must be the only record of its type and owner.

Use `POST /api/domains/{domain}/dns/records/{record}/geo/preview` with `{"ip":"…"}` to preview selection.

## Location and ECS limitations

PowerDNS uses trusted EDNS Client Subnet (ECS) when supplied. Otherwise it locates the recursive resolver, which may differ from the user. Unknown geography, including unsupported IPv6 classification, returns the default set. Runtime evaluation reads the local MMDB and never calls Laravel or an external GeoIP service.

Country keys use uppercase ISO 3166-1 alpha-2 codes. Supported continents are `AF`, `AN`, `AS`, `EU`, `NA`, `OC`, and `SA`. Telemetry must use the same vocabulary.

## Troubleshooting

- Validation failure leaves desired and active state unchanged.
- Preview `source: unknown` means the control-plane cannot read its local MMDB; default answers remain available.
- Failed deployment state is visible while PowerDNS retains the last successful RRsets.
- Check `mmdb-updater` health and `LAST_UPDATE`; failed downloads preserve the previous validated file.
- Confirm PowerDNS has the MMDB mounted, GeoIP loaded, ECS enabled, and zone Lua metadata enabled.
