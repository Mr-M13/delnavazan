#!/usr/bin/env python3
"""Plan and (optionally) create Telnyx WhatsApp templates from the reviewed manifest.

This is the only code path that talks to the Telnyx WABA, and it is deliberately
narrow:

  - it validates the reviewed manifest before touching the network and fails
    closed on any structural error (provider/category/language/placeholder shape,
    duplicate names, missing samples);
  - it can only list templates (GET) and create missing templates (POST). There
    is no update, delete, send or deploy verb anywhere in this file;
  - it uses only TELNYX_API_KEY and TELNYX_WABA_ID, read from the environment and
    never echoed, so the key never appears in a command line or a log line;
  - it treats a 409 (duplicate) as a skip and never overwrites an existing
    template, and it refuses (without creating) on any other non-2xx response;
  - it writes a machine-readable per-template result so the run can be audited.

Modes:
  --validate-only  offline: validate the manifest and print the create/skip plan
                   assuming an empty WABA. No network, no credentials.
  --mode dry_run   list existing templates, then print and record what WOULD be
                   created. Creates nothing.
  --mode apply     list existing templates, then create only the missing ones
                   with ~2s pacing between creates.
"""

import argparse
import json
import os
import re
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

API_BASE = "https://api.telnyx.com/v2"
ALLOWED_LANGUAGES = ("fa", "en")
REQUIRED_CATEGORY = "UTILITY"
REQUIRED_PROVIDER = "telnyx"
PAGE_SIZE = 250
CREATE_PACING_SECONDS = 2


def fail(message):
    sys.stderr.write("FAIL  %s\n" % message)
    sys.exit(1)


def load_manifest(path):
    try:
        with open(path, "r", encoding="utf-8") as handle:
            data = json.load(handle)
    except (OSError, ValueError) as exc:
        fail("cannot read manifest %s: %s" % (path, exc))
    if not isinstance(data, dict):
        fail("manifest root must be an object")
    return data


def validate_manifest(manifest):
    """Return the validated template list, failing closed on any error."""
    if manifest.get("provider") != REQUIRED_PROVIDER:
        fail("manifest provider must be %r" % REQUIRED_PROVIDER)
    templates = manifest.get("templates")
    if not isinstance(templates, list) or not templates:
        fail("manifest must contain a non-empty 'templates' list")

    errors = []
    seen_names = set()
    validated = []
    for index, template in enumerate(templates):
        label = "template[%d]" % index

        provider = template.get("provider", REQUIRED_PROVIDER)
        if provider != REQUIRED_PROVIDER:
            errors.append("%s provider %r must be %r" % (label, provider, REQUIRED_PROVIDER))
            continue

        if template.get("category") != REQUIRED_CATEGORY:
            errors.append("%s category %r must be %r" % (label, template.get("category"), REQUIRED_CATEGORY))
            continue

        name = template.get("name")
        if not isinstance(name, str) or not name:
            errors.append("%s is missing a name" % label)
            continue
        if name in seen_names:
            errors.append("%s duplicate name %r" % (label, name))
            continue
        seen_names.add(name)

        language = template.get("language")
        if language not in ALLOWED_LANGUAGES:
            errors.append("%s language %r must be one of %s" % (label, language, list(ALLOWED_LANGUAGES)))
            continue

        body = template.get("body")
        if not isinstance(body, str) or not body:
            errors.append("%s is missing a body" % label)
            continue

        variables = template.get("variables")
        if not isinstance(variables, list):
            errors.append("%s 'variables' must be a list" % label)
            continue

        # Every {{...}} placeholder must be positional: no named {{var}} may
        # remain, and the count of {{n}} placeholders must equal len(variables).
        named = []
        positional = []
        for token in re.findall(r"\{\{\s*([^{}]+?)\s*\}\}", body):
            if token.isdigit():
                positional.append(token)
            else:
                named.append(token)
        if named:
            errors.append("%s has named placeholders %r (only {{n}} is allowed)" % (label, named))
            continue
        if len(positional) != len(variables):
            errors.append(
                "%s has %d positional placeholders but %d variables"
                % (label, len(positional), len(variables))
            )
            continue

        samples = template.get("samples")
        if not isinstance(samples, dict):
            errors.append("%s 'samples' must be an object" % label)
            continue
        missing_samples = [var for var in variables if var not in samples]
        if missing_samples:
            errors.append("%s variables %r have no sample" % (label, missing_samples))
            continue

        validated.append(template)

    if errors:
        for error in errors:
            sys.stderr.write("FAIL  %s\n" % error)
        fail("%d manifest validation error(s); nothing will be created" % len(errors))

    return validated


def api_request(api_key, method, path, payload=None):
    """Issue one Telnyx API request. Returns (status, parsed_json_or_none).

    The Authorization header is built from the environment key and is never
    returned, logged or printed.
    """
    url = API_BASE + path
    headers = {
        "Authorization": "Bearer " + api_key,
        "Accept": "application/json",
    }
    data = None
    if payload is not None:
        data = json.dumps(payload, ensure_ascii=False).encode("utf-8")
        headers["Content-Type"] = "application/json"
    request = urllib.request.Request(url, data=data, method=method, headers=headers)
    try:
        with urllib.request.urlopen(request, timeout=30) as response:
            raw = response.read().decode("utf-8")
            return response.status, _parse_json(raw)
    except urllib.error.HTTPError as exc:
        raw = ""
        try:
            raw = exc.read().decode("utf-8")
        except Exception:
            raw = ""
        return exc.code, _parse_json(raw)
    except Exception:
        # Any transport error fails closed without a traceback, so neither the
        # request URL (which carries the WABA id) nor the key can be surfaced.
        return None, None


