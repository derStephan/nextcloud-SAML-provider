#!/usr/bin/env python3
"""Extract the SAML HTTP-POST form from a Nextcloud response."""
from html.parser import HTMLParser
from pathlib import Path
import json
import sys

class FormParser(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.in_form = False
        self.result = {"action": "", "SAMLResponse": "", "RelayState": "", "requesttoken": ""}
    def handle_starttag(self, tag, attrs):
        values = dict(attrs)
        if "data-requesttoken" in values and not self.result["requesttoken"]:
            self.result["requesttoken"] = values["data-requesttoken"]
        if tag.lower() == "form":
            self.in_form = True
            self.result["action"] = values.get("action", "")
        elif tag.lower() == "input":
            name = values.get("name", "")
            if name == "requesttoken" and not self.result["requesttoken"]:
                self.result["requesttoken"] = values.get("value", "")
            # Only SAML POST fields belong to the extracted SAML form payload.
            if self.in_form and name in ("SAMLResponse", "RelayState"):
                self.result[name] = values.get("value", "")
    def handle_endtag(self, tag):
        if tag.lower() == "form":
            self.in_form = False

page = Path(sys.argv[1]).read_text(encoding="utf-8", errors="replace")
parser = FormParser()
parser.feed(page)
parser.close()
print(json.dumps(parser.result))
