"""Names and certificates of the management endpoints (control, Grafana, telemetry).

Fleet tells the operator which DNS names must point where, checks them against a public resolver (1.1.1.1) the way a
certificate authority would see them, and lets the operator supply their own certificates instead of automatic issuance.
The web page and the CLI call the same functions here.
"""
from __future__ import annotations

import datetime as dt
import ipaddress
import random
import socket
import struct
from pathlib import Path
from typing import Any

from .common import ValidationError, atomic_write

RESOLVER = "1.1.1.1"
MANAGEMENT_NAMES = ("control", "grafana", "telemetry")
TLS_DIR = "tls"
EXPIRY_WARNING_DAYS = 14


def hostname(state: dict[str, Any], name: str) -> str:
    if name not in MANAGEMENT_NAMES:
        raise ValidationError(f"Unknown management name {name!r}; use one of {', '.join(MANAGEMENT_NAMES)}")
    return f"{name}.{state['global']['operator_domain']}"


# -- which names point where --------------------------------------------------------------------------------
def dns_records(state: dict[str, Any]) -> tuple[list[dict[str, Any]], list[dict[str, Any]]]:
    """First list: records in the operator domain's DNS. Second: nameserver glue for the platform domain."""
    nodes = state["nodes"]
    operator, platform = state["global"]["operator_domain"], state["global"]["platform_domain"]

    def ips(node: dict[str, Any]) -> list[str]:
        return [a for a in (node.get("public_ipv4"), node.get("public_ipv6")) if a]

    control = next((n for n in sorted(nodes) if nodes[n]["role"] == "control" and nodes[n].get("enabled", True)), None)
    monitoring = state["features"]["monitoring"]
    watcher = control if monitoring["mode"] == "colocated" else (monitoring.get("host") if monitoring["mode"] == "dedicated" else None)
    records: list[dict[str, Any]] = []
    if control:
        records += [
            {"name": f"control.{operator}", "key": "control", "node": control, "ips": ips(nodes[control]), "use": f"Admin panel, domain panel, and API (https://control.{operator}/admin)"},
            {"name": f"edge-control.{operator}", "key": None, "node": control, "ips": ips(nodes[control]), "use": "Edge nodes fetch their configuration here (TCP 8443)"},
        ]
    if watcher and watcher in nodes:
        records += [
            {"name": f"grafana.{operator}", "key": "grafana", "node": watcher, "ips": ips(nodes[watcher]), "use": "Grafana dashboards"},
            {"name": f"telemetry.{operator}", "key": "telemetry", "node": watcher, "ips": ips(nodes[watcher]), "use": "Edges send logs and metrics here (TCP 8444)"},
        ]
    glue: list[dict[str, Any]] = []
    dns_nodes = [n for n in sorted(nodes) if nodes[n]["role"] in {"dns", "dns-edge"} and nodes[n].get("enabled", True)]
    for i, label in enumerate(("ns1", "ns2")):
        if dns_nodes:
            n = dns_nodes[i % len(dns_nodes)]
            glue.append({"name": f"{label}.{platform}", "key": None, "node": n, "ips": ips(nodes[n]), "use": "Public nameserver of the platform"})
    return records, glue


def ha_records(state: dict[str, Any], standby_name: str, standby_address: str) -> list[dict[str, Any]]:
    """What to set in DNS before adding a control standby: both client names at the active node (short TTL), and the standby's own name."""
    nodes = state["nodes"]
    operator = state["global"]["operator_domain"]
    active = next((n for n in sorted(nodes) if nodes[n]["role"] == "control"), None)
    if active is None:
        return []
    ips = [a for a in (nodes[active].get("public_ipv4"), nodes[active].get("public_ipv6")) if a]
    return [
        {"name": f"control.{operator}", "node": active, "ips": ips, "ttl": 60, "use": "Admin panel and API. Points at the ACTIVE node; set TTL 60 so a failover is quick."},
        {"name": f"edge-control.{operator}", "node": active, "ips": ips, "ttl": 60, "use": "Edges fetch configuration here. Points at the ACTIVE node; set TTL 60."},
        {"name": f"{standby_name}.{operator}", "node": standby_name, "ips": [standby_address], "ttl": 300, "use": "The standby host itself. Point it at the standby's public address."},
    ]