def _parse_json(raw):
    if not raw:
        return None
    try:
        return json.loads(raw)
    except ValueError:
        return None


def list_existing(api_key, waba_id):
    """Paginate every WABA template and return {(name, language): True}."""
    existing = {}
    page = 1
    while True:
        query = urllib.parse.urlencode(
            {"page[number]": page, "page[size]": PAGE_SIZE, "filter[waba_id]": waba_id}
        )
        status, body = api_request(api_key, "GET", "/whatsapp/message_templates?" + query)
        if status is None or status < 200 or status >= 300:
            fail("could not list existing templates (HTTP %s)" % ("unknown" if status is None else status))
        for item in (body or {}).get("data", []) or []:
            item_name = item.get("name")
            item_language = item.get("language")
            if item_name and item_language:
                existing[(item_name, item_language)] = True
        total_pages = int((body or {}).get("meta", {}).get("total_pages", 1) or 1)
        if page >= total_pages:
            break
        page += 1
    return existing


def create_template(api_key, waba_id, template):
    """POST one missing template. Returns (result, status, detail)."""
    variables = template["variables"]
    samples = template["samples"]
    example_body_text = [samples[var] for var in variables]
    payload = {
        "waba_id": waba_id,
        "name": template["name"],
        "language": template["language"],
        "category": REQUIRED_CATEGORY,
        "components": [
            {
                "type": "BODY",
                "text": template["body"],
                "example": {"body_text": example_body_text},
            }
        ],
    }
    status, body = api_request(api_key, "POST", "/whatsapp/message_templates", payload)
    if status is None:
        return "refused", None, "transport error"
    if 200 <= status < 300:
        return "created", status, "ok"
    if status == 409:
        return "skipped", status, "duplicate"
    return "refused", status, "status %s" % status


def run_plan(validated, existing, mode, api_key, waba_id):
    """Return the per-template results and summary counts."""
    results = []
    counts = {"create": 0, "skip": 0, "created": 0, "refused": 0}
    for template in validated:
        key = (template["name"], template["language"])
        if key in existing:
            results.append({"name": template["name"], "language": template["language"], "result": "skip", "status": None, "detail": "already exists"})
            counts["skip"] += 1
            continue
        if mode == "apply":
            result, status, detail = create_template(api_key, waba_id, template)
            results.append({"name": template["name"], "language": template["language"], "result": result, "status": status, "detail": detail})
            if result == "created":
                counts["created"] += 1
            elif result == "skipped":
                counts["skip"] += 1
            else:
                counts["refused"] += 1
            time.sleep(CREATE_PACING_SECONDS)
        else:
            results.append({"name": template["name"], "language": template["language"], "result": "create", "status": None, "detail": "would create"})
            counts["create"] += 1
    return results, counts


def print_table(results, counts, mode):
    print("MODE   %s" % mode)
    print("TOTAL  %d" % len(results))
    for key in ("create", "created", "skip", "refused"):
        if counts.get(key):
            print("%-6s %d" % (key.upper(), counts[key]))
    for row in results:
        status = row["status"] if row["status"] is not None else "-"
        print("%-7s %-60s %s" % (row["result"].upper(), row["name"], row["language"] + " " + str(status)))


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--manifest", required=True, help="path to whatsapp-templates.json")
    parser.add_argument("--mode", choices=("validate-only", "dry_run", "apply"), default="validate-only")
    parser.add_argument("--result", default=None, help="write a JSON result file for the job artifact")
    args = parser.parse_args()

    manifest = load_manifest(args.manifest)
    validated = validate_manifest(manifest)

    existing = {}
    key = ""
    waba = ""
    if args.mode in ("dry_run", "apply"):
        key = os.environ.get("TELNYX_API_KEY", "")
        waba = os.environ.get("TELNYX_WABA_ID", "")
        if not key:
            fail("TELNYX_API_KEY is not configured in the production environment")
        if not waba:
            fail("TELNYX_WABA_ID is not configured in the production environment")
        existing = list_existing(key, waba)

    results, counts = run_plan(validated, existing, args.mode, key, waba)
    print_table(results, counts, args.mode)

    if args.result:
        record = {
            "mode": args.mode,
            "manifest": os.path.basename(args.manifest),
            "total": len(validated),
            "counts": counts,
            "templates": results,
        }
        with open(args.result, "w", encoding="utf-8") as handle:
            json.dump(record, handle, ensure_ascii=False, indent=2)
        print("RESULT %s" % args.result)

    if args.mode == "apply" and counts["refused"]:
        fail("%d template(s) were refused; see the result artifact for statuses" % counts["refused"])


if __name__ == "__main__":
    main()
