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
allowed_locales = {'en', 'de', 'de_DE', 'fr', 'es', 'it', 'pt_BR', 'pl', 'ru', 'ja', 'zh_CN'}
for catalog in sorted(l10n.glob('*.json')):
    if catalog.stem not in allowed_locales:
        errors.append(f'{catalog.name}: incomplete locale is not a supported shipped catalog')
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
# Every literal server-side translation key must be present in the English PHP source catalog.
used_php_keys: set[str] = set()
for source in list((root / 'lib').rglob('*.php')) + list((root / 'templates').rglob('*.php')):
    used_php_keys.update(re.findall(r"->t\(\s*['\"]([^'\"]+)['\"]", source.read_text(encoding='utf-8')))
missing_used_php = sorted(used_php_keys - php_base)
if missing_used_php:
    errors.append(f'en.php: missing source keys used by PHP: {missing_used_php!r}')
for catalog in sorted(l10n.glob('*.php')):
    if catalog.stem not in allowed_locales:
        errors.append(f'{catalog.name}: incomplete locale is not a supported shipped catalog')
    keys = php_keys(catalog)
    if keys != php_base:
        errors.append(f'{catalog.name}: PHP keys missing={sorted(php_base - keys)!r}; extra={sorted(keys - php_base)!r}')

# Every shipped non-English locale must replace the source text for security-sensitive server messages.
for locale in sorted(allowed_locales - {'en'}):
    source = (l10n / f'{locale}.php').read_text(encoding='utf-8')
    for key in ('Entity ID must not be empty', 'SP certificate is not a valid X.509 PEM certificate', 'Service provider not found'):
        if f'"{key}" => "{key}"' in source or f"'{key}' => '{key}'" in source:
            errors.append(f'{locale}.php: required server-side message remains English: {key}')

if errors:
    print('Localization catalog validation failed:', *errors, sep='\n- ', file=sys.stderr)
    sys.exit(1)
# Key parity ensures every message can be resolved. It is deliberately not a
# translation-quality claim: report literal English fallbacks for human review.
coverage = []
for catalog in sorted(l10n.glob('*.json')):
    if catalog.stem not in allowed_locales:
        errors.append(f'{catalog.name}: incomplete locale is not a supported shipped catalog')
    translations = json.loads(catalog.read_text(encoding='utf-8')).get('translations', {})
    untranslated = sum(1 for key in base_keys if translations.get(key) == base.get(key))
    coverage.append(f'{catalog.stem}: {len(base_keys) - untranslated}/{len(base_keys)} UI strings differ from English')
print(f'Localization catalog structure passed: {len(base_keys)} UI keys across {len(list(l10n.glob("*.json")))} locales.')
print('Translation-difference report (not a quality certification): ' + '; '.join(coverage))
