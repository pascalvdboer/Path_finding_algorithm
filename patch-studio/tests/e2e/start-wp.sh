#!/usr/bin/env bash
# Patch Studio — local WordPress + WooCommerce test site (SQLite, php -S, no MySQL).
#
# Usage: tests/e2e/start-wp.sh [start|stop|restart|status|reset|url|wp <args…>]
#   start    (default) download/install if needed, link + activate the plugin, configure the shop, run the server
#   stop     stop the php -S server
#   restart  stop + start
#   status   print whether the server answers
#   reset    stop, delete the site (keeps downloads) and start fresh
#   url      print the studio page URL
#   wp …     run WP-CLI against the test site (e.g. tests/e2e/start-wp.sh wp option get siteurl)
#
# Settings come from tests/e2e/.env (if present) or tests/e2e/.env.example; every BPS_WP_* variable
# can also be set in the environment. Idempotent: running "start" again only re-applies settings.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/../.." && pwd)"
PLUGIN_SRC="$PROJECT_DIR/plugin/borduur-patch-studio"

# Load defaults without overriding variables that are already set in the environment.
load_env() {
	local file="$1" line key val
	[[ -f "$file" ]] || return 0
	while IFS= read -r line || [[ -n "$line" ]]; do
		[[ "$line" =~ ^[[:space:]]*# || ! "$line" =~ = ]] && continue
		key="${line%%=*}"; val="${line#*=}"
		key="$(echo "$key" | tr -d '[:space:]')"
		[[ "$key" =~ ^BPS_WP_[A-Z_]+$ ]] || continue
		if [[ -z "${!key:-}" ]]; then export "$key=$val"; fi
	done < "$file"
}
load_env "$SCRIPT_DIR/.env"
load_env "$SCRIPT_DIR/.env.example"

WP_DIR="${BPS_WP_DIR:-/tmp/claude-0/-home-user-Path-finding-algorithm/119da7ba-62fd-5edf-a213-c52888883035/scratchpad/wp}"
HOST="${BPS_WP_HOST:-127.0.0.1}"
PORT="${BPS_WP_PORT:-8899}"
URL="http://$HOST:$PORT"
ADMIN_USER="${BPS_WP_ADMIN_USER:-admin}"
ADMIN_PASS="${BPS_WP_ADMIN_PASS:-patchstudio-local-2026}"
ADMIN_EMAIL="${BPS_WP_ADMIN_EMAIL:-admin@example.test}"
CHECKOUT="${BPS_WP_CHECKOUT:-classic}"
THEME="${BPS_WP_THEME:-hello-elementor}"
HPOS="${BPS_WP_HPOS:-1}"

SITE="$WP_DIR/site"
DL="$WP_DIR/dl"
WPCLI="$DL/wp-cli.phar"
PIDFILE="$WP_DIR/server.pid"
LOG="$WP_DIR/server.log"

log() { printf '\033[1;34m[start-wp]\033[0m %s\n' "$*" >&2; }
die() { printf '\033[1;31m[start-wp] %s\033[0m\n' "$*" >&2; exit 1; }

wp() { local root=(); [[ "$(id -u)" == 0 ]] && root=(--allow-root); php -d memory_limit=1024M "$WPCLI" "${root[@]}" --path="$SITE" --url="$URL" "$@"; }

fetch() { # fetch <url> <dest> [fallback-url]
	local url="$1" dest="$2" alt="${3:-}"
	[[ -s "$dest" ]] && return 0
	log "download $(basename "$dest")"
	if ! curl -sSfL --retry 3 -o "$dest.part" "$url"; then
		[[ -n "$alt" ]] && curl -sSfL --retry 3 -o "$dest.part" "$alt" || { rm -f "$dest.part"; return 1; }
	fi
	mv "$dest.part" "$dest"
}

server_up() { curl -s -o /dev/null --max-time 3 "$URL/wp-login.php"; }

stop_server() {
	if [[ -f "$PIDFILE" ]]; then
		local pid; pid="$(cat "$PIDFILE")"
		if kill -0 "$pid" 2>/dev/null; then
			log "stop server (pid $pid)"
			kill "$pid" 2>/dev/null || true
			for _ in $(seq 1 30); do kill -0 "$pid" 2>/dev/null || break; sleep 0.1; done
			kill -9 "$pid" 2>/dev/null || true
		fi
		rm -f "$PIDFILE"
	fi
	# Fallback: anything else still bound to our port with our router.
	pkill -f "php .*-S $HOST:$PORT .*router.php" 2>/dev/null || true
}

start_server() {
	if server_up; then log "server already running on $URL"; return 0; fi
	log "start php -S on $URL"
	# PHP_CLI_SERVER_WORKERS: parallel requests (block checkout, REST + page loads at once).
	PHP_CLI_SERVER_WORKERS="${BPS_WP_WORKERS:-6}" nohup php \
		-d memory_limit=512M -d upload_max_filesize=32M -d post_max_size=40M -d max_execution_time=120 \
		-d display_errors=0 -d log_errors=1 \
		-S "$HOST:$PORT" -t "$SITE" "$SCRIPT_DIR/lib/router.php" >>"$LOG" 2>&1 &
	echo $! >"$PIDFILE"
	for _ in $(seq 1 100); do server_up && break; sleep 0.2; done
	server_up || { tail -n 30 "$LOG" >&2; die "server did not start (see $LOG)"; }
}

install_site() {
	mkdir -p "$WP_DIR" "$DL"
	command -v php >/dev/null || die "php not found"
	php -m | grep -qi pdo_sqlite || die "php extension pdo_sqlite is required"

	fetch "https://wordpress.org/latest.zip" "$DL/wordpress.zip"
	fetch "https://downloads.wordpress.org/plugin/sqlite-database-integration.latest-stable.zip" "$DL/sqlite.zip"
	fetch "https://downloads.wordpress.org/plugin/woocommerce.latest-stable.zip" "$DL/woocommerce.zip"
	fetch "https://github.com/wp-cli/wp-cli/releases/download/v2.11.0/wp-cli-2.11.0.phar" "$WPCLI" \
		"https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar"
	if [[ "$THEME" == "hello-elementor" ]]; then
		fetch "https://downloads.wordpress.org/theme/hello-elementor.latest-stable.zip" "$DL/hello-elementor.zip" || log "theme download failed; default theme stays"
	fi

	if [[ ! -f "$SITE/wp-includes/version.php" ]]; then
		log "unpack WordPress"
		local tmp; tmp="$(mktemp -d "$WP_DIR/unz.XXXX")"
		unzip -q "$DL/wordpress.zip" -d "$tmp"
		rm -rf "$SITE"; mv "$tmp/wordpress" "$SITE"; rm -rf "$tmp"
	fi
	local plugins="$SITE/wp-content/plugins"
	[[ -d "$plugins/sqlite-database-integration" ]] || { log "unpack sqlite-database-integration"; unzip -q "$DL/sqlite.zip" -d "$plugins"; }
	[[ -d "$plugins/woocommerce" ]] || { log "unpack WooCommerce"; unzip -q "$DL/woocommerce.zip" -d "$plugins"; }
	if [[ -s "$DL/hello-elementor.zip" && ! -d "$SITE/wp-content/themes/hello-elementor" ]]; then
		unzip -q "$DL/hello-elementor.zip" -d "$SITE/wp-content/themes"
	fi
	cp "$plugins/sqlite-database-integration/db.copy" "$SITE/wp-content/db.php"
	mkdir -p "$SITE/wp-content/database" "$SITE/wp-content/mu-plugins"
	cp "$SCRIPT_DIR/lib/mu-bps-test-env.php" "$SITE/wp-content/mu-plugins/bps-test-env.php"

	if [[ ! -f "$SITE/wp-config.php" ]]; then
		log "write wp-config.php"
		local salts; salts="$(php -r 'foreach (["AUTH_KEY","SECURE_AUTH_KEY","LOGGED_IN_KEY","NONCE_KEY","AUTH_SALT","SECURE_AUTH_SALT","LOGGED_IN_SALT","NONCE_SALT"] as $k) { printf("define( %s, %s );\n", var_export($k, true), var_export(bin2hex(random_bytes(32)), true)); }')"
		cat >"$SITE/wp-config.php" <<PHP
<?php
// Local Patch Studio test site (SQLite via the sqlite-database-integration drop-in). Generated by start-wp.sh.
define( 'DB_NAME', 'wordpress' );
define( 'DB_USER', '' );
define( 'DB_PASSWORD', '' );
define( 'DB_HOST', 'localhost' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );
define( 'DB_DIR', __DIR__ . '/wp-content/database/' );
define( 'DB_FILE', '.ht.sqlite' );
$salts
\$table_prefix = 'wp_';
define( 'WP_HOME', '$URL' );
define( 'WP_SITEURL', '$URL' );
define( 'WP_ENVIRONMENT_TYPE', 'local' );
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', __DIR__ . '/wp-content/debug.log' );
define( 'WP_DEBUG_DISPLAY', false );
define( 'SCRIPT_DEBUG', false );
define( 'DISABLE_WP_CRON', true );
define( 'WP_HTTP_BLOCK_EXTERNAL', true );
define( 'AUTOMATIC_UPDATER_DISABLED', true );
define( 'WP_AUTO_UPDATE_CORE', false );
define( 'FS_METHOD', 'direct' );
define( 'WP_MEMORY_LIMIT', '512M' );
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
require_once ABSPATH . 'wp-settings.php';
PHP
	fi

	if ! wp core is-installed 2>/dev/null; then
		log "install WordPress"
		wp core install --title="Borduurservice België (test)" --admin_user="$ADMIN_USER" \
			--admin_password="$ADMIN_PASS" --admin_email="$ADMIN_EMAIL" --skip-email >/dev/null
	fi
	# Keep the admin password in sync with .env (idempotent).
	wp user update "$ADMIN_USER" --user_pass="$ADMIN_PASS" --skip-email >/dev/null 2>&1 || true
}

# Language packs via curl (WP's own HTTP API is blocked with WP_HTTP_BLOCK_EXTERNAL). Non-fatal.
install_languages() {
	local langdir="$SITE/wp-content/languages" ver wcver lang kind slug pkg zip
	[[ -f "$langdir/nl_BE.mo" && -f "$langdir/plugins/woocommerce-nl_BE.mo" ]] && return 0
	mkdir -p "$langdir/plugins" "$DL/lang"
	ver="$(php -r 'include $argv[1]; echo $wp_version;' "$SITE/wp-includes/version.php")"
	wcver="$(sed -n 's/^ \* Version: *//p' "$SITE/wp-content/plugins/woocommerce/woocommerce.php" | head -1)"
	for kind in core woocommerce; do
		local api
		if [[ "$kind" == core ]]; then api="https://api.wordpress.org/translations/core/1.0/?version=$ver"; slug=core
		else api="https://api.wordpress.org/translations/plugins/1.0/?slug=woocommerce&version=$wcver"; slug=woocommerce; fi
		curl -sSf --max-time 20 -o "$DL/lang/$slug.json" "$api" 2>/dev/null || { log "translations API unreachable ($slug)"; continue; }
		for lang in nl_BE fr_BE fr_FR; do
			pkg="$(php -r '$d=json_decode(file_get_contents($argv[1]),true); foreach(($d["translations"]??[]) as $t){ if($t["language"]===$argv[2]){ echo $t["package"]; break; } }' "$DL/lang/$slug.json" "$lang")"
			[[ -n "$pkg" ]] || continue
			zip="$DL/lang/$slug-$lang.zip"
			fetch "$pkg" "$zip" || continue
			if [[ "$kind" == core ]]; then unzip -oq "$zip" -d "$langdir"; else unzip -oq "$zip" -d "$langdir/plugins"; fi
		done
	done
}

link_plugin() {
	[[ -f "$PLUGIN_SRC/borduur-patch-studio.php" ]] || die "plugin not found at $PLUGIN_SRC"
	local link="$SITE/wp-content/plugins/borduur-patch-studio"
	if [[ -L "$link" ]]; then
		[[ "$(readlink "$link")" == "$PLUGIN_SRC" ]] || { rm "$link"; ln -s "$PLUGIN_SRC" "$link"; }
	else
		rm -rf "$link"; ln -s "$PLUGIN_SRC" "$link"
	fi
	[[ -f "$PLUGIN_SRC/assets/dist/patch-studio.js" ]] || log "WARNING: assets/dist/patch-studio.js missing; run 'npm run build' in $PROJECT_DIR"
}

configure() {
	log "activate plugins and configure the shop ($CHECKOUT checkout, theme $THEME, HPOS $HPOS)"
	wp plugin activate sqlite-database-integration woocommerce >/dev/null 2>&1 || die "activating WooCommerce failed"
	if [[ -d "$SITE/wp-content/themes/$THEME" ]]; then wp theme activate "$THEME" >/dev/null 2>&1 || true; fi
	wp option update permalink_structure '/%postname%/' >/dev/null
	if [[ -f "$SITE/wp-content/languages/nl_BE.mo" ]]; then wp option update WPLANG nl_BE >/dev/null; fi
	wp option update woocommerce_custom_orders_table_enabled "$([[ "$HPOS" == 1 ]] && echo yes || echo no)" >/dev/null
	wp option update woocommerce_custom_orders_table_data_sync_enabled yes >/dev/null
	wp eval-file "$SCRIPT_DIR/lib/wp-setup.php" "$CHECKOUT" >"$WP_DIR/setup.json"
	# Activate Patch Studio last so its activation hook sees a configured WooCommerce.
	if ! wp plugin is-active borduur-patch-studio 2>/dev/null; then
		wp plugin activate borduur-patch-studio >/dev/null || die "activating borduur-patch-studio failed (see $SITE/wp-content/debug.log)"
	fi
	wp rewrite flush >/dev/null
	wp eval 'if ( class_exists( "\\BorduurPatchStudio\\Woo\\Integration" ) && method_exists( "\\BorduurPatchStudio\\Woo\\Integration", "activate" ) ) { \BorduurPatchStudio\Woo\Integration::activate(); } echo (int) get_option( "bps_product_id" );' >"$WP_DIR/product-id.txt" 2>/dev/null || true
	wp cache flush >/dev/null 2>&1 || true
}

summary() {
	cat >&2 <<TXT
[start-wp] ready
  Studio:   $URL/patch-studio/
  Admin:    $URL/wp-admin/   ($ADMIN_USER / $ADMIN_PASS)
  Cart:     $URL/winkelmand/   Checkout: $URL/afrekenen/ ($CHECKOUT)
  Product:  $(cat "$WP_DIR/product-id.txt" 2>/dev/null || echo '?')
  Mails:    $SITE/wp-content/bps-mails/   Debug log: $SITE/wp-content/debug.log
  Server:   pid $(cat "$PIDFILE" 2>/dev/null || echo '?'), log $LOG
TXT
}

cmd="${1:-start}"; [[ $# -gt 0 ]] && shift
case "$cmd" in
	start)   install_site; install_languages; link_plugin; configure; start_server; summary ;;
	stop)    stop_server ;;
	restart) stop_server; install_site; install_languages; link_plugin; configure; start_server; summary ;;
	status)  if server_up; then echo "up $URL"; else echo "down"; exit 1; fi ;;
	reset)   stop_server; rm -rf "$SITE" "$WP_DIR/setup.json" "$WP_DIR/product-id.txt"; install_site; install_languages; link_plugin; configure; start_server; summary ;;
	url)     echo "$URL/patch-studio/" ;;
	wp)      wp "$@" ;;
	*)       die "unknown command: $cmd" ;;
esac
