#!/usr/bin/env python3
"""Generate core/phpstan-schema/schema.sql, the column list PHPStan reads for Eloquent models.

Larastan cannot parse a pg_dump, so this converts the PostgreSQL baseline in core/database/schema
into plain CREATE TABLE statements with types it understands. Run it after the baseline or any
later migration changes a table, or pass --check to fail when the file is out of date.
"""
import pathlib
import re
import sys

ROOT = pathlib.Path(__file__).resolve().parents[2]
SOURCE = ROOT / "core/database/schema/pgsql-schema.sql"
TARGET = ROOT / "core/phpstan-schema/schema.sql"


# Types Larastan cannot model precisely (dates and JSON, which models cast) stay unknown, so a cast decides.
def column_type(declaration: str) -> str:
    text = declaration.lower()
    if text.startswith("boolean"):
        return "boolean"
    if text.startswith(("bigint", "bigserial")):
        return "bigint"
    if text.startswith(("integer", "smallint", "serial")):
        return "int"
    if text.startswith("timestamp") or text.startswith("date"):
        return "geometry"
    if text.startswith(("jsonb", "json")):
        return "geometry"
    if text.startswith(("numeric", "double", "real", "decimal")):
        return "double"
    if text.startswith("text"):
        return "text"
    return "varchar(255)"


def render() -> str:
    source = SOURCE.read_text(encoding="utf-8")
    tables = []
    for match in re.finditer(r"^CREATE TABLE public\.(\w+) \((.*?)^\);", source, re.S | re.M):
        name, body = match.groups()
        columns = []
        for line in body.splitlines():
            line = line.strip().rstrip(",")
            if not line or line.upper().startswith(("CONSTRAINT", "PRIMARY", "UNIQUE", "CHECK", "FOREIGN")):
                continue
            column, _, rest = line.partition(" ")
            nullable = "NOT NULL" not in rest.upper()
            columns.append(f"  `{column}` {column_type(rest)}{'' if nullable else ' NOT NULL'}")
        tables.append(f"CREATE TABLE `{name}` (\n" + ",\n".join(columns) + "\n);")
    return "\n\n".join(tables) + "\n"


def main() -> int:
    content = render()
    if "--check" in sys.argv:
        if not TARGET.is_file() or TARGET.read_text(encoding="utf-8") != content:
            print("core/phpstan-schema/schema.sql is out of date; run scripts/ci/generate_phpstan_schema.py")
            return 1
        print("phpstan_schema=passed")
        return 0
    TARGET.parent.mkdir(parents=True, exist_ok=True)
    TARGET.write_text(content, encoding="utf-8")
    return 0


if __name__ == "__main__":
    sys.exit(main())
