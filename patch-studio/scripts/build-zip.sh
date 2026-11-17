#!/usr/bin/env bash
# Patch Studio: installeerbare plugin-zip maken → dist/borduur-patch-studio.zip
#   (WordPress: Plugins › Nieuwe plugin › Plugin uploaden).
# Bouwt eerst de JS (npm run build), kopieert de plugin zonder bronbestanden/tests/node_modules,
# controleert of alle runtime-bestanden er zijn en of elke PHP-file syntactisch klopt, en zipt met
# de map borduur-patch-studio/ als top-niveau (vereist door WordPress).
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SRC="$ROOT/plugin/borduur-patch-studio"
OUT="$ROOT/dist/borduur-patch-studio.zip"
cd "$ROOT"

[[ "${BPS_SKIP_BUILD:-0}" == 1 ]] || npm run --silent build

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
DEST="$STAGE/borduur-patch-studio"
mkdir -p "$DEST"
# Copy everything except development-only files.
tar -C "$SRC" \
	--exclude='./assets/src' \
	--exclude='node_modules' \
	--exclude='tests' \
	--exclude='*.map' \
	--exclude='.DS_Store' \
	--exclude='.git*' \
	--exclude='*.log' \
	-cf - . | tar -C "$DEST" -xf -

# Required runtime files.
missing=0
for f in borduur-patch-studio.php uninstall.php \
	assets/dist/patch-studio.js assets/dist/patch-studio-worker.js assets/dist/patch-studio-admin.js assets/dist/patch-studio-admin.css \
	assets/css/patch-studio.css config/defaults.json config/thread-palette.json \
	languages/borduur-patch-studio.pot languages/borduur-patch-studio-fr_BE.mo; do
	[[ -s "$DEST/$f" ]] || { echo "ONTBREEKT: $f" >&2; missing=1; }
done
# Every font named in the config must be present.
while IFS= read -r font; do
	[[ -z "$font" ]] && continue
	[[ -s "$DEST/assets/fonts/$font" ]] || { echo "ONTBREEKT: assets/fonts/$font" >&2; missing=1; }
done < <(php -r '$c=json_decode(file_get_contents($argv[1]),true); foreach(($c["fonts"]??[]) as $f){ if(!empty($f["file"])) echo basename($f["file"]),"\n"; }' "$DEST/config/defaults.json")
[[ $missing -eq 0 ]] || { echo "Zip niet gemaakt: bestanden ontbreken." >&2; exit 1; }

bad=0
while IFS= read -r -d '' f; do php -l "$f" >/dev/null 2>&1 || { php -l "$f"; bad=1; }; done < <(find "$DEST" -name '*.php' -print0)
[[ $bad -eq 0 ]] || { echo "Zip niet gemaakt: PHP-syntaxfout." >&2; exit 1; }

mkdir -p "$ROOT/dist"
rm -f "$OUT"
(cd "$STAGE" && zip -qr -X "$OUT" borduur-patch-studio)
echo "dist/borduur-patch-studio.zip  $(du -h "$OUT" | cut -f1)  ($(unzip -l "$OUT" | tail -1 | awk '{print $2}') bestanden)"
