#!/usr/bin/env python3
"""Fail when a production OCP import is absent from the exact public API specification."""
from pathlib import Path
import json, re
root = Path(__file__).resolve().parents[2]
spec = json.loads((root / 'tests/Integration/public-ocp-api.json').read_text(encoding='utf-8'))
allowed = set(spec['contracts']) | set(spec['types'])
if any(not methods for methods in spec['contracts'].values()):
    raise SystemExit('PUBLIC OCP INVENTORY FAILED: a public OCP contract type has no declared methods.')
imports: dict[str, list[str]] = {}
method_calls: dict[str, list[str]] = {}
production_roots = [root / 'lib', root / 'templates']
for production_root in production_roots:
    for source in production_root.rglob('*.php'):
        for line_number, line in enumerate(source.read_text(encoding='utf-8').splitlines(), 1):
            location = f'{source.relative_to(root)}:{line_number}'
            match = re.match(r'\s*use\s+(OCP\\[^;]+);', line)
            if match:
                imports.setdefault(match.group(1), []).append(location)
            for class_name, method in re.findall(r'\\(OCP\\[A-Za-z_\\]+)::([A-Za-z_][A-Za-z0-9_]*)\s*\(', line):
                method_calls.setdefault(f'{class_name}::{method}', []).append(location)
missing = [f'{name} ({", ".join(locations)})' for name, locations in sorted(imports.items()) if name not in allowed]
for call, locations in sorted(method_calls.items()):
    class_name, method = call.rsplit('::', 1)
    if method not in spec['contracts'].get(class_name, []):
        missing.append(f'{call} ({", ".join(locations)})')
if missing:
    raise SystemExit('PUBLIC OCP INVENTORY FAILED: exact production OCP imports/method calls absent from public-ocp-api.json:\n- ' + '\n- '.join(missing))
print('PUBLIC OCP INVENTORY: every production OCP import exactly matches public-ocp-api.json.')