# -- a minimal DNS client (UDP, A and AAAA) so the check does not depend on this computer's resolver -------------
def _encode_name(name: str) -> bytes:
    out = b""
    for label in name.rstrip(".").split("."):
        raw = label.encode("idna")
        if not 0 < len(raw) < 64:
            raise ValidationError(f"Invalid DNS name: {name}")
        out += bytes([len(raw)]) + raw
    return out + b"\x00"


def _read_name(data: bytes, offset: int) -> tuple[str, int]:
    labels: list[str] = []
    end = None
    for _ in range(128):
        length = data[offset]
        if length & 0xC0 == 0xC0:
            if end is None:
                end = offset + 2
            offset = ((length & 0x3F) << 8) | data[offset + 1]
            continue
        offset += 1
        if length == 0:
            return ".".join(labels), end if end is not None else offset
        labels.append(data[offset:offset + length].decode("ascii", "replace"))
        offset += length
    raise ValidationError("Malformed DNS answer")


def query(name: str, rtype: str, server: str = RESOLVER, *, timeout: float = 3.0, port: int = 53) -> tuple[str, list[str]]:
    """Returns (status, addresses). Status is NOERROR, NXDOMAIN, SERVFAIL, ... or UNREACHABLE."""
    qtype = {"A": 1, "AAAA": 28}[rtype]
    ident = random.randrange(65536)
    packet = struct.pack("!HHHHHH", ident, 0x0100, 1, 0, 0, 0) + _encode_name(name) + struct.pack("!HH", qtype, 1)
    with socket.socket(socket.AF_INET, socket.SOCK_DGRAM) as sock:
        sock.settimeout(timeout)
        try:
            sock.sendto(packet, (server, port))
            data, _ = sock.recvfrom(4096)
        except OSError:
            return "UNREACHABLE", []
    if len(data) < 12 or struct.unpack("!H", data[:2])[0] != ident:
        return "UNREACHABLE", []
    rcode = data[3] & 0x0F
    status = {0: "NOERROR", 2: "SERVFAIL", 3: "NXDOMAIN", 5: "REFUSED"}.get(rcode, f"RCODE{rcode}")
    questions, answers = struct.unpack("!HH", data[4:8])
    offset = 12
    for _ in range(questions):
        _, offset = _read_name(data, offset)
        offset += 4
    found: list[str] = []
    for _ in range(answers):
        _, offset = _read_name(data, offset)
        atype, _cls, _ttl, length = struct.unpack("!HHIH", data[offset:offset + 10])
        offset += 10
        rdata = data[offset:offset + length]
        offset += length
        if atype == 1 and length == 4:
            found.append(str(ipaddress.IPv4Address(rdata)))
        elif atype == 28 and length == 16:
            found.append(str(ipaddress.IPv6Address(rdata)))
    return status, found


def check_name(name: str, wanted: list[str], server: str = RESOLVER, *, port: int = 53) -> tuple[bool, str]:
    """True when the public resolver returns every wanted address for the name."""
    if not wanted:
        return False, "no address is planned for this host"
    got: list[str] = []
    for rtype, want in (("A", [a for a in wanted if ":" not in a]), ("AAAA", [a for a in wanted if ":" in a])):
        if not want:
            continue
        status, found = query(name, rtype, server, port=port)
        if status == "UNREACHABLE":
            return False, f"Could not ask {server} from this computer (is UDP port 53 blocked?)"
        if status == "NXDOMAIN":
            return False, f"{server} says this name does not exist yet"
        got += found
        if not all(w in found for w in want):
            shown = ", ".join(found) if found else f"no {rtype} record"
            return False, f"{server} sees {shown}; expected {', '.join(want)}"
    return True, f"{server} sees {', '.join(got)}"


# -- certificates the operator supplies ------------------------------------------------------------------------
def _covers(pattern: str, host: str) -> bool:
    pattern, host = pattern.lower().rstrip("."), host.lower().rstrip(".")
    if pattern == host:
        return True
    return pattern.startswith("*.") and host.count(".") == pattern.count(".") and host.endswith(pattern[1:])


