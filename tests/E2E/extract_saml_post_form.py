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
        self.result = {"action": "", "SAMLResponse": "", "RelayState": ""}
    def handle_starttag(self, tag, attrs):
        values = dict(attrs)
        if tag.lower() == "form":
            self.in_form = True
            self.result["action"] = values.get("action", "")
        elif tag.lower() == "input" and self.in_form:
            name = values.get("name", "")
            if name in self.result:
                self.result[name] = values.get("value", "")
    def handle_endtag(self, tag):
        if tag.lower() == "form":
            self.in_form = False

page = Path(sys.argv[1]).read_text(encoding="utf-8", errors="replace")
parser = FormParser()
parser.feed(page)
parser.close()
print(json.dumps(parser.result))
