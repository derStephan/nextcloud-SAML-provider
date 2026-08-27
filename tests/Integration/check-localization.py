#!/usr/bin/env python3
"""Fail when Nextcloud UI locale catalogs drift from the English source catalog."""
from __future__ import annotations
import json
from pathlib import Path
import re
import sys

root = Path(__file__).resolve().parents[2]
l10n = root / 'l10n'
base = json.loads((l10n / 'en.json').read_text(encoding='utf-8'))['translations']
base_keys = set(base)
errors: list[str] = []
for catalog in sorted(l10n.glob('*.json')):
    data = json.loads(catalog.read_text(encoding='utf-8'))
    keys = set(data.get('translations', {}))
    if keys != base_keys:
        errors.append(f'{catalog.name}: missing={sorted(base_keys - keys)!r}; extra={sorted(keys - base_keys)!r}')
    js = catalog.with_suffix('.js')
    if not js.exists():
        errors.append(f'{js.name}: missing generated JavaScript catalog')
        continue
    # The generated JS contains exactly one JSON object between the app id and plural form.
    source = js.read_text(encoding='utf-8')
    match = re.search(r'"saml_provider",\s*(\{.*\})\s*,\s*"nplurals=', source, re.S)
    if not match:
        errors.append(f'{js.name}: cannot parse generated translation object')
    else:
        try:
            js_keys = set(json.loads(match.group(1)))
            if js_keys != keys:
                errors.append(f'{js.name}: keys differ from {catalog.name}')
        except json.JSONDecodeError as exc:
            errors.append(f'{js.name}: invalid generated JSON: {exc}')
# Server-rendered labels and validation messages use PHP catalogs. They form a
# separate catalog from JavaScript UI strings, but every locale must expose exactly
# the English server-side key set as well.
def php_keys(path: Path) -> set[str]:
    source = path.read_text(encoding='utf-8')
    return set(re.findall(r'^\s*[\"\'](.+?)[\"\']\s*=>', source, re.M))
php_base = php_keys(l10n / 'en.php')
for catalog in sorted(l10n.glob('*.php')):
    keys = php_keys(catalog)
    if keys != php_base:
        errors.append(f'{catalog.name}: PHP keys missing={sorted(php_base - keys)!r}; extra={sorted(keys - php_base)!r}')

if errors:
    print('Localization catalog validation failed:', *errors, sep='\n- ', file=sys.stderr)
    sys.exit(1)
print(f'Localization catalog validation passed: {len(base_keys)} UI keys across {len(list(l10n.glob("*.json")))} locales.')
