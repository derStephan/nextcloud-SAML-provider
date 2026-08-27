#!/usr/bin/env python3
"""Extract SAML POST and credential-form details from Nextcloud HTML."""
from html.parser import HTMLParser
from pathlib import Path
import json
import sys

class FormParser(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.current = None
        self.forms = []
        self.requesttoken = ""
    def handle_starttag(self, tag, attrs):
        values = dict(attrs)
        if "data-requesttoken" in values and not self.requesttoken:
            self.requesttoken = values["data-requesttoken"]
        if tag.lower() == "form":
            self.current = {"action": values.get("action", ""), "inputs": {}}
        elif tag.lower() == "input" and self.current is not None:
            name = values.get("name", "")
            if name:
                self.current["inputs"][name] = {
                    "value": values.get("value", ""),
                    "type": values.get("type", "text").lower(),
                }
                if name == "requesttoken" and not self.requesttoken:
                    self.requesttoken = values.get("value", "")
    def handle_endtag(self, tag):
        if tag.lower() == "form" and self.current is not None:
            self.forms.append(self.current)
            self.current = None

page = Path(sys.argv[1]).read_text(encoding="utf-8", errors="replace")
parser = FormParser()
parser.feed(page)
parser.close()
result = {"action": "", "SAMLResponse": "", "RelayState": "", "requesttoken": parser.requesttoken, "loginAction": "", "loginUserField": ""}
for form in parser.forms:
    fields = form["inputs"]
    if "SAMLResponse" in fields:
        result["action"] = form["action"]
        result["SAMLResponse"] = fields["SAMLResponse"]["value"]
        result["RelayState"] = fields.get("RelayState", {}).get("value", "")
    if any(field.get("type") == "password" for field in fields.values()):
        result["loginAction"] = form["action"]
        for candidate in ("user", "username", "login"):
            if candidate in fields:
                result["loginUserField"] = candidate
                break
print(json.dumps(result))
