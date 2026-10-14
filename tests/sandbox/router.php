<?php
// PHP built-in server router for WordPress: serve real files, everything else goes to index.php.
$root = __DIR__ . '/wordpress';
$path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
if ( $path !== '/' && file_exists( $root . $path ) && ! is_dir( $root . $path ) ) { return false; }
if ( is_dir( $root . $path ) && file_exists( $root . rtrim( $path, '/' ) . '/index.php' ) ) { chdir( $root . $path ); require $root . rtrim( $path, '/' ) . '/index.php'; return; }
chdir( $root ); require $root . '/index.php';
