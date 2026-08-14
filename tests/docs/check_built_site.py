#!/usr/bin/env python3
"""Validate internal links in the rendered VitePress site."""

from __future__ import annotations

from html.parser import HTMLParser
import os
import pathlib
import re
import sys
import urllib.parse
import xml.etree.ElementTree as ET


ROOT = pathlib.Path(__file__).resolve().parents[2]
DIST = ROOT / "docs" / ".vitepress" / "dist"
SITE_ORIGIN = "https://docs.invalid"
CONFIGURED_BASE = os.environ.get("DOCS_BASE", "/CDNFoundry/").strip("/")
SITE_BASE = f"/{CONFIGURED_BASE}/" if CONFIGURED_BASE else "/"
PUBLIC_SITE_URL = os.environ.get(
    "DOCS_SITE_URL", "https://vaheed.github.io/CDNFoundry"
).rstrip("/")
SOURCE_DOCS = ROOT / "docs"
UNPUBLISHED_DOCUMENTS = {"manual-browser-qualification.md", "roadmap.md"}


class PageParser(HTMLParser):
    def __init__(self) -> None:
        super().__init__()
        self.links: list[str] = []
        self.identifiers: set[str] = set()
        self.canonicals: list[str] = []
        self.descriptions: list[str] = []
        self.h1_count = 0
        self._in_title = False
        self._title_parts: list[str] = []

    def handle_starttag(
        self, tag: str, attributes: list[tuple[str, str | None]]
    ) -> None:
        values = dict(attributes)
        identifier = values.get("id")
        if identifier:
            self.identifiers.add(identifier)
        if tag == "a" and values.get("href"):
            self.links.append(values["href"])
        if (
            tag == "link"
            and values.get("rel") == "canonical"
            and values.get("href")
        ):
            self.canonicals.append(values["href"])
        if (
            tag == "meta"
            and values.get("name") == "description"
            and values.get("content")
        ):
            self.descriptions.append(values["content"])
        if tag == "h1":
            self.h1_count += 1
        if tag == "title":
            self._in_title = True

    def handle_endtag(self, tag: str) -> None:
        if tag == "title":
            self._in_title = False

    def handle_data(self, data: str) -> None:
        if self._in_title:
            self._title_parts.append(data)

    @property
    def title(self) -> str:
        return "".join(self._title_parts).strip()


def page_url(path: pathlib.Path) -> str:
    relative = path.relative_to(DIST)
    if relative.name == "index.html":
        route = relative.parent.as_posix().strip("/")
        if route == ".":
            route = ""
        suffix = f"{route}/" if route else ""
    else:
        suffix = relative.with_suffix("").as_posix()
    return f"{SITE_ORIGIN}{SITE_BASE}{suffix}"


def public_page_url(path: pathlib.Path) -> str:
    relative = path.relative_to(DIST)
    if relative.name == "index.html":
        route = relative.parent.as_posix().strip("/")
        if route == ".":
            route = ""
        suffix = f"{route}/" if route else ""
    else:
        suffix = relative.with_suffix("").as_posix()
    return f"{PUBLIC_SITE_URL}/{suffix}"


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
    canonical_urls: set[str] = set()
    title_owners: dict[str, pathlib.Path] = {}
    description_owners: dict[str, pathlib.Path] = {}
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
        "canonical URL": f'<link rel="canonical" href="{PUBLIC_SITE_URL}/">',
        "Open Graph image dimensions": '<meta property="og:image:width" content="1200">',
        "homepage product summary": "What CDNFoundry includes",
        "self-hosted CDN heading": "Self-hosted private CDN software",
    }
    for feature, marker in shell_markers.items():
        if marker not in home_markup:
            failures.append(f"index.html: missing {feature}")

    sitemap_urls: set[str] = set()
    sitemap = DIST / "sitemap.xml"
    if not sitemap.is_file():
        failures.append("sitemap.xml: missing generated sitemap")
    else:
        try:
            root = ET.parse(sitemap).getroot()
            namespace = {"s": "http://www.sitemaps.org/schemas/sitemap/0.9"}
            sitemap_urls = {
                element.text
                for element in root.findall("s:url/s:loc", namespace)
                if element.text
            }
            expected_home = f"{PUBLIC_SITE_URL}/"
            if expected_home not in sitemap_urls:
                failures.append(f"sitemap.xml: missing homepage {expected_home}")
            indexable_pages = sum(path.name != "404.html" for path in pages)
            if len(sitemap_urls) != indexable_pages:
                failures.append(
                    f"sitemap.xml: {len(sitemap_urls)} URLs but rendered site has "
                    f"{indexable_pages} indexable pages"
                )
        except ET.ParseError as error:
            failures.append(f"sitemap.xml: invalid XML: {error}")

    robots = DIST / "robots.txt"
    expected_sitemap = f"Sitemap: {PUBLIC_SITE_URL}/sitemap.xml"
    if not robots.is_file() or expected_sitemap not in robots.read_text(encoding="utf-8"):
        failures.append(f"robots.txt: missing {expected_sitemap}")

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
        relative_source = source.relative_to(DIST)
        if source.name != "404.html":
            expected_canonical = public_page_url(source)
            if parser.canonicals != [expected_canonical]:
                failures.append(
                    f"{relative_source}: expected one canonical {expected_canonical}, "
                    f"found {parser.canonicals}"
                )
            else:
                canonical_urls.add(expected_canonical)
            if not parser.title:
                failures.append(f"{relative_source}: missing non-empty title")
            elif parser.title in title_owners:
                failures.append(
                    f"{relative_source}: duplicates title from "
                    f"{title_owners[parser.title].relative_to(DIST)}"
                )
            else:
                title_owners[parser.title] = source
            if len(parser.descriptions) != 1:
                failures.append(
                    f"{relative_source}: expected one non-empty description, "
                    f"found {len(parser.descriptions)}"
                )
            elif parser.descriptions[0] in description_owners:
                failures.append(
                    f"{relative_source}: duplicates description from "
                    f"{description_owners[parser.descriptions[0]].relative_to(DIST)}"
                )
            else:
                description_owners[parser.descriptions[0]] = source
            if parser.h1_count != 1:
                failures.append(
                    f"{relative_source}: expected one h1, found {parser.h1_count}"
                )
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

    if sitemap_urls and sitemap_urls != canonical_urls:
        missing_from_sitemap = sorted(canonical_urls - sitemap_urls)
        missing_canonical_pages = sorted(sitemap_urls - canonical_urls)
        if missing_from_sitemap:
            failures.append(
                "sitemap.xml: missing canonical URLs " + ", ".join(missing_from_sitemap)
            )
        if missing_canonical_pages:
            failures.append(
                "sitemap.xml: contains URLs without canonical pages "
                + ", ".join(missing_canonical_pages)
            )

    private_cdn_page = page_markup.get(
        (DIST / "getting-started" / "private-cdn-design.html").resolve(), ""
    )
    if 'id="how-to-build-a-self-hosted-private-cdn"' not in private_cdn_page:
        failures.append(
            "getting-started/private-cdn-design.html: missing self-hosted CDN h1"
        )
    if "Self-hosted CDN or managed CDN?" not in private_cdn_page:
        failures.append(
            "getting-started/private-cdn-design.html: missing managed-CDN comparison"
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