def validate_certificate(certificate_pem: str, key_pem: str, hosts: list[str], *, now: dt.datetime | None = None) -> dict[str, Any]:
    """Checks the chain file, the key, that they match, that every host is covered, and the expiry. Raises ValidationError."""
    from cryptography import x509
    from cryptography.hazmat.primitives import serialization

    try:
        chain = x509.load_pem_x509_certificates(certificate_pem.encode())
    except ValueError as exc:
        raise ValidationError("The certificate is not a valid PEM file (paste the full chain, leaf first)") from exc
    if not chain:
        raise ValidationError("The certificate file holds no certificate")
    try:
        key = serialization.load_pem_private_key(key_pem.encode(), password=None)
    except TypeError as exc:
        raise ValidationError("The private key is password-protected; remove the password first") from exc
    except ValueError as exc:
        raise ValidationError("The private key is not a valid unencrypted PEM key") from exc
    leaf = chain[0]
    spki = serialization.Encoding.DER, serialization.PublicFormat.SubjectPublicKeyInfo
    if leaf.public_key().public_bytes(*spki) != key.public_key().public_bytes(*spki):
        raise ValidationError("The private key does not belong to the first certificate in the file")
    try:
        sans = leaf.extensions.get_extension_for_class(x509.SubjectAlternativeName).value.get_values_for_type(x509.DNSName)
    except x509.ExtensionNotFound:
        sans = []
    missing = [h for h in hosts if not any(_covers(s, h) for s in sans)]
    if missing:
        raise ValidationError(f"The certificate does not cover: {', '.join(missing)}. It covers: {', '.join(sans) or 'no names'}")
    now = now or dt.datetime.now(dt.timezone.utc)
    after = getattr(leaf, "not_valid_after_utc", None) or leaf.not_valid_after.replace(tzinfo=dt.timezone.utc)
    before = getattr(leaf, "not_valid_before_utc", None) or leaf.not_valid_before.replace(tzinfo=dt.timezone.utc)
    if after <= now:
        raise ValidationError(f"The certificate expired on {after:%Y-%m-%d}")
    if before > now:
        raise ValidationError(f"The certificate is not valid until {before:%Y-%m-%d}")
    return {"names": sans, "not_after": after.strftime("%Y-%m-%d"), "days_left": (after - now).days, "chain_length": len(chain)}


def tls_dir(state_dir: Path) -> Path:
    return state_dir / TLS_DIR


def save_certificate(state_dir: Path, name: str, certificate_pem: str, key_pem: str) -> None:
    folder = tls_dir(state_dir)
    (folder / f"{name}.local").unlink(missing_ok=True)
    atomic_write(folder / f"{name}.crt", certificate_pem.strip() + "\n", 0o644)
    atomic_write(folder / f"{name}.key", key_pem.strip() + "\n", 0o600)


def remove_certificate(state_dir: Path, name: str) -> bool:
    gone = False
    (tls_dir(state_dir) / f"{name}.local").unlink(missing_ok=True)
    for suffix in ("crt", "key"):
        path = tls_dir(state_dir) / f"{name}.{suffix}"
        if path.exists():
            path.unlink()
            gone = True
    return gone


LOCAL_CA = "local-ca"
LOCAL_DAYS = 365


