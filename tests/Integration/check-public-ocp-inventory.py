#!/usr/bin/env python3
"""Fail when a production OCP import is absent from the exact public API specification."""
from pathlib import Path
import json, re
root = Path(__file__).resolve().parents[2]
spec = json.loads((root / 'tests/Integration/public-ocp-api.json').read_text(encoding='utf-8'))
allowed = set(spec['contracts']) | set(spec['types'])
if any(not methods for methods in spec['contracts'].values()):
    raise SystemExit('PUBLIC OCP INVENTORY FAILED: a public OCP contract type has no declared methods.')
required_probes = {'OCP\\IDBConnection::getQueryBuilder/executeStatement/getPrefix', 'OCP\\DB\\QueryBuilder\\IQueryBuilder::executeQuery', 'OCP DB result::fetchAssociative/closeCursor', 'OCP\\Util::addScript/addStyle', 'OCP\\Migration\\SimpleMigrationStep::changeSchema and OCP\\DB\\ISchemaWrapper methods'}
missing_probes = required_probes - set(spec.get('behavioral_probes', {}))
if missing_probes:
    raise SystemExit('PUBLIC OCP INVENTORY FAILED: required behavior probes are absent: ' + ', '.join(sorted(missing_probes)))
imports: dict[str, list[str]] = {}
for source in (root / 'lib').rglob('*.php'):
    for line_number, line in enumerate(source.read_text(encoding='utf-8').splitlines(), 1):
        match = re.match(r'\s*use\s+(OCP\\[^;]+);', line)
        if match:
            imports.setdefault(match.group(1), []).append(f'{source.relative_to(root)}:{line_number}')
missing = [f'{name} ({", ".join(locations)})' for name, locations in sorted(imports.items()) if name not in allowed]
if missing:
    raise SystemExit('PUBLIC OCP INVENTORY FAILED: exact imports absent from public-ocp-api.json:\n- ' + '\n- '.join(missing))
print('PUBLIC OCP INVENTORY: every production OCP import exactly matches public-ocp-api.json.')
