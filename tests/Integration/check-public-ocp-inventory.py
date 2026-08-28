#!/usr/bin/env python3
"""Fail when a production OCP import is absent from the exact public API specification."""
from pathlib import Path
import json, re
root = Path(__file__).resolve().parents[2]
spec = json.loads((root / 'tests/Integration/public-ocp-api.json').read_text(encoding='utf-8'))
allowed = set(spec['contracts']) | set(spec['types'])
if any(not methods for methods in spec['contracts'].values()):
    raise SystemExit('PUBLIC OCP INVENTORY FAILED: a public OCP contract type has no declared methods.')
uses: dict[str, list[str]] = {}
methods: dict[str, set[str]] = {}
for directory in ('lib', 'templates'):
    for source in (root / directory).rglob('*.php'):
        for line_number, line in enumerate(source.read_text(encoding='utf-8').splitlines(), 1):
            location = f'{source.relative_to(root)}:{line_number}'
            match = re.match(r'\s*use\s+(OCP\\[^;]+);', line)
            if match:
                uses.setdefault(match.group(1), []).append(location)
            for qualified in re.finditer(r'\\(OCP\\[A-Za-z0-9_\\]+)::([A-Za-z0-9_]+)\s*\(', line):
                uses.setdefault(qualified.group(1), []).append(location)
                methods.setdefault(qualified.group(1), set()).add(qualified.group(2))
missing = [f'{name} ({", ".join(locations)})' for name, locations in sorted(uses.items()) if name not in allowed]
for class_name, called in sorted(methods.items()):
    declared = set(spec['contracts'].get(class_name, []))
    unknown = called - declared
    if unknown:
        missing.append(f'{class_name} calls undeclared methods {sorted(unknown)}')
if missing:
    raise SystemExit('PUBLIC OCP INVENTORY FAILED: exact imports absent from public-ocp-api.json:\n- ' + '\n- '.join(missing))
print('PUBLIC OCP INVENTORY: every production OCP import and qualified method call exactly matches public-ocp-api.json.')
