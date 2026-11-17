<?php
/**
 * Router for `php -S` so WordPress pretty permalinks work without Apache/nginx.
 * Usage: php -S 127.0.0.1:8899 -t <wp-root> tests/e2e/lib/router.php
 */

$root = rtrim( $_SERVER['DOCUMENT_ROOT'], '/' );
$path = rawurldecode( (string) parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ) );

// Block path traversal and the private upload folder (php -S ignores .htaccess, see Uploads::self_test()).
if ( false !== strpos( $path, '..' ) || 0 === strpos( $path, '/wp-content/uploads/patch-studio/private/' ) ) {
	http_response_code( 403 );
	echo 'Forbidden';
	return true;
}

$file = $root . $path;

if ( '/' !== $path && is_file( $file ) ) {
	if ( '.php' === substr( $path, -4 ) ) {
		$_SERVER['SCRIPT_NAME']     = $path;
		$_SERVER['SCRIPT_FILENAME'] = $file;
		$_SERVER['PHP_SELF']        = $path;
		chdir( dirname( $file ) );
		require $file;
		return true;
	}
	return false; // Let the built-in server stream static files.
}

if ( is_dir( $file ) && is_file( rtrim( $file, '/' ) . '/index.php' ) ) {
	if ( '/' !== substr( $path, -1 ) ) {
		header( 'Location: ' . $path . '/', true, 301 );
		return true;
	}
	$index                      = rtrim( $file, '/' ) . '/index.php';
	$_SERVER['SCRIPT_NAME']     = rtrim( $path, '/' ) . '/index.php';
	$_SERVER['SCRIPT_FILENAME'] = $index;
	$_SERVER['PHP_SELF']        = $_SERVER['SCRIPT_NAME'];
	chdir( dirname( $index ) );
	require $index;
	return true;
}

$_SERVER['SCRIPT_NAME']     = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $root . '/index.php';
$_SERVER['PHP_SELF']        = '/index.php';
chdir( $root );
require $root . '/index.php';
return true;
