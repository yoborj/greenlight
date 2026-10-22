<?php
// Remove Greenlight's settings, security state and self-hosted fonts when the plugin is deleted.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;
foreach ( array( 'greenlight_settings', 'greenlight_alerts', 'greenlight_scripts', 'greenlight_core_check', 'greenlight_content_state' ) as $greenlight_option ) {
	delete_option( $greenlight_option );
}
delete_post_meta_by_key( '_greenlight_content_hash' );
wp_clear_scheduled_hook( 'greenlight_security_daily' );
wp_clear_scheduled_hook( 'greenlight_security_batch' );
$greenlight_dir = trailingslashit( wp_get_upload_dir()['basedir'] ) . 'greenlight';
if ( is_dir( $greenlight_dir ) ) {
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $greenlight_dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $it as $f ) {
		$f->isDir() ? rmdir( $f->getPathname() ) : unlink( $f->getPathname() );
	}
	rmdir( $greenlight_dir );
}
