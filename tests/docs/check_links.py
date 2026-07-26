#!/usr/bin/env python3
"""Validate the maintained Markdown documentation and its local references."""

from __future__ import annotations

import json
import pathlib
import re
import sys
import urllib.parse
import unicodedata


ROOT = pathlib.Path(__file__).resolve().parents[2]
DOCS_ROOT = ROOT / "docs"
PUBLIC_DOCUMENTS = [
    ROOT / "README.md",
    ROOT / "CONTRIBUTING.md",
    ROOT / "SECURITY.md",
    ROOT / "CODE_OF_CONDUCT.md",
    ROOT / "core" / "README.md",
    ROOT / ".github" / "pull_request_template.md",
]
REQUIRED_PAGES = {
    "index.md",
    "getting-started/index.md",
    "getting-started/private-cdn-design.md",
    "getting-started/installation.md",
    "concepts/index.md",
    "architecture/index.md",
    "guides/index.md",
    "reference/index.md",
    "reference/configuration.md",
    "reference/api/index.md",
    "reference/api/endpoints.md",
    "deployment/index.md",
    "deployment/production-quick-start.md",
    "deployment/upgrade.md",
    "operations/index.md",
    "security/index.md",
    "troubleshooting/index.md",
    "development/index.md",
    "contributing/index.md",
    "roadmap.md",
    "manual-browser-qualification.md",
}
LINK = re.compile(
    r"(?<!!)\[[^\]]*\]\((?:<([^>]+)>|([^\s)]+))(?:\s+['\"][^'\"]*['\"])?\)"
)
IMAGE = re.compile(
    r"!\[([^\]]*)\]\((?:<([^>]+)>|([^\s)]+))(?:\s+['\"][^'\"]*['\"])?\)"
)
HEADING = re.compile(r"^(#{1,6})\s+(.+?)\s*#*\s*$")
FRONTMATTER = re.compile(r"\A---\n(.*?)\n---\n", re.DOTALL)
ENDPOINT_ROW = re.compile(
    r"^\| `(?P<method>[A-Z]+)` \| `(?P<path>/[^`]+)` \| "
    r"(?:Required|No) \| (?:Supported|No) \| `(?P<operation>[^`]+)` \|$",
    re.MULTILINE,
)
MAKE_INVOCATION = re.compile(r"(?m)^\s*make\s+([A-Za-z0-9_.-]+)")
SCRIPT_PATH = re.compile(r"(?:\./)?(scripts/[A-Za-z0-9_./-]+\.sh)")
COMPOSE_PATH = re.compile(
    r"(?<![A-Za-z0-9_./-])((?:deploy/production/)?compose[A-Za-z0-9_.-]*\.ya?ml)"
)


def maintained_documents() -> list[pathlib.Path]:
    documents = [
        path
        for path in DOCS_ROOT.rglob("*.md")
        if not {"legacy", "node_modules", ".vitepress"}.intersection(
            path.relative_to(DOCS_ROOT).parts
        )
    ]
    return [*PUBLIC_DOCUMENTS, *sorted(documents)]


def content_lines(text: str) -> list[tuple[int, str]]:
    """Return lines outside fenced code blocks."""
    lines: list[tuple[int, str]] = []
    fence: str | None = None
    for number, line in enumerate(text.splitlines(), start=1):
        stripped = line.lstrip()
        marker = stripped[:3]
        if marker in {"```", "~~~"}:
            if fence is None:
                fence = marker
            elif fence == marker:
                fence = None
            continue
        if fence is None:
            lines.append((number, line))
    return lines


def slug(value: str) -> str:
    """Approximate the Markdown heading IDs emitted by VitePress."""
    value = re.sub(r"<[^>]+>", "", value)
    value = re.sub(r"!\[([^\]]*)\]\([^)]*\)", r"\1", value)
    value = re.sub(r"\[([^\]]+)\]\([^)]*\)", r"\1", value)
    value = value.replace("`", "").replace("*", "").replace("_", "")
    value = unicodedata.normalize("NFKC", value).strip().lower()
    value = re.sub(r"[^\w\s-]", "", value)
    return re.sub(r"[\s-]+", "-", value).strip("-")


def heading_ids(document: pathlib.Path) -> tuple[set[str], list[tuple[int, str]]]:
    ids: set[str] = set()
    duplicates: list[tuple[int, str]] = []
    text = document.read_text(encoding="utf-8")
    for number, line in content_lines(text):
        match = HEADING.match(line)
        if not match:
            continue
        identifier = slug(match.group(2))
        if identifier in ids:
            duplicates.append((number, identifier))
        ids.add(identifier)
    return ids, duplicates


