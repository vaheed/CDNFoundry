"""Versioned request corpora for the managed WAF baseline.

Three corpora feed the baseline harness (``waf_baseline.py``):

- the OWASP CRS project's own regression tests, read from a checkout of the pinned commit;
- CDNFoundry evasion cases that concern this runtime (``fixtures/waf-evasion-corpus.json``);
- a clean corpus of at least 10,000 synthetic requests, generated deterministically from a seed.

Everything is synthetic. No request is recorded from a live system or built from customer data. The generator is deterministic, so
the corpus is identified by a digest instead of being stored: the same version and seed always give the same bytes.
"""

from __future__ import annotations

import base64
import hashlib
import json
import pathlib
import random
import re
import subprocess
from dataclasses import dataclass, field

ROOT = pathlib.Path(__file__).resolve().parents[2]
EVASION_FILE = pathlib.Path(__file__).resolve().parent / "fixtures" / "waf-evasion-corpus.json"
RUNTIME_PINS = ROOT / "core" / "resources" / "contracts" / "runtime-pins.json"
CRS_REPOSITORY = "https://github.com/coreruleset/coreruleset"

CLEAN_CORPUS_VERSION = "clean-1"
CLEAN_CORPUS_SEED = 20261125
CLEAN_CLASS_SIZES = {
    "browser_page_load": 3000,
    "form_post": 1500,
    "json_api": 2500,
    "file_upload": 600,
    "mobile_api": 1200,
    "webhook": 700,
    "crawler": 800,
}
MINIMUM_CLEAN_REQUESTS = 10000


@dataclass
class CorpusRequest:
    """One request. ``raw`` is a complete HTTP/1.1 request with a placeholder Host and no client-address header, used by CRS stages."""

    identifier: str
    corpus: str
    category: str
    method: str = "GET"
    path: str = "/"
    headers: list[tuple[str, str]] = field(default_factory=list)
    body: bytes = b""
    transport: str = "http1"  # http1, chunked, h2, h2c, raw
    raw: bytes | None = None
    expect: dict[str, str] = field(default_factory=dict)
    paranoia_floor: int = 1
    note: str = ""
    chunk_size: int = 0


def pinned_crs_commit() -> str:
    return json.loads(RUNTIME_PINS.read_text())["crs"]


def checkout_crs(directory: pathlib.Path, commit: str | None = None) -> pathlib.Path:
    """Fetch the pinned CRS commit into ``directory`` (once) and verify it is exactly that commit."""
    commit = commit or pinned_crs_commit()
    if not (directory / ".git").exists():
        directory.mkdir(parents=True, exist_ok=True)
        subprocess.run(["git", "init", "-q", str(directory)], check=True)
        subprocess.run(["git", "-C", str(directory), "fetch", "-q", "--depth", "1", CRS_REPOSITORY, commit], check=True, timeout=600)
        subprocess.run(["git", "-C", str(directory), "checkout", "-q", "FETCH_HEAD"], check=True)
    head = subprocess.run(["git", "-C", str(directory), "rev-parse", "HEAD"], check=True, capture_output=True, text=True).stdout.strip()
    if head != commit:
        raise RuntimeError(f"CRS checkout is {head}, the pinned commit is {commit}")
    return directory


def rule_paranoia_levels(crs: pathlib.Path) -> dict[int, int]:
    """Paranoia level of each CRS rule id, from the paranoia-level tag (a rule without one runs at level 1)."""
    levels: dict[int, int] = {}
    for rules_file in sorted((crs / "rules").glob("*.conf")):
        block: list[str] = []

        def flush() -> None:
            text = "\n".join(block)
            match = re.search(r"\bid:(\d+)", text)
            if match:
                tag = re.search(r"paranoia-level/(\d)", text)
                levels[int(match.group(1))] = int(tag.group(1)) if tag else 1

        for line in rules_file.read_text().splitlines():
            if re.match(r"\s*(SecRule|SecAction|SecMarker)\b", line):
                flush()
                block = []
            block.append(line)
        flush()
    return levels


