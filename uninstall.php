<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$uploads   = wp_get_upload_dir();
$directory = trailingslashit( $uploads['basedir'] ) . 'alphasnippets';

if ( is_dir( $directory ) ) {
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);

	foreach ( $iterator as $file ) {
		$file->isDir() ? @rmdir( $file->getPathname() ) : @unlink( $file->getPathname() );
	}

	@rmdir( $directory );
}