def resolve_target(document: pathlib.Path, raw_target: str) -> tuple[pathlib.Path, str]:
    target = urllib.parse.unquote(raw_target)
    path_part, _, anchor = target.partition("#")
    path_part = path_part.split("?", 1)[0]

    if not path_part:
        return document, anchor

    if path_part.startswith("/"):
        relative = path_part.lstrip("/")
        public_asset = DOCS_ROOT / "public" / relative
        resolved = public_asset if public_asset.exists() else DOCS_ROOT / relative
    else:
        resolved = document.parent / path_part

    if resolved.suffix:
        return resolved.resolve(), anchor

    candidates = [
        resolved,
        resolved.with_suffix(".md"),
        resolved / "index.md",
    ]
    for candidate in candidates:
        if candidate.exists():
            return candidate.resolve(), anchor
    return resolved.resolve(), anchor


def validate_openapi(failures: list[str]) -> None:
    contract_path = DOCS_ROOT / "public" / "openapi.json"
    catalog_path = DOCS_ROOT / "reference" / "api" / "endpoints.md"
    try:
        contract = json.loads(contract_path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as error:
        failures.append(f"docs/public/openapi.json: invalid OpenAPI JSON: {error}")
        return

    if contract.get("openapi") != "3.1.0":
        failures.append("docs/public/openapi.json: expected OpenAPI 3.1.0")

    operations: set[tuple[str, str, str]] = set()
    operation_ids: set[str] = set()
    for path, path_item in contract.get("paths", {}).items():
        for method, operation in path_item.items():
            if method.upper() not in {"GET", "POST", "PUT", "PATCH", "DELETE"}:
                continue
            operation_id = operation.get("operationId")
            if not isinstance(operation_id, str) or not operation_id:
                failures.append(
                    f"docs/public/openapi.json: {method.upper()} {path} has no operationId"
                )
                continue
            if operation_id in operation_ids:
                failures.append(
                    f"docs/public/openapi.json: duplicate operationId {operation_id}"
                )
            operation_ids.add(operation_id)
            operations.add((method.upper(), path, operation_id))

    catalog = {
        (match.group("method"), match.group("path"), match.group("operation"))
        for match in ENDPOINT_ROW.finditer(catalog_path.read_text(encoding="utf-8"))
    }
    for missing in sorted(operations - catalog):
        failures.append(
            "docs/reference/api/endpoints.md: missing OpenAPI operation "
            f"{missing[0]} {missing[1]} ({missing[2]})"
        )
    for extra in sorted(catalog - operations):
        failures.append(
            "docs/reference/api/endpoints.md: undocumented OpenAPI mismatch "
            f"{extra[0]} {extra[1]} ({extra[2]})"
        )

    def resolve_pointer(pointer: str) -> object:
        value: object = contract
        for part in pointer.removeprefix("#/").split("/"):
            part = part.replace("~1", "/").replace("~0", "~")
            if not isinstance(value, dict) or part not in value:
                raise KeyError(pointer)
            value = value[part]
        return value

    def visit(value: object) -> None:
        if isinstance(value, dict):
            reference = value.get("$ref")
            if isinstance(reference, str) and reference.startswith("#/"):
                try:
                    resolve_pointer(reference)
                except KeyError:
                    failures.append(
                        f"docs/public/openapi.json: unresolved reference {reference}"
                    )
            for child in value.values():
                visit(child)
        elif isinstance(value, list):
            for child in value:
                visit(child)

    visit(contract)


def validate_implementation_references(
    documents: list[pathlib.Path], failures: list[str]
) -> tuple[int, int, int]:
    """Validate commands, repository paths, and environment-key coverage."""
    text = "\n".join(
        document.read_text(encoding="utf-8")
        for document in documents
        if document.exists()
    )

    makefile = (ROOT / "Makefile").read_text(encoding="utf-8")
    make_targets = set(re.findall(r"(?m)^([A-Za-z0-9_.-]+):", makefile))
    used_targets = set(MAKE_INVOCATION.findall(text))
    for target in sorted(used_targets - make_targets):
        failures.append(f"documentation: unknown Make target {target}")

    referenced_paths = set(SCRIPT_PATH.findall(text))
    referenced_paths.update(COMPOSE_PATH.findall(text))
    for path in sorted(referenced_paths):
        if not (ROOT / path).is_file() and not (
            ROOT / "deploy" / "production" / path
        ).is_file():
            failures.append(f"documentation: missing command/configuration path {path}")

    environment_keys: set[str] = set()
    for example in (ROOT / ".env.dev.example", ROOT / ".env.prod.example"):
        for line in example.read_text(encoding="utf-8").splitlines():
            match = re.match(r"([A-Z][A-Z0-9_]*)=", line)
            if match:
                environment_keys.add(match.group(1))

    implementation_files = [
        *(ROOT / "core" / "config").glob("*.php"),
        ROOT / "compose.dev.yml",
        ROOT / "compose.prod.yml",
        *(ROOT / "edge-agent").rglob("*.go"),
    ]
    for source in implementation_files:
        source_text = source.read_text(encoding="utf-8")
        environment_keys.update(
            re.findall(r"""env\(['"]([A-Z][A-Z0-9_]*)""", source_text)
        )
        environment_keys.update(
            re.findall(r"\$\{([A-Z][A-Z0-9_]*)", source_text)
        )
        environment_keys.update(
            re.findall(
                r"""(?:Getenv|LookupEnv)\(["`]([A-Z][A-Z0-9_]*)""",
                source_text,
            )
        )

    configuration = (
        DOCS_ROOT / "reference" / "configuration.md"
    ).read_text(encoding="utf-8")
    for key in sorted(environment_keys):
        if f"`{key}`" not in configuration:
            failures.append(
                f"docs/reference/configuration.md: missing implementation environment key {key}"
            )

    return len(used_targets), len(referenced_paths), len(environment_keys)


def main() -> int:
    failures: list[str] = []
    documents = maintained_documents()
    current_pages = {
        str(path.relative_to(DOCS_ROOT))
        for path in documents
        if path.is_relative_to(DOCS_ROOT)
    }
    for required in sorted(REQUIRED_PAGES - current_pages):
        failures.append(f"docs/{required}: required documentation page is missing")

    validate_openapi(failures)
    target_count, path_count, environment_count = validate_implementation_references(
        documents, failures
    )

    headings: dict[pathlib.Path, set[str]] = {}
    for document in documents:
        if not document.exists():
            failures.append(f"{document.relative_to(ROOT)}: required public document is missing")
            continue
        identifiers, duplicates = heading_ids(document)
        headings[document.resolve()] = identifiers
        for line, identifier in duplicates:
            failures.append(
                f"{document.relative_to(ROOT)}:{line}: duplicate heading id #{identifier}"
            )

        if document.is_relative_to(DOCS_ROOT):
            text = document.read_text(encoding="utf-8")
            frontmatter = FRONTMATTER.match(text)
            if frontmatter is None:
                failures.append(
                    f"{document.relative_to(ROOT)}: missing YAML front matter"
                )
            else:
                metadata = frontmatter.group(1)
                for field in ("title", "description"):
                    if not re.search(rf"^{field}:\s*\S.+$", metadata, re.MULTILINE):
                        failures.append(
                            f"{document.relative_to(ROOT)}: missing non-empty {field}"
                        )

    for document in documents:
        if not document.exists():
            continue
        text = document.read_text(encoding="utf-8")
        visible_text = "\n".join(line for _, line in content_lines(text))
        for match in IMAGE.finditer(visible_text):
            if not match.group(1).strip():
                line = text.count("\n", 0, match.start()) + 1
                failures.append(
                    f"{document.relative_to(ROOT)}:{line}: image is missing alt text"
                )

        for match in LINK.finditer(visible_text):
            target = urllib.parse.unquote(match.group(1) or match.group(2))
            if target.startswith(
                ("http://", "https://", "mailto:", "tel:", "data:", "javascript:")
            ):
                continue
            resolved, anchor = resolve_target(document, target)
            if not resolved.exists():
                line = text.count("\n", 0, match.start()) + 1
                failures.append(
                    f"{document.relative_to(ROOT)}:{line}: missing local target {target}"
                )
                continue
            if resolved.is_dir():
                index = resolved / "index.md"
                if not index.exists():
                    line = text.count("\n", 0, match.start()) + 1
                    failures.append(
                        f"{document.relative_to(ROOT)}:{line}: directory target has no index {target}"
                    )
                    continue
                resolved = index
            if anchor and resolved.suffix == ".md":
                identifiers = headings.get(resolved.resolve())
                if identifiers is None:
                    identifiers, _ = heading_ids(resolved)
                    headings[resolved.resolve()] = identifiers
                if urllib.parse.unquote(anchor).lower() not in identifiers:
                    line = text.count("\n", 0, match.start()) + 1
                    failures.append(
                        f"{document.relative_to(ROOT)}:{line}: missing anchor #{anchor} in "
                        f"{resolved.relative_to(ROOT)}"
                    )
    if failures:
        print("\n".join(failures), file=sys.stderr)
        return 1
    print(
        "documentation_validation=passed "
        f"documents={len(documents)} required_pages={len(REQUIRED_PAGES)} "
        f"make_targets={target_count} command_paths={path_count} "
        f"environment_keys={environment_count}"
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
