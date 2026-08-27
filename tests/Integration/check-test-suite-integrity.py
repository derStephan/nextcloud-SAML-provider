#!/usr/bin/env python3
"""Guard against a superficially green PHPUnit suite with no meaningful checks."""
from __future__ import annotations
from pathlib import Path
import re
import sys

root = Path(__file__).resolve().parents[2]
tests = sorted((root / 'tests' / 'Unit').glob('*Test.php'))
if len(tests) < 8:
    raise SystemExit('Too few unit-test files for the application surface.')
methods = assertions = 0
uncovered: list[str] = []
for test in tests:
    source = test.read_text(encoding='utf-8')
    count = len(re.findall(r'function\s+test[A-Za-z0-9_]+\s*\(', source))
    checks = len(re.findall(r'\b(?:assert[A-Za-z]+|expectException|fail|addToAssertionCount)\s*\(', source))
    methods += count
    assertions += checks
    if count == 0 or checks == 0:
        uncovered.append(test.name)
production = {path.stem for path in (root / 'lib').rglob('*.php')}
covered = set()
for test in tests:
    covered.update(re.findall(r'#[\[]?CoversClass\((?:\\?\w+\\)*([A-Za-z0-9_]+)::class\)', test.read_text(encoding='utf-8')))
# Controller and entity tests use behavioral fixture tests; all non-trivial services
# and controllers must still be represented by a correspondingly named test file.
# Some production classes are intentionally covered through a behavior/contract test
# with a clearer name than the class itself. Keep this mapping explicit so coverage
# cannot silently disappear during a refactor.
coverage_test = {
    'Admin': 'AdminSettingsTest.php',
    'Version0001Date20260826000000': 'MigrationContractTest.php',
    'ServiceProviderMapper': 'ServiceProviderTest.php',
}
missing = sorted(
    name for name in production
    if name not in {'Application'}
    and not (root/'tests'/'Unit'/coverage_test.get(name, f'{name}Test.php')).exists()
)
if methods < 30 or assertions < 100 or uncovered or missing:
    print(f'Unit-test integrity failed: methods={methods}, assertion-sites={assertions}, empty={uncovered}, missing-named-tests={missing}', file=sys.stderr)
    sys.exit(1)
print(f'Unit-test integrity passed: {len(tests)} files, {methods} test methods, {assertions} assertion sites.')
