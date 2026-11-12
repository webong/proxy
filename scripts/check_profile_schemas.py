"""Validate the shared fixture corpus against the published draft-2020-12 schemas.

Test-only dependency: jsonschema>=4,<5. Runtime Go/PHP parsers remain authoritative
for semantic IP validity; the schema additionally checks the wire document shape,
URL grammar, decoded credential byte lengths and portable HTTP byte subset.
"""

import json
from pathlib import Path
from urllib.parse import quote

from jsonschema import Draft202012Validator

ROOT = Path(__file__).resolve().parents[1]
checked = 0


def load(path):
    return json.loads(path.read_text())


def validator(path):
    schema = load(ROOT / path)
    Draft202012Validator.check_schema(schema)
    return Draft202012Validator(schema)


def check(schema, document, expected, label):
    global checked
    errors = list(schema.iter_errors(document))
    if (not errors) != expected:
        raise AssertionError(f"{label}: expected {'valid' if expected else 'invalid'}")
    checked += 1


http = validator("spec/http-profile.schema.json")
tcp = validator("spec/tcp-profile.schema.json")
udp = validator("spec/udp-profile.schema.json")
for schema, directory in [(http, "spec/fixtures"), (tcp, "spec/tcp"), (udp, "spec/udp")]:
    for group in ("valid", "invalid"):
        files = sorted((ROOT / directory / group).glob("*.json"))
        assert files, f"Missing {directory}/{group} fixtures"
        for path in files:
            check(schema, load(path), group == "valid", str(path.relative_to(ROOT)))

credentials = load(ROOT / "spec/http-credential-cases.json")
for scheme in credentials["schemes"]:
    for case in credentials["cases"]:
        proxy = f'{scheme}://{case["userinfo"]}@proxy.example'
        check(http, {"version": 1, "proxy": {"http": proxy}}, case["valid"], f'{scheme}/{case["name"]}')

# Raw TCP deliberately preserves C0 credentials for RFC1929 framing.
for case in load(ROOT / "spec/socks5-cases.json"):
    if case.get("credentials"):
        user = quote(case.get("username", "user"), safe="")
        password = quote(case.get("password", "p@ss"), safe="")
        for schema in (tcp, udp):
            check(schema, {"version": 1, "proxy": f'socks5h://{user}:{password}@proxy.example'}, True, case["name"])

print(f"Validated {checked} shared profile/schema cases")
