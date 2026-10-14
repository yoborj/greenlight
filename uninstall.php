<?php
// Remove Speed Kit's settings and self-hosted fonts when the plugin is deleted.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;
delete_option( 'speedkit_settings' );
$speedkit_dir = trailingslashit( wp_get_upload_dir()['basedir'] ) . 'speed-kit';
if ( is_dir( $speedkit_dir ) ) {
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $speedkit_dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $it as $f ) {
		$f->isDir() ? rmdir( $f->getPathname() ) : unlink( $f->getPathname() );
	}
	rmdir( $speedkit_dir );
}
