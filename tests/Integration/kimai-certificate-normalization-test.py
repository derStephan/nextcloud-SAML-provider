#!/usr/bin/env python3
"""Verify Kimai certificate normalization used by the browser E2E harness."""
import re

def normalize(pem: str) -> str:
    pem = re.sub(r"-----BEGIN CERTIFICATE-----|-----END CERTIFICATE-----", "", pem)
    return re.sub(r"\s+", "", pem)

expected = "MIIBExampleBase64Value+/="
for value in (
    "-----BEGIN CERTIFICATE-----\nMIIBExampleBase64Value+/=\n-----END CERTIFICATE-----\n",
    "-----BEGIN CERTIFICATE-----MIIBExampleBase64Value+/=-----END CERTIFICATE-----",
):
    actual = normalize(value)
    assert actual == expected, actual
    assert re.fullmatch(r"[A-Za-z0-9+/=]+", actual), actual
print("Kimai certificate normalization passed for multi-line and single-line PEM values.")
