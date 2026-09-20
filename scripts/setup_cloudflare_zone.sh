#!/usr/bin/env bash
# Create/update Cloudflare zone + Railway DNS for ebubeconnect.com
# Requires: CF_API_TOKEN (Zone:Edit + DNS:Edit), curl, python3
set -euo pipefail

DOMAIN="${DOMAIN:-ebubeconnect.com}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DNS_JSON="$ROOT/deploy/cloudflare-dns.json"
API="https://api.cloudflare.com/client/v4"

if [[ -z "${CF_API_TOKEN:-}" ]]; then
  echo "Set CF_API_TOKEN (Cloudflare API token with Zone + DNS edit)."
  echo "Create token: https://dash.cloudflare.com/profile/api-tokens"
  exit 1
fi

auth() {
  curl -sS -H "Authorization: Bearer ${CF_API_TOKEN}" -H "Content-Type: application/json" "$@"
}

echo "== Account =="
ACCOUNT_ID=$(auth "$API/accounts" | python3 -c 'import json,sys; d=json.load(sys.stdin); print(d["result"][0]["id"] if d.get("success") and d.get("result") else "")')
if [[ -z "$ACCOUNT_ID" ]]; then
  echo "Failed to read Cloudflare account. Check CF_API_TOKEN."
  auth "$API/accounts" | python3 -m json.tool | head -40
  exit 1
fi
echo "account=$ACCOUNT_ID"

echo "== Ensure zone $DOMAIN =="
ZONE_ID=$(auth "$API/zones?name=$DOMAIN" | python3 -c 'import json,sys; d=json.load(sys.stdin); print(d["result"][0]["id"] if d.get("result") else "")')
if [[ -z "$ZONE_ID" ]]; then
  ZONE_ID=$(auth -X POST "$API/zones" \
    --data "{\"name\":\"$DOMAIN\",\"account\":{\"id\":\"$ACCOUNT_ID\"},\"jump_start\":false,\"type\":\"full\"}" \
    | python3 -c 'import json,sys; d=json.load(sys.stdin); print(d.get("result",{}).get("id","")); 
import sys as s
err=d.get("errors");
print("", file=s.stderr) if False else None')
fi
if [[ -z "$ZONE_ID" ]]; then
  echo "Could not create/find zone for $DOMAIN"
  exit 1
fi
echo "zone_id=$ZONE_ID"

NS=$(auth "$API/zones/$ZONE_ID" | python3 -c 'import json,sys; d=json.load(sys.stdin); print(" ".join(d.get("result",{}).get("name_servers",[])))')
echo "Cloudflare nameservers: $NS"
echo "→ Set these at your domain registrar if DNS is not already on Cloudflare."

auth -X PATCH "$API/zones/$ZONE_ID/settings/ssl" --data '{"value":"strict"}' >/dev/null || true

echo "== Upsert DNS records =="
export ZONE_ID DOMAIN CF_API_TOKEN API
python3 <<'PY'
import json, os, urllib.request

token = os.environ["CF_API_TOKEN"]
zone = os.environ["ZONE_ID"]
domain = os.environ["DOMAIN"]
api = os.environ["API"]
cfg = json.load(open(os.path.join(os.path.dirname(__file__) if False else "", ""), encoding="utf-8") if False else open(
    "/Applications/XAMPP/xamppfiles/htdocs/Ebube/deploy/cloudflare-dns.json", encoding="utf-8"
))

def req(method, url, body=None):
    data = None if body is None else json.dumps(body).encode()
    r = urllib.request.Request(url, data=data, method=method, headers={
        "Authorization": f"Bearer {token}",
        "Content-Type": "application/json",
    })
    with urllib.request.urlopen(r) as resp:
        return json.load(resp)

for rec in cfg["dns_records"]:
    name = rec["name"]
    fqdn = domain if name == "@" else f"{name}.{domain}"
    listing = req("GET", f"{api}/zones/{zone}/dns_records?type={rec['type']}&name={fqdn}")
    payload = {
        "type": rec["type"],
        "name": name,
        "content": rec["content"],
        "ttl": 1,
        "proxied": bool(rec.get("proxied")),
    }
    results = listing.get("result") or []
    if results:
        rid = results[0]["id"]
        out = req("PUT", f"{api}/zones/{zone}/dns_records/{rid}", payload)
        print("updated", rec["type"], name, out.get("success"), out.get("errors"))
    else:
        out = req("POST", f"{api}/zones/{zone}/dns_records", payload)
        print("created", rec["type"], name, out.get("success"), out.get("errors"))

print("NS:", NS if False else "")
PY

echo "Cloudflare nameservers (set at registrar): $NS"
echo ""
echo "Next:"
echo "1) Point registrar NS to Cloudflare (if not already)"
echo "2) Resend → add domain $DOMAIN → add their DNS in Cloudflare"
echo "3) Railway vars / ./scripts/setup_resend_vars.sh"
echo "4) Deploy: ./scripts/deploy.sh"
