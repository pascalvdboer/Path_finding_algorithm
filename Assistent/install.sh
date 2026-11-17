#!/bin/bash
# Bouwt de Assistent-app en zet hem op je aangesloten iPhone.
# Gebruik:  ./install.sh <TEAM_ID> [bundle-id]
#   TEAM_ID vind je op developer.apple.com/account onder "Membership details".
#   Zonder TEAM_ID wordt alleen het Xcode-project gemaakt en geopend.
set -euo pipefail
cd "$(dirname "$0")"

TEAM_ID="${1:-${TEAM_ID:-}}"
BUNDLE_ID="${2:-com.assistent.$(echo "${TEAM_ID:-app}" | tr '[:upper:]' '[:lower:]')}"

if ! command -v xcodegen >/dev/null; then
  if ! command -v brew >/dev/null; then
    echo "Installeer eerst Homebrew: https://brew.sh" >&2; exit 1
  fi
  brew install xcodegen
fi
xcodegen generate

if [ -z "$TEAM_ID" ]; then
  open Assistent.xcodeproj
  echo "Project geopend. Kies in Xcode bij Signing & Capabilities je team, sluit je iPhone aan en druk op ▶︎."
  exit 0
fi

echo "▸ Bouwen voor iPhone (team $TEAM_ID, bundle $BUNDLE_ID)…"
xcodebuild -project Assistent.xcodeproj -scheme Assistent -configuration Release \
  -destination 'generic/platform=iOS' -derivedDataPath build \
  -allowProvisioningUpdates \
  DEVELOPMENT_TEAM="$TEAM_ID" PRODUCT_BUNDLE_IDENTIFIER="$BUNDLE_ID" CODE_SIGN_STYLE=Automatic \
  build | (command -v xcbeautify >/dev/null && xcbeautify || cat)

APP="build/Build/Products/Release-iphoneos/Assistent.app"
[ -d "$APP" ] || { echo "Bouwen mislukt, $APP niet gevonden." >&2; exit 1; }

echo "▸ iPhone zoeken…"
xcrun devicectl list devices --json-output /tmp/assistent-devices.json >/dev/null
DEVICE=$(python3 - <<'PY'
import json
data = json.load(open("/tmp/assistent-devices.json"))
for d in data.get("result", {}).get("devices", []):
    hw = d.get("hardwareProperties", {})
    conn = d.get("connectionProperties", {})
    if hw.get("platform") == "iOS" and conn.get("pairingState") == "paired" and conn.get("tunnelState") != "unavailable":
        print(d["identifier"]); break
PY
)
[ -n "$DEVICE" ] || { echo "Geen iPhone gevonden. Sluit hem aan (of zet hem op hetzelfde wifi) en ontgrendel hem." >&2; exit 1; }

echo "▸ Installeren op $DEVICE…"
xcrun devicectl device install app --device "$DEVICE" "$APP"
xcrun devicectl device process launch --device "$DEVICE" "$BUNDLE_ID" || true
echo "✓ Klaar! De app 'Assistent' staat op je iPhone."
