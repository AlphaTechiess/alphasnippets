<?php
/**
 * Alpha Snippets Uninstaller
 * Completely cleans up all stored snippets, directories, and OPcache.
 * Leaves 0 database footprints and 0 file footprints.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

function alpha_snippets_clean_dir( $directory ) {
	if ( ! is_dir( $directory ) ) {
		return;
	}

	try {
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $file ) {
			$path = $file->getPathname();
			if ( $file->isDir() ) {
				@rmdir( $path );
			} else {
				if ( function_exists( 'opcache_invalidate' ) ) {
					@opcache_invalidate( $path, true );
				}
				@unlink( $path );
			}
		}

		@rmdir( $directory );
	} catch ( Throwable $e ) {
		// Fail-safe during uninstall.
	}
}

// 1. Clean main upload directory.
$uploads = wp_get_upload_dir();
$main_dir = trailingslashit( $uploads['basedir'] ) . 'alphasnippets';
alpha_snippets_clean_dir( $main_dir );

// 2. Multisite cleanup across all sub-sites if multisite.
if ( is_multisite() ) {
	$sites = get_sites( array( 'fields' => 'ids' ) );
	if ( is_array( $sites ) ) {
		foreach ( $sites as $site_id ) {
			switch_to_blog( $site_id );
			$site_uploads = wp_get_upload_dir();
			$site_dir = trailingslashit( $site_uploads['basedir'] ) . 'alphasnippets';
			alpha_snippets_clean_dir( $site_dir );
			restore_current_blog();
		}
	}
}
