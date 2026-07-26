#!/usr/bin/env python3
"""Validate internal links in the rendered VitePress site."""

from __future__ import annotations

from html.parser import HTMLParser
import os
import pathlib
import sys
import urllib.parse


ROOT = pathlib.Path(__file__).resolve().parents[2]
DIST = ROOT / "docs" / ".vitepress" / "dist"
SITE_ORIGIN = "https://docs.invalid"
CONFIGURED_BASE = os.environ.get("DOCS_BASE", "/CDNFoundry/").strip("/")
SITE_BASE = f"/{CONFIGURED_BASE}/" if CONFIGURED_BASE else "/"


class PageParser(HTMLParser):
    def __init__(self) -> None:
        super().__init__()
        self.links: list[str] = []
        self.identifiers: set[str] = set()

    def handle_starttag(
        self, tag: str, attributes: list[tuple[str, str | None]]
    ) -> None:
        values = dict(attributes)
        identifier = values.get("id")
        if identifier:
            self.identifiers.add(identifier)
        if tag == "a" and values.get("href"):
            self.links.append(values["href"])


def page_url(path: pathlib.Path) -> str:
    relative = path.relative_to(DIST)
    if relative.name == "index.html":
        route = relative.parent.as_posix().strip("/")
        suffix = f"{route}/" if route else ""
    else:
        suffix = relative.with_suffix("").as_posix()
    return f"{SITE_ORIGIN}{SITE_BASE}{suffix}"


def resolve_output(path: str) -> pathlib.Path | None:
    relative = urllib.parse.unquote(path.removeprefix(SITE_BASE))
    direct = DIST / relative
    candidates = [direct]
    if not relative or relative.endswith("/"):
        candidates.append(direct / "index.html")
    else:
        candidates.extend([direct.with_suffix(".html"), direct / "index.html"])
    return next((candidate for candidate in candidates if candidate.is_file()), None)


def main() -> int:
    pages: dict[pathlib.Path, PageParser] = {}
    for path in sorted(DIST.rglob("*.html")):
        parser = PageParser()
        parser.feed(path.read_text(encoding="utf-8"))
        pages[path.resolve()] = parser

    failures: list[str] = []
    link_count = 0
    for source, parser in pages.items():
        source_url = page_url(source)
        for raw_target in parser.links:
            if raw_target.startswith(
                ("mailto:", "tel:", "data:", "javascript:")
            ):
                continue
            target = urllib.parse.urlsplit(
                urllib.parse.urljoin(source_url, raw_target)
            )
            if target.netloc != urllib.parse.urlsplit(SITE_ORIGIN).netloc:
                continue
            link_count += 1
            if not target.path.startswith(SITE_BASE):
                failures.append(
                    f"{source.relative_to(DIST)}: link escapes site base {raw_target}"
                )
                continue
            resolved = resolve_output(target.path)
            if resolved is None:
                failures.append(
                    f"{source.relative_to(DIST)}: missing rendered target {raw_target}"
                )
                continue
            if target.fragment and resolved.suffix == ".html":
                target_page = pages.get(resolved.resolve())
                if target_page is None or urllib.parse.unquote(
                    target.fragment
                ) not in target_page.identifiers:
                    failures.append(
                        f"{source.relative_to(DIST)}: missing rendered anchor "
                        f"#{target.fragment} in {resolved.relative_to(DIST)}"
                    )

    if failures:
        print("\n".join(failures), file=sys.stderr)
        return 1
    print(
        "built_site_link_validation=passed "
        f"pages={len(pages)} internal_links={link_count}"
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
