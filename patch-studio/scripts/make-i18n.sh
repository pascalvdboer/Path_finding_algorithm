#!/usr/bin/env bash
# Patch Studio: vertalingen bijwerken (PHP-teksten).
#   1. languages/borduur-patch-studio.pot opnieuw uit de PHP-code halen
#   2. nieuwe/gewijzigde teksten in de fr_BE .po zetten (bestaande vertalingen blijven)
#   3. .mo en .l10n.php (snelle vertalingen, WP 6.5+) opnieuw maken
# Vertaal daarna de lege msgstr in de .po (bv. met Poedit) en draai dit script opnieuw.
#
# De teksten van de ontwerptool zelf (JavaScript) zitten NIET in de .pot: die staan met NL + FR in
# assets/src/**/messages.js en worden via de configuratie meegegeven (geen wp.i18n, dus geen JSON nodig).
#
# Vereist WP-CLI: `wp` op het PATH, of WP_CLI="php /pad/naar/wp-cli.phar".
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN="$ROOT/plugin/borduur-patch-studio"
WP_CLI="${WP_CLI:-wp}"
cd "$PLUGIN"
$WP_CLI i18n make-pot . languages/borduur-patch-studio.pot --domain=borduur-patch-studio \
	--exclude=assets/src,node_modules \
	--headers='{"Report-Msgid-Bugs-To":"https://borduurservicebelgie.be/","Language-Team":"Borduurservice België"}'
for po in languages/borduur-patch-studio-*.po; do
	$WP_CLI i18n update-po languages/borduur-patch-studio.pot "$po"
done
$WP_CLI i18n make-mo languages/ languages/
$WP_CLI i18n make-php languages/ languages/
echo "Klaar. Lege vertalingen:"
for po in languages/borduur-patch-studio-*.po; do
	printf '  %s: ' "$po"; msgattrib --untranslated "$po" 2>/dev/null | grep -c '^msgid ' || echo "(installeer gettext voor deze telling)"
done
