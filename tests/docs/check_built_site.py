#!/usr/bin/env python3
"""Validate internal links in the rendered VitePress site."""

from __future__ import annotations

from html.parser import HTMLParser
import os
import pathlib
import re
import sys
import urllib.parse


ROOT = pathlib.Path(__file__).resolve().parents[2]
DIST = ROOT / "docs" / ".vitepress" / "dist"
SITE_ORIGIN = "https://docs.invalid"
CONFIGURED_BASE = os.environ.get("DOCS_BASE", "/CDNFoundry/").strip("/")
SITE_BASE = f"/{CONFIGURED_BASE}/" if CONFIGURED_BASE else "/"
SOURCE_DOCS = ROOT / "docs"
UNPUBLISHED_DOCUMENTS = {"manual-browser-qualification.md", "roadmap.md"}


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
    page_markup: dict[pathlib.Path, str] = {}
    for path in sorted(DIST.rglob("*.html")):
        markup = path.read_text(encoding="utf-8")
        parser = PageParser()
        parser.feed(markup)
        pages[path.resolve()] = parser
        page_markup[path.resolve()] = markup

    failures: list[str] = []
    link_count = 0
    home = DIST / "index.html"
    home_markup = page_markup.get(home.resolve(), "")
    shell_markers = {
        "VitePress navigation": 'class="VPNav"',
        "Learn navigation": f'href="{SITE_BASE}concepts/cdn-fundamentals"',
        "Architecture navigation": f'href="{SITE_BASE}architecture/"',
        "local search": 'id="local-search"',
        "accessible appearance switch": 'class="VPSwitch VPSwitchAppearance"',
        "diagram rendering container": 'class="cdnf-diagram"',
        "server-rendered diagram fallback": 'class="cdnf-diagram-fallback"',
        "Google Search Console verification": (
            'name="google-site-verification" '
            'content="5Vy61YITiNmNEK2ePkuwEyAL34Lq2UQ6C7xXGXt05uI"'
        ),
    }
    for feature, marker in shell_markers.items():
        if marker not in home_markup:
            failures.append(f"index.html: missing {feature}")

    compiled_css = re.sub(
        r"\s+",
        "",
        "\n".join(
            path.read_text(encoding="utf-8")
            for path in sorted((DIST / "assets").glob("*.css"))
        ),
    )
    diagram_style_markers = {
        "compact diagram height": "max-height:min(52vh,28rem)",
        "large diagram view": ".cdnf-diagram-expanded",
        "diagram size control": ".cdnf-diagram-toggle",
    }
    for feature, marker in diagram_style_markers.items():
        if marker not in compiled_css:
            failures.append(f"compiled CSS: missing {feature}")

    published_sources = [
        path
        for path in SOURCE_DOCS.rglob("*.md")
        if path.name not in UNPUBLISHED_DOCUMENTS
        and not {"legacy", "node_modules", ".vitepress"}.intersection(
            path.relative_to(SOURCE_DOCS).parts
        )
    ]
    source_diagrams = sum(
        path.read_text(encoding="utf-8").count("```mermaid")
        for path in published_sources
    )
    source_callouts = sum(
        len(
            re.findall(
                r"^:::\s+(?:info|tip|warning|danger)\b",
                path.read_text(encoding="utf-8"),
                re.MULTILINE,
            )
        )
        for path in published_sources
    )
    rendered_diagrams = sum(
        markup.count('class="cdnf-diagram"') for markup in page_markup.values()
    )
    rendered_callouts = sum(
        markup.count(' custom-block"') for markup in page_markup.values()
    )
    if rendered_diagrams != source_diagrams:
        failures.append(
            f"rendered site has {rendered_diagrams} diagrams but source has "
            f"{source_diagrams}"
        )
    if rendered_callouts != source_callouts:
        failures.append(
            f"rendered site has {rendered_callouts} callouts but source has "
            f"{source_callouts}"
        )

    for source, parser in pages.items():
        markup = page_markup[source]
        if '<div class="mermaid"></div>' in markup:
            failures.append(
                f"{source.relative_to(DIST)}: contains an empty Mermaid container"
            )
        if re.search(r"<p>:::\s+(?:info|tip|warning|danger)\b", markup):
            failures.append(
                f"{source.relative_to(DIST)}: contains an unrendered custom container"
            )
        diagrams = markup.count('class="cdnf-diagram"')
        fallbacks = markup.count('class="cdnf-diagram-fallback"')
        if diagrams != fallbacks:
            failures.append(
                f"{source.relative_to(DIST)}: {diagrams} diagrams but "
                f"{fallbacks} server-rendered fallbacks"
            )
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
        f"pages={len(pages)} internal_links={link_count} "
        f"diagrams={rendered_diagrams} callouts={rendered_callouts}"
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