def generate_local(state_dir: Path, state: dict[str, Any], names: list[str], *, now: dt.datetime | None = None) -> None:
    """Gives each name a certificate signed by a Fleet-local CA, so Caddy serves HTTPS without Let's Encrypt.

    The CA is created once and kept in the vault. Browsers warn until the operator trusts `local-ca.crt` or replaces the
    certificate with a real one.
    """
    from cryptography import x509
    from cryptography.hazmat.primitives import hashes, serialization
    from cryptography.hazmat.primitives.asymmetric import ec
    from cryptography.x509.oid import NameOID

    now = now or dt.datetime.now(dt.timezone.utc)
    folder = tls_dir(state_dir)
    ca_crt, ca_key = folder / f"{LOCAL_CA}.crt", folder / f"{LOCAL_CA}.key"
    if ca_crt.exists() and ca_key.exists():
        ca_cert = x509.load_pem_x509_certificate(ca_crt.read_bytes())
        ca_private = serialization.load_pem_private_key(ca_key.read_bytes(), password=None)
    else:
        ca_private = ec.generate_private_key(ec.SECP256R1())
        subject = x509.Name([x509.NameAttribute(NameOID.COMMON_NAME, "CDNFoundry Fleet local CA")])
        ca_cert = (x509.CertificateBuilder().subject_name(subject).issuer_name(subject).public_key(ca_private.public_key())
                   .serial_number(x509.random_serial_number()).not_valid_before(now - dt.timedelta(minutes=5)).not_valid_after(now + dt.timedelta(days=3650))
                   .add_extension(x509.BasicConstraints(ca=True, path_length=0), critical=True)
                   .add_extension(x509.KeyUsage(digital_signature=True, key_cert_sign=True, crl_sign=True, content_commitment=False, key_encipherment=False,
                                                data_encipherment=False, key_agreement=False, encipher_only=False, decipher_only=False), critical=True)
                   .sign(ca_private, hashes.SHA256()))
        atomic_write(ca_crt, ca_cert.public_bytes(serialization.Encoding.PEM).decode(), 0o644)
        atomic_write(ca_key, ca_private.private_bytes(serialization.Encoding.PEM, serialization.PrivateFormat.PKCS8, serialization.NoEncryption()).decode(), 0o600)
    records, _ = dns_records(state)
    addresses = {r["key"]: r["ips"] for r in records if r["key"]}
    for name in names:
        host = hostname(state, name)
        sans: list[Any] = [x509.DNSName(host)] + [x509.IPAddress(ipaddress.ip_address(a)) for a in addresses.get(name, [])]
        leaf_key = ec.generate_private_key(ec.SECP256R1())
        leaf = (x509.CertificateBuilder().subject_name(x509.Name([x509.NameAttribute(NameOID.COMMON_NAME, host)])).issuer_name(ca_cert.subject)
                .public_key(leaf_key.public_key()).serial_number(x509.random_serial_number()).not_valid_before(now - dt.timedelta(minutes=5))
                .not_valid_after(now + dt.timedelta(days=LOCAL_DAYS)).add_extension(x509.SubjectAlternativeName(sans), critical=False)
                .add_extension(x509.BasicConstraints(ca=False, path_length=None), critical=True)
                .add_extension(x509.ExtendedKeyUsage([x509.oid.ExtendedKeyUsageOID.SERVER_AUTH]), critical=False)
                .sign(ca_private, hashes.SHA256()))
        chain = leaf.public_bytes(serialization.Encoding.PEM).decode() + ca_cert.public_bytes(serialization.Encoding.PEM).decode()
        key_pem = leaf_key.private_bytes(serialization.Encoding.PEM, serialization.PrivateFormat.PKCS8, serialization.NoEncryption()).decode()
        save_certificate(state_dir, name, chain, key_pem)
        atomic_write(folder / f"{name}.local", "generated by Fleet\n", 0o644)


def local_ca_certificate(state_dir: Path) -> str | None:
    path = tls_dir(state_dir) / f"{LOCAL_CA}.crt"
    return path.read_text(encoding="utf-8") if path.exists() else None


def stored(state_dir: Path, state: dict[str, Any]) -> dict[str, dict[str, Any]]:
    """The management names that use an operator certificate, with its names and expiry."""
    out: dict[str, dict[str, Any]] = {}
    for name in MANAGEMENT_NAMES:
        crt, key = tls_dir(state_dir) / f"{name}.crt", tls_dir(state_dir) / f"{name}.key"
        if crt.exists() and key.exists():
            try:
                out[name] = validate_certificate(crt.read_text(encoding="utf-8"), key.read_text(encoding="utf-8"), [hostname(state, name)])
                out[name]["kind"] = "local" if (tls_dir(state_dir) / f"{name}.local").exists() else "own"
            except ValidationError as exc:
                out[name] = {"error": str(exc), "names": [], "not_after": "", "days_left": -1}
    return out


def caddy_files(state_dir: Path, names: list[str]) -> dict[str, tuple[str, int]]:
    """Files for a node's `tls/` directory: certificate, key, and the Caddy snippet that selects them."""
    files: dict[str, tuple[str, int]] = {}
    for name in names:
        crt, key = tls_dir(state_dir) / f"{name}.crt", tls_dir(state_dir) / f"{name}.key"
        if not (crt.exists() and key.exists()):
            continue
        files[f"{name}.crt"] = (crt.read_text(encoding="utf-8"), 0o644)
        files[f"{name}.key"] = (key.read_text(encoding="utf-8"), 0o600)
        files[f"{name}-tls.caddy"] = (f"tls /etc/caddy/own-tls/{name}.crt /etc/caddy/own-tls/{name}.key\n", 0o644)
    return files


def unready(state: dict[str, Any], state_dir: Path, results: dict[str, tuple[bool, str]]) -> list[tuple[str, str]]:
    """(key, reason) for names that would need an automatic certificate but whose DNS does not point at the host yet."""
    own = set(stored(state_dir, state))
    records, _ = dns_records(state)
    return [(r["key"], f"{r['name']}: {results.get(r['name'], (False, 'not checked'))[1]}") for r in records
            if r["key"] and r["key"] not in own and not results.get(r["name"], (False, ""))[0]]