def _crs_headers(headers: dict | None) -> list[tuple[str, str]]:
    return [(str(name), str(value)) for name, value in (headers or {}).items()]


def crs_regression_requests(crs: pathlib.Path) -> tuple[list[CorpusRequest], dict[str, int]]:
    """Every request stage of the CRS regression tests, with the counted reasons for the ones that cannot be replayed."""
    import yaml

    levels = rule_paranoia_levels(crs)
    requests: list[CorpusRequest] = []
    skipped: dict[str, int] = {}
    total = 0
    for path in sorted((crs / "tests" / "regression" / "tests").rglob("*.yaml")):
        document = yaml.safe_load(path.read_text()) or {}
        if not isinstance(document, dict) or "tests" not in document:
            continue
        rule_id = document.get("rule_id")
        for test in document.get("tests") or []:
            test_id = test.get("test_id", test.get("test_title", "?"))
            for index, stage in enumerate(test.get("stages") or []):
                total += 1
                incoming = stage.get("input") or {}
                output = stage.get("output") or {}
                log = output.get("log") or {}
                expected = [int(value) for value in (log.get("expect_ids") or [])]
                if output.get("expect_error"):
                    skipped["expects a connection error"] = skipped.get("expects a connection error", 0) + 1
                    continue
                if any(950 <= identifier // 1000 <= 999 for identifier in expected):
                    skipped["response rule (the stub origin sends no leaking response)"] = skipped.get(
                        "response rule (the stub origin sends no leaking response)", 0) + 1
                    continue
                if incoming.get("stop_magic") or incoming.get("raw_request"):
                    skipped["raw bytes the harness cannot frame"] = skipped.get("raw bytes the harness cannot frame", 0) + 1
                    continue
                category = "negative" if not expected else str(expected[0] // 1000)
                identifier = f"crs:{rule_id}-{test_id}-{index + 1}"
                floor = min((levels.get(value, 1) for value in expected), default=1)
                if incoming.get("encoded_request"):
                    try:
                        raw = base64.b64decode(incoming["encoded_request"], validate=False)
                    except ValueError:
                        skipped["undecodable encoded request"] = skipped.get("undecodable encoded request", 0) + 1
                        continue
                    requests.append(CorpusRequest(identifier, "crs", category, transport="raw", raw=raw, paranoia_floor=floor))
                    continue
                data = incoming.get("data")
                if isinstance(data, list):
                    data = "".join(str(part) for part in data)
                body = str(data).encode("utf-8", "surrogateescape") if data is not None else b""
                requests.append(CorpusRequest(
                    identifier, "crs", category, method=str(incoming.get("method") or "GET"), path=str(incoming.get("uri") or "/"),
                    headers=_crs_headers(incoming.get("headers")), body=body, paranoia_floor=floor,
                    transport="raw" if incoming.get("version") not in (None, "HTTP/1.1") else "http1",
                ))
    for request in requests:
        if request.transport == "raw" and request.raw is None:
            request.raw = _raw_request(request, version=None)
    skipped_total = sum(skipped.values())
    if total != len(requests) + skipped_total:
        raise RuntimeError(f"CRS stage count {total} does not equal {len(requests)} replayed plus {skipped_total} skipped")
    return requests, {"stages": total, "replayed": len(requests), **{f"skipped: {reason}": count for reason, count in sorted(skipped.items())}}


def _raw_request(request: CorpusRequest, version: str | None) -> bytes:
    headers = [(name, value) for name, value in request.headers if name.lower() not in ("host", "x-forwarded-for")]
    names = {name.lower() for name, _ in headers}
    if request.body and "content-length" not in names and "transfer-encoding" not in names:
        headers.append(("Content-Length", str(len(request.body))))
    head = f"{request.method} {request.path} {version or 'HTTP/1.1'}\r\n"
    head += "".join(f"{name}: {value}\r\n" for name, value in headers)
    return head.encode("utf-8", "surrogateescape") + b"\r\n" + request.body


def with_host_and_client(request: CorpusRequest, host: str, client: str) -> bytes:
    """The request as bytes for one host and one client address, always asking the cell to close the connection."""
    if request.raw is not None:
        head, separator, body = request.raw.partition(b"\r\n\r\n")
        lines = head.split(b"\r\n")
        kept = [lines[0]] + [line for line in lines[1:] if not re.match(rb"(?i)(host|x-forwarded-for|connection):", line)]
        kept += [f"Host: {host}".encode(), f"X-Forwarded-For: {client}".encode(), b"Connection: close"]
        return b"\r\n".join(kept) + b"\r\n\r\n" + body
    headers = [(name, value) for name, value in request.headers if name.lower() not in ("host", "x-forwarded-for", "connection")]
    headers = [("Host", host), ("X-Forwarded-For", client), ("Connection", "close"), *headers]
    names = {name.lower() for name, _ in headers}
    chunked = request.transport == "chunked"
    body = request.body
    if chunked:
        headers = [pair for pair in headers if pair[0].lower() not in ("content-length", "transfer-encoding")]
        headers.append(("Transfer-Encoding", "chunked"))
        body = chunk_body(request.body, request.chunk_size)
    elif body and "content-length" not in names:
        headers.append(("Content-Length", str(len(body))))
    head = f"{request.method} {request.path} HTTP/1.1\r\n" + "".join(f"{name}: {value}\r\n" for name, value in headers)
    return head.encode("utf-8", "surrogateescape") + b"\r\n" + body


def chunk_body(body: bytes, size: int = 0) -> bytes:
    """HTTP/1.1 chunked framing. ``size`` 0 sends one chunk; a positive size splits the payload into chunks of that many bytes."""
    if not body:
        return b"0\r\n\r\n"
    step = size if size > 0 else len(body)
    framed = b""
    for offset in range(0, len(body), step):
        piece = body[offset:offset + step]
        framed += f"{len(piece):x}\r\n".encode() + piece + b"\r\n"
    return framed + b"0\r\n\r\n"


def evasion_corpus() -> tuple[list[CorpusRequest], dict]:
    document = json.loads(EVASION_FILE.read_text())
    requests: list[CorpusRequest] = []
    for entry in document["cases"]:
        body = entry.get("body", "").encode("utf-8")
        if "body_base64" in entry:
            body = base64.b64decode(entry["body_base64"])
        if "body_repeat" in entry:
            body = entry["body_repeat"]["unit"].encode() * entry["body_repeat"]["count"]
        body = entry.get("body_prefix", "").encode("utf-8") + body + entry.get("body_suffix", "").encode("utf-8")
        path = entry.get("path", "/")
        if "path_repeat" in entry:
            path = entry["path_repeat"]["prefix"] + entry["path_repeat"]["unit"] * entry["path_repeat"]["count"]
        headers = [(name, value) for name, value in entry.get("headers", {}).items()]
        if "header_repeat" in entry:
            spec = entry["header_repeat"]
            headers += [(f"{spec['name']}-{index}", spec["unit"] * spec.get("size", 1)) for index in range(spec["count"])]
        if "header_value_repeat" in entry:
            spec = entry["header_value_repeat"]
            headers.append((spec["name"], spec["unit"] * spec["count"]))
        request = CorpusRequest(entry["id"], "evasion", entry["class"], method=entry.get("method", "GET"), path=path, headers=headers,
                                body=body, transport=entry.get("transport", "http1"), expect=entry.get("expect", {}),
                                paranoia_floor=entry.get("paranoia_floor", 1), note=entry.get("note", ""))
        request.chunk_size = int(entry.get("chunk_size", 0))
        requests.append(request)
    return requests, {"version": document["version"], "cases": len(requests)}


# --- clean corpus ---------------------------------------------------------------------------------------------------------------

FIRST = ["Alice", "Bahar", "Carlos", "Dina", "Emre", "Farid", "Grace", "Hamid", "Ines", "Jonas", "Kaveh", "Lena", "Mina", "Nils", "Omid",
         "Pooya", "Quinn", "Rana", "Sara", "Tomas", "Uma", "Vera", "Wei", "Xenia", "Yara", "Zane"]
LAST = ["Ahmadi", "Berg", "Costa", "Dubois", "Eriksen", "Farahani", "Garcia", "Hosseini", "Ito", "Jensen", "Karimi", "Lopez", "Meyer",
        "Nouri", "Okafor", "Petrov", "Rossi", "Sato", "Tanaka", "Usman", "Vogel", "Wagner", "Yilmaz", "Zhang"]
CITIES = ["Tehran", "Berlin", "Lisbon", "Osaka", "Toronto", "Nairobi", "Oslo", "Lima", "Hanoi", "Cairo", "Dublin", "Madrid", "Prague"]
WORDS = ("order delivery invoice account profile settings meeting schedule report summary payment refund shipping address product review "
         "catalog category article tutorial support ticket project milestone upgrade release notes weather recipe travel hotel booking "
         "flight guide question answer comment reply thanks please garden coffee music football camera laptop hiking recipe museum").split()
# Words and sentences that sit close to attack signatures. They are the hard negatives: legitimate text a rule set may still flag.
# They are reported on their own, so the ordinary false positive rate is not inflated by them and they are not hidden.
HARD_WORDS = "select union drop insert delete update exec eval script alert onload system cmd".split()
HARD_WORD_PATTERN = r"\b(" + "|".join(HARD_WORDS) + r")\b"
HARD_SENTENCES = [
    "The meeting notes are in the shared folder; drop me a line if something is missing.",
    "Could you update my shipping address before the order leaves the warehouse?",
    "Please don't delete my draft; select the second version and drop the first one.",
    "The script that imports the catalog takes 20 minutes; can we run it from cron instead of /bin/sh?",
    "Use the onload event of the page to set the focus; an alert box would be too intrusive.",
    "Why does the report say 'where is my order' for 3 of 4 customers? The from-address looks fine.",
]
SENTENCES = [
    "I chose the blue one, but the confirmation email says green. Please check.",
    "Where can I find the invoice for last month? It is under billing, right?",
    "Please keep my draft, I am still editing the introduction and it is nearly done.",
    "Our team needs a new account owner; the current one left the company.",
    "Thanks for the quick delivery, the package arrived on Monday and everything was fine.",
    "Could you change my shipping address before the order leaves the warehouse?",
    "The meeting notes are in the shared folder; send me a message if something is missing.",
    "Release notes for 4.2: fixed the sorting of the product list and the broken pagination.",
    "Great article! The part about joining two lists was clearer than my textbook.",
    "Is it fine to use bold text in a comment, or does the editor remove formatting?",
    "Looking for a hotel near the station, 2 nights, from 12/03 to 14/03, under $120.",
]
PATHS = ["/", "/about", "/pricing", "/blog", "/blog/how-we-cache-static-assets", "/docs/getting-started", "/products", "/products/42",
         "/category/shoes", "/search", "/cart", "/checkout", "/account", "/account/orders", "/contact", "/help/faq", "/news/2026/11/launch"]
STATIC = ["/static/app.3f9a1c.js", "/static/app.8b2d4e.css", "/static/logo.svg", "/static/fonts/inter.woff2", "/images/hero-1.jpg", "/favicon.ico"]
BROWSERS = [
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36",
    "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Safari/605.1.15",
    "Mozilla/5.0 (X11; Linux x86_64; rv:132.0) Gecko/20100101 Firefox/132.0",
    "Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Mobile/15E148 Safari/604.1",
    "Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Mobile Safari/537.36",
]
MOBILE_AGENTS = ["okhttp/4.12.0", "Dalvik/2.1.0 (Linux; U; Android 14; Pixel 8 Build/AP2A.241005.015)", "ShopApp/5.3.1 (iPhone; iOS 18.1; Scale/3.00)",
                 "CFNetwork/1568.200.51 Darwin/24.1.0", "ride-driver/2.9.0 (Android 13)"]
CRAWLERS = ["Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)", "Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)",
            "Mozilla/5.0 (compatible; DuckDuckBot/1.1; +http://duckduckgo.com/duckduckbot.html)", "Mozilla/5.0 (compatible; Applebot/0.1; +http://www.apple.com/go/applebot)",
            "Mozilla/5.0 (compatible; UptimeRobot/2.0; http://www.uptimerobot.com/)", "Pingdom.com_bot_version_1.4_(http://www.pingdom.com/)"]
BROWSER_HEADERS = [("Accept", "text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8"),
                   ("Accept-Language", "en-US,en;q=0.9"), ("Accept-Encoding", "gzip, deflate, br"), ("Upgrade-Insecure-Requests", "1")]


HARD_SHARE = 0.06


def _identifier(kind: str, index: int, hard: bool) -> str:
    """Hard negatives carry their own identifier prefix, so every result can be split without a second lookup."""
    return f"clean-hard:{kind}:{index}" if hard else f"clean:{kind}:{index}"


def _hard(rng: random.Random) -> bool:
    return rng.random() < HARD_SHARE


def _text(rng: random.Random, sentences: int = 1, hard: bool = False) -> str:
    pool = SENTENCES + HARD_SENTENCES if hard else SENTENCES
    chosen = [rng.choice(pool) for _ in range(sentences)]
    if hard and not any(sentence in HARD_SENTENCES for sentence in chosen):
        chosen[0] = rng.choice(HARD_SENTENCES)
    return " ".join(chosen)


def _words(rng: random.Random, count: int, hard: bool = False) -> str:
    chosen = [rng.choice(WORDS) for _ in range(count)]
    if hard:
        chosen[0] = rng.choice(HARD_WORDS)
    return " ".join(chosen)


def _name(rng: random.Random) -> str:
    return f"{rng.choice(FIRST)} {rng.choice(LAST)}"


def _email(rng: random.Random) -> str:
    return f"{rng.choice(FIRST).lower()}.{rng.choice(LAST).lower()}{rng.randint(1, 999)}@example.com"


def _urlencode(pairs: list[tuple[str, str]]) -> str:
    from urllib.parse import urlencode
    return urlencode(pairs)


def _browser(rng: random.Random, index: int, hard: bool = False) -> CorpusRequest:
    kind = rng.random()
    if kind < 0.45:
        path = rng.choice(PATHS)
        if rng.random() < 0.35:
            path += "?" + _urlencode([("utm_source", rng.choice(["newsletter", "twitter", "partner"])), ("utm_campaign", f"autumn-{rng.randint(1, 20)}")])
    elif kind < 0.65:
        path = "/search?" + _urlencode([("q", _words(rng, rng.randint(1, 4), hard)), ("page", str(rng.randint(1, 9)))])
    elif kind < 0.85:
        path = f"{rng.choice(['/products', '/blog', '/news'])}/{rng.randint(1, 5000)}?" + _urlencode([("sort", rng.choice(["price", "date", "name"])), ("dir", rng.choice(["asc", "desc"]))])
    else:
        path = rng.choice(STATIC)
    headers = [("User-Agent", rng.choice(BROWSERS)), *BROWSER_HEADERS]
    if rng.random() < 0.6:
        headers.append(("Cookie", f"session={rng.getrandbits(96):024x}; theme={rng.choice(['dark', 'light'])}; consent=1"))
    if rng.random() < 0.5:
        headers.append(("Referer", f"https://www.example.com{rng.choice(PATHS)}"))
    return CorpusRequest(_identifier("browser_page_load", index, hard), "clean", "browser_page_load", path=path, headers=headers)


def _form(rng: random.Random, index: int, hard: bool = False) -> CorpusRequest:
    form = rng.choice(["contact", "signup", "address", "comment", "search"])
    if form == "contact":
        path, pairs = "/contact", [("name", _name(rng)), ("email", _email(rng)), ("subject", rng.choice(["Question", "Feedback", "Invoice"])), ("message", _text(rng, rng.randint(1, 3), hard))]
    elif form == "signup":
        path, pairs = "/signup", [("username", f"{rng.choice(FIRST).lower()}{rng.randint(1, 9999)}"), ("email", _email(rng)), ("password", f"pw-{rng.getrandbits(64):016x}!"), ("terms", "on")]
    elif form == "address":
        path, pairs = "/account/address", [("name", _name(rng)), ("street", f"{rng.randint(1, 300)} {rng.choice(LAST)} Street"), ("city", rng.choice(CITIES)), ("postal", str(rng.randint(10000, 99999))), ("country", rng.choice(["DE", "IR", "PT", "JP", "CA"]))]
    elif form == "comment":
        path, pairs = f"/blog/{rng.randint(1, 900)}/comments", [("author", _name(rng)), ("comment", _text(rng, rng.randint(1, 4), hard))]
    else:
        path, pairs = "/search", [("q", _words(rng, rng.randint(1, 5), hard))]
    headers = [("User-Agent", rng.choice(BROWSERS)), ("Content-Type", "application/x-www-form-urlencoded"), ("Origin", "https://www.example.com"),
               ("Referer", f"https://www.example.com{path}"), ("Accept", "text/html,application/xhtml+xml")]
    return CorpusRequest(_identifier("form_post", index, hard), "clean", "form_post", method="POST", path=path, headers=headers, body=_urlencode(pairs).encode())


def _json_document(rng: random.Random, depth: int = 0) -> object:
    kind = rng.random()
    if depth < 2 and kind < 0.3:
        return {rng.choice(["id", "name", "status", "items", "filters", "meta", "options"]) + str(rng.randint(0, 3)): _json_document(rng, depth + 1) for _ in range(rng.randint(1, 4))}
    if depth < 2 and kind < 0.45:
        return [_json_document(rng, depth + 1) for _ in range(rng.randint(1, 4))]
    return rng.choice([rng.randint(0, 100000), round(rng.random() * 100, 2), rng.random() < 0.5, None, _name(rng), _email(rng), rng.choice(SENTENCES), rng.choice(CITIES)])


def _json_api(rng: random.Random, index: int, hard: bool = False) -> CorpusRequest:
    kind = rng.random()
    headers = [("User-Agent", rng.choice(BROWSERS)), ("Accept", "application/json"), ("Authorization", f"Bearer {rng.getrandbits(128):032x}")]
    if kind < 0.35:
        path = f"/api/v1/{rng.choice(['orders', 'products', 'users', 'invoices'])}?" + _urlencode([("limit", str(rng.choice([10, 20, 50]))), ("cursor", f"{rng.getrandbits(48):012x}"), ("filter[status]", rng.choice(["open", "paid", "shipped"]))])
        return CorpusRequest(_identifier("json_api", index, hard), "clean", "json_api", path=path, headers=headers)
    method = rng.choice(["POST", "POST", "PUT", "PATCH", "DELETE"])
    if method == "DELETE":
        return CorpusRequest(_identifier("json_api", index, hard), "clean", "json_api", method="DELETE",
                             path=f"/api/v1/{rng.choice(['orders', 'products', 'users', 'invoices'])}/{rng.randint(1, 99999)}", headers=headers)
    body = json.dumps({"data": _json_document(rng), "note": _text(rng, 1, hard), "request_id": f"{rng.getrandbits(64):016x}"}, ensure_ascii=rng.random() < 0.5).encode()
    request = CorpusRequest(_identifier("json_api", index, hard), "clean", "json_api", method=method, path=f"/api/v1/{rng.choice(['orders', 'products', 'users', 'invoices'])}/{rng.randint(1, 99999)}",
                            headers=headers + [("Content-Type", "application/json")], body=body)
    if rng.random() < 0.12:
        request.transport = "chunked"
        request.chunk_size = rng.choice([0, 64, 512])
    return request


def _upload(rng: random.Random, index: int, hard: bool = False) -> CorpusRequest:
    boundary = f"----CDNFoundryBoundary{rng.getrandbits(48):012x}"
    kind = rng.choice(["text", "csv", "png", "pdf"])
    if kind == "text":
        name, mime, content = "notes.txt", "text/plain", (_text(rng, rng.randint(1, 20), hard) + "\n").encode()
    elif kind == "csv":
        name, mime = "export.csv", "text/csv"
        content = "id,name,email,city\n".encode() + "".join(f"{i},{_name(rng)},{_email(rng)},{rng.choice(CITIES)}\n" for i in range(rng.randint(2, 40))).encode()
    elif kind == "png":
        name, mime = "photo.png", "image/png"
        content = b"\x89PNG\r\n\x1a\n" + bytes(rng.getrandbits(8) for _ in range(rng.randint(200, 2400)))
    else:
        name, mime = "report.pdf", "application/pdf"
        content = b"%PDF-1.4\n" + base64.b64encode(bytes(rng.getrandbits(8) for _ in range(rng.randint(200, 1500)))) + b"\n%%EOF\n"
    parts = (f'--{boundary}\r\nContent-Disposition: form-data; name="title"\r\n\r\n{_name(rng)} {_words(rng, 1, hard)}\r\n'
             f'--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="{name}"\r\nContent-Type: {mime}\r\n\r\n').encode() + content + f"\r\n--{boundary}--\r\n".encode()
    headers = [("User-Agent", rng.choice(BROWSERS)), ("Content-Type", f"multipart/form-data; boundary={boundary}"), ("Origin", "https://www.example.com")]
    return CorpusRequest(_identifier("file_upload", index, hard), "clean", "file_upload", method="POST", path="/account/upload", headers=headers, body=parts)


def _mobile(rng: random.Random, index: int, hard: bool = False) -> CorpusRequest:
    headers = [("User-Agent", rng.choice(MOBILE_AGENTS)), ("Accept", "application/json"), ("Authorization", f"Bearer {rng.getrandbits(160):040x}"),
               ("X-Device-Id", f"{rng.getrandbits(128):032x}"), ("X-App-Version", f"{rng.randint(1, 6)}.{rng.randint(0, 9)}.{rng.randint(0, 9)}")]
    if rng.random() < 0.5:
        path = f"/mobile/v2/{rng.choice(['feed', 'profile', 'rides', 'wallet'])}?" + _urlencode([("since", str(1700000000 + rng.randint(0, 99999999))), ("lat", f"{rng.uniform(-60, 60):.5f}"), ("lng", f"{rng.uniform(-170, 170):.5f}")])
        return CorpusRequest(_identifier("mobile_api", index, hard), "clean", "mobile_api", path=path, headers=headers)
    body = json.dumps({"device": {"os": rng.choice(["android", "ios"]), "locale": rng.choice(["en-US", "fa-IR", "de-DE"])}, "events": [{"type": rng.choice(["open", "tap", "scroll"]), "ts": 1700000000 + rng.randint(0, 99999999)} for _ in range(rng.randint(1, 8))]}).encode()
    return CorpusRequest(_identifier("mobile_api", index, hard), "clean", "mobile_api", method="POST", path="/mobile/v2/events", headers=headers + [("Content-Type", "application/json")], body=body)


def _webhook(rng: random.Random, index: int, hard: bool = False) -> CorpusRequest:
    kind = rng.choice(["payment", "push", "ticket"])
    if kind == "payment":
        body = json.dumps({"id": f"evt_{rng.getrandbits(80):020x}", "type": rng.choice(["payment.succeeded", "payment.failed", "refund.created"]), "data": {"amount": rng.randint(100, 500000), "currency": rng.choice(["usd", "eur", "irr"]), "customer": f"cus_{rng.getrandbits(48):012x}"}})
        headers = [("User-Agent", "PaymentHooks/1.0"), ("X-Signature", f"t={1700000000 + rng.randint(0, 99999999)},v1={rng.getrandbits(256):064x}")]
    elif kind == "push":
        body = json.dumps({"ref": "refs/heads/main", "commits": [{"id": f"{rng.getrandbits(160):040x}", "message": _text(rng, 1, hard), "author": {"name": _name(rng)}} for _ in range(rng.randint(1, 3))]})
        headers = [("User-Agent", "GitHub-Hookshot/abc1234"), ("X-GitHub-Event", "push"), ("X-Hub-Signature-256", f"sha256={rng.getrandbits(256):064x}")]
    else:
        body = json.dumps({"ticket": rng.randint(1, 99999), "action": rng.choice(["created", "updated", "closed"]), "comment": _text(rng, 2, hard), "requester": _email(rng)})
        headers = [("User-Agent", "Helpdesk-Webhook/3.1"), ("X-Webhook-Token", f"{rng.getrandbits(128):032x}")]
    return CorpusRequest(_identifier("webhook", index, hard), "clean", "webhook", method="POST", path=f"/hooks/{kind}", headers=headers + [("Content-Type", "application/json")], body=body.encode())


def _crawler(rng: random.Random, index: int, hard: bool = False) -> CorpusRequest:
    path = rng.choice(["/robots.txt", "/sitemap.xml", "/sitemap-posts.xml", "/feed.xml", rng.choice(PATHS), f"/blog/{rng.randint(1, 900)}", f"/products/{rng.randint(1, 5000)}?page={rng.randint(1, 30)}"])
    headers = [("User-Agent", rng.choice(CRAWLERS)), ("Accept", "*/*"), ("Accept-Encoding", "gzip")]
    return CorpusRequest(_identifier("crawler", index, hard), "clean", "crawler", method=rng.choice(["GET", "GET", "GET", "HEAD"]), path=path, headers=headers)


CLEAN_GENERATORS = {"browser_page_load": _browser, "form_post": _form, "json_api": _json_api, "file_upload": _upload,
                    "mobile_api": _mobile, "webhook": _webhook, "crawler": _crawler}


def clean_corpus(seed: int = CLEAN_CORPUS_SEED, scale: float = 1.0) -> list[CorpusRequest]:
    """Deterministic clean traffic of seven classes. ``scale`` below 1 gives a smaller sample for development runs only."""
    requests: list[CorpusRequest] = []
    for name, size in CLEAN_CLASS_SIZES.items():
        rng = random.Random(f"{seed}:{name}")
        generator = CLEAN_GENERATORS[name]
        requests += [generator(rng, index, _hard(rng)) for index in range(max(1, int(size * scale)))]
    return requests


def corpus_digest(requests: list[CorpusRequest]) -> str:
    digest = hashlib.sha256()
    for request in requests:
        digest.update(json.dumps([request.identifier, request.method, request.path, request.headers, request.transport,
                                  request.chunk_size], sort_keys=True).encode())
        digest.update(request.raw if request.raw is not None else request.body)
    return digest.hexdigest()


def clean_class_counts(requests: list[CorpusRequest]) -> dict[str, int]:
    counts: dict[str, int] = {}
    for request in requests:
        counts[request.category] = counts.get(request.category, 0) + 1
    return counts


def is_hard_negative(identifier: str) -> bool:
    return identifier.startswith("clean-hard:")
