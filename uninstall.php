<?php
// Remove Greenlight's settings and self-hosted fonts when the plugin is deleted.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;
delete_option( 'greenlight_settings' );
$greenlight_dir = trailingslashit( wp_get_upload_dir()['basedir'] ) . 'greenlight';
if ( is_dir( $greenlight_dir ) ) {
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $greenlight_dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $it as $f ) {
		$f->isDir() ? rmdir( $f->getPathname() ) : unlink( $f->getPathname() );
	}
	rmdir( $greenlight_dir );
}
