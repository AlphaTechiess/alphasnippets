<?php
/**
 * Alpha Snippets Core Engine
 * Production Ready with OPcache atomic storage, Safe Mode, Syntax Validation,
 * and infinite recursion protection.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Alpha_Snippets_Core {
	private static $dir = null;
	private static $all = null;
	private static $runtime = array();
	private static $capability = null;
	private static $executing = array();

	/**
	 * Get snippet directory in uploads folder.
	 */
	public static function dir() {
		if ( null !== self::$dir ) {
			return self::$dir;
		}

		$uploads = wp_get_upload_dir();
		return self::$dir = trailingslashit( $uploads['basedir'] ) . 'alphasnippets/';
	}

	/**
	 * Check if Safe Mode is enabled.
	 * Can be enabled via constant ALPHA_SNIPPETS_SAFE_MODE or ?alphasnippets_safe_mode=1 by admin.
	 */
	public static function is_safe_mode() {
		if ( defined( 'ALPHA_SNIPPETS_SAFE_MODE' ) && ALPHA_SNIPPETS_SAFE_MODE ) {
			return true;
		}

		if ( isset( $_GET['alphasnippets_safe_mode'] ) && '1' === (string) $_GET['alphasnippets_safe_mode'] ) {
			if ( is_user_logged_in() && current_user_can( self::capability() ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Boot Alpha Snippets.
	 */
	public static function boot() {
		if ( is_admin() ) {
			add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
			add_action( 'admin_init', array( __CLASS__, 'post' ) );
			add_action( 'wp_ajax_as_toggle_edit', array( __CLASS__, 'ajax_toggle_edit' ) );
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ) );
			add_action( 'admin_head', array( __CLASS__, 'chrome' ) );
			add_filter( 'plugin_row_meta', array( __CLASS__, 'plugin_row_meta' ), 10, 4 );

			if ( self::is_safe_mode() ) {
				add_action( 'admin_notices', array( __CLASS__, 'safe_mode_notice' ) );
				return;
			}

			if ( self::has_runtime( 'php', true ) ) {
				self::php();
			}
			if ( self::has_runtime( 'css', true ) ) {
				add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_inline_assets' ), 20 );
			}
			if ( self::has_runtime( 'js', true ) ) {
				add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_script_assets' ), 20 );
			}
			return;
		}

		if ( self::is_safe_mode() ) {
			return;
		}

		if ( self::has_runtime( 'php', false ) ) {
			self::php();
		}
		if ( self::has_runtime( 'htmlphp', false ) ) {
			self::content_hooks();
		}
		if ( self::has_runtime( 'css', false ) ) {
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		}
		if ( self::has_runtime( 'js', false ) ) {
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'script_assets' ) );
		}
	}

	/**
	 * Plugin installation hook.
	 */
	public static function install() {
		if ( ! self::ensure() ) {
			return;
		}

		if ( ! is_file( self::dir() . 'manifest.php' ) ) {
			self::manifest( array() );
		}
	}

	/**
	 * Ensure upload directory and safety files exist.
	 */
	private static function ensure() {
		$dir = self::dir();

		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		$index_html = $dir . 'index.html';
		if ( ! is_file( $index_html ) ) {
			@file_put_contents( $index_html, '', LOCK_EX );
		}

		$index_php = $dir . 'index.php';
		if ( ! is_file( $index_php ) ) {
			@file_put_contents( $index_php, "<?php\n// Silence is golden.\n", LOCK_EX );
		}

		$htaccess = $dir . '.htaccess';
		if ( ! is_file( $htaccess ) ) {
			@file_put_contents(
				$htaccess,
				"Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
				LOCK_EX
			);
		}

		$webconfig = $dir . 'web.config';
		if ( ! is_file( $webconfig ) ) {
			@file_put_contents(
				$webconfig,
				"<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><directoryBrowse enabled=\"false\"/><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
				LOCK_EX
			);
		}

		return is_dir( $dir ) && is_readable( $dir ) && is_writable( $dir );
	}

	/**
	 * Get all snippets ordered by priority.
	 */
	public static function all() {
		if ( null !== self::$all ) {
			return self::$all;
		}

		$file = self::dir() . 'manifest.php';
		if ( ! is_file( $file ) || ! is_readable( $file ) ) {
			return self::$all = array();
		}

		$data = include $file;
		if ( ! is_array( $data ) ) {
			return self::$all = array();
		}

		usort(
			$data,
			function ( $a, $b ) {
				$priority_a = (int) ( $a['priority'] ?? 10 );
				$priority_b = (int) ( $b['priority'] ?? 10 );

				if ( $priority_a === $priority_b ) {
					return strcmp( (string) ( $a['id'] ?? '' ), (string) ( $b['id'] ?? '' ) );
				}

				return $priority_a <=> $priority_b;
			}
		);

		return self::$all = $data;
	}

	/**
	 * Get snippets filtered by runtime context.
	 */
	public static function runtime( $type, $admin ) {
		$key = $type . ':' . ( $admin ? '1' : '0' );
		if ( array_key_exists( $key, self::$runtime ) ) {
			return self::$runtime[ $key ];
		}

		$runtime = array();
		foreach ( self::all() as $snippet ) {
			if ( empty( $snippet['active'] ) || ( $snippet['type'] ?? '' ) !== $type ) {
				continue;
			}

			if ( 'htmlphp' === $type ) {
				if ( ! $admin ) {
					$runtime[] = $snippet;
				}
				continue;
			}

			if ( 'js' === $type ) {
				$where = $snippet['where'] ?? 'footer';
				$allowed = $admin
					? in_array( $where, array( 'admin_header', 'admin_footer', 'everywhere', 'admin' ), true )
					: in_array( $where, array( 'header', 'footer', 'everywhere', 'frontend' ), true );

				if ( $allowed ) {
					$runtime[] = $snippet;
				}
				continue;
			}

			$where = $snippet['where'] ?? 'everywhere';
			if ( 'everywhere' !== $where && ( $admin ? 'admin' !== $where : 'frontend' !== $where ) ) {
				continue;
			}

			$runtime[] = $snippet;
		}

		return self::$runtime[ $key ] = $runtime;
	}

	/**
	 * Check if any snippet of a given type is active for this context.
	 */
	public static function has_runtime( $type, $admin ) {
		return ! empty( self::runtime( $type, $admin ) );
	}

	/**
	 * Get a single snippet by ID.
	 */
	public static function one( $id ) {
		foreach ( self::all() as $snippet ) {
			if ( ( $snippet['id'] ?? '' ) === $id ) {
				return $snippet;
			}
		}
		return null;
	}

	/**
	 * Sanitize and clean snippet code before saving.
	 */
	public static function clean( $code, $type ) {
		$code = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $code );

		if ( 'php' === $type ) {
			$code = preg_replace( '/^\s*<\?php\s*/i', '', $code, 1 );
			$code = preg_replace(
				'/^\s*(?:defined\s*\(\s*["\']ABSPATH["\']\s*\)\s*\|\|\s*exit\s*;|if\s*\(\s*!defined\s*\(\s*["\']ABSPATH["\']\s*\)\s*\)\s*exit\s*;)\s*/i',
				'',
				$code,
				1
			);
			$code = preg_replace( '/\s*\?>\s*$/i', '', $code, 1 );
		} elseif ( 'htmlphp' === $type ) {
			$code = preg_replace(
				'/^\s*<\?php\s*(?:defined\s*\(\s*["\']ABSPATH["\']\s*\)\s*\|\|\s*exit\s*;|if\s*\(\s*!defined\s*\(\s*["\']ABSPATH["\']\s*\)\s*\)\s*exit\s*;)\s*\?>\s*/i',
				'',
				$code,
				1
			);
			$code = preg_replace( '/^\s*<!--\s*begin content\s*-->\s*/i', '', $code, 1 );
			$code = preg_replace( '/\s*<!--\s*end content\s*-->\s*$/i', '', $code, 1 );
		} elseif ( 'css' === $type ) {
			$code = preg_replace( '/^\s*<style[^>]*>\s*/i', '', $code, 1 );
			$code = preg_replace( '/\s*<\/style>\s*$/i', '', $code, 1 );
		} else {
			$code = preg_replace( '/^\s*<script[^>]*>\s*/i', '', $code, 1 );
			$code = preg_replace( '/\s*<\/script>\s*$/i', '', $code, 1 );
		}

		return $code;
	}

	/**
	 * Validate PHP syntax safely without executing code.
	 * Returns true if valid, or an error string if invalid.
	 */
	public static function validate_php( $code ) {
		$code = trim( (string) $code );
		if ( '' === $code ) {
			return true;
		}

		$test_code = "<?php\n" . $code;

		if ( defined( 'TOKEN_PARSE' ) ) {
			try {
				@token_get_all( $test_code, TOKEN_PARSE );
			} catch ( ParseError $e ) {
				return $e->getMessage() . ' on line ' . max( 1, $e->getLine() - 1 );
			} catch ( Throwable $e ) {
				return $e->getMessage();
			}
		} else {
			$tokens = @token_get_all( $test_code );
			$braces = 0;
			$parens = 0;
			$brackets = 0;
			foreach ( $tokens as $token ) {
				if ( is_string( $token ) ) {
					if ( '{' === $token ) {
						$braces++;
					} elseif ( '}' === $token ) {
						$braces--;
						if ( $braces < 0 ) {
							return 'Unmatched closing brace }';
						}
					} elseif ( '(' === $token ) {
						$parens++;
					} elseif ( ')' === $token ) {
						$parens--;
						if ( $parens < 0 ) {
							return 'Unmatched closing parenthesis )';
						}
					} elseif ( '[' === $token ) {
						$brackets++;
					} elseif ( ']' === $token ) {
						$brackets--;
						if ( $brackets < 0 ) {
							return 'Unmatched closing bracket ]';
						}
					}
				}
			}
			if ( $braces > 0 ) {
				return 'Unclosed opening brace {';
			}
			if ( $parens > 0 ) {
				return 'Unclosed opening parenthesis (';
			}
			if ( $brackets > 0 ) {
				return 'Unclosed opening bracket [';
			}
		}

		return true;
	}

	/**
	 * Save a snippet atomically.
	 */
	public static function save( $data, $id = '' ) {
		$id = $id ?: wp_generate_uuid4();
		$type = self::normalize_type( $data['type'] ?? 'php' );
		$where = self::normalize_where( $type, $data['where'] ?? '' );
		$filename = 'snippet-' . preg_replace( '/[^a-z0-9]/i', '', $id ) . '.' . ( 'css' === $type ? 'css' : ( 'js' === $type ? 'js' : 'php' ) );
		$old = self::one( $id );
		$raw_code = $data['code'] ?? '';
		$code = self::clean( $raw_code, $type );

		if ( 'php' === $type ) {
			$code = "<?php\ndefined('ABSPATH')||exit;\n" . $code;
		} elseif ( 'htmlphp' === $type ) {
			$code = "<?php defined('ABSPATH')||exit; ?>" . $code;
		}

		if ( ! self::ensure() ) {
			return false;
		}

		$path = self::dir() . $filename;
		$old_file_contents = is_file( $path ) ? @file_get_contents( $path ) : false;

		if ( ! self::write_atomic( $path, $code ) ) {
			return false;
		}

		$snippet = array(
			'id'       => $id,
			'name'     => sanitize_text_field( $data['name'] ?? 'Untitled Snippet' ),
			'type'     => $type,
			'priority' => max( 1, min( 100, (int) ( $data['priority'] ?? 10 ) ) ),
			'where'    => $where,
			'active'   => array_key_exists( 'active', $data ) ? (bool) $data['active'] : false,
			'file'     => $filename,
		);

		$all = self::all();
		$found = false;
		foreach ( $all as $key => $value ) {
			if ( ( $value['id'] ?? '' ) === $id ) {
				$all[ $key ] = $snippet;
				$found = true;
				break;
			}
		}
		if ( ! $found ) {
			$all[] = $snippet;
		}

		if ( ! self::manifest( $all ) ) {
			if ( false !== $old_file_contents ) {
				self::write_atomic( $path, $old_file_contents );
			} else {
				@unlink( $path );
			}
			return false;
		}

		if ( $old && ( $old['file'] ?? '' ) !== $filename ) {
			self::delete_file( $old['file'] );
		}

		self::reset_cache();
		return $snippet;
	}

	/**
	 * Fast toggle snippet active status without rewriting code files.
	 */
	public static function toggle_active( $id ) {
		$all = self::all();
		$found = false;
		$new_state = false;

		foreach ( $all as $index => $snippet ) {
			if ( ( $snippet['id'] ?? '' ) === $id ) {
				$new_state = empty( $snippet['active'] );
				$all[ $index ]['active'] = $new_state;
				$found = true;
				break;
			}
		}

		if ( ! $found ) {
			return false;
		}

		if ( ! self::manifest( $all ) ) {
			return false;
		}

		self::reset_cache();
		return $new_state;
	}

	/**
	 * Write file atomically with OPcache invalidation and Windows safety.
	 */
	private static function write_atomic( $path, $body ) {
		$temp = $path . '.tmp.' . wp_generate_uuid4();
		if ( false === @file_put_contents( $temp, $body, LOCK_EX ) ) {
			@unlink( $temp );
			return false;
		}

		@chmod( $temp, 0644 );

		if ( DIRECTORY_SEPARATOR === '\\' && is_file( $path ) ) {
			@unlink( $path );
		}

		if ( @rename( $temp, $path ) ) {
			if ( function_exists( 'opcache_invalidate' ) ) {
				opcache_invalidate( $path, true );
			}
			return true;
		}

		$copied = @copy( $temp, $path );
		@unlink( $temp );

		if ( $copied && function_exists( 'opcache_invalidate' ) ) {
			opcache_invalidate( $path, true );
		}

		return $copied;
	}

	/**
	 * Update manifest atomically and invalidate OPcache.
	 */
	private static function manifest( $snippets ) {
		if ( ! self::ensure() ) {
			return false;
		}

		usort(
			$snippets,
			function ( $left, $right ) {
				$priority_left = (int) ( $left['priority'] ?? 10 );
				$priority_right = (int) ( $right['priority'] ?? 10 );

				if ( $priority_left === $priority_right ) {
					return strcmp( (string) ( $left['id'] ?? '' ), (string) ( $right['id'] ?? '' ) );
				}

				return $priority_left <=> $priority_right;
			}
		);

		$body = "<?php\nif(!defined('ABSPATH'))exit;return " . var_export( array_values( $snippets ), true ) . ";\n";
		$manifest = self::dir() . 'manifest.php';
		$temp = self::dir() . 'manifest.php.tmp.' . wp_generate_uuid4();

		if ( false === @file_put_contents( $temp, $body, LOCK_EX ) ) {
			return false;
		}

		@chmod( $temp, 0644 );

		if ( DIRECTORY_SEPARATOR === '\\' && is_file( $manifest ) ) {
			@unlink( $manifest );
		}

		$written = false;
		if ( @rename( $temp, $manifest ) ) {
			$written = true;
		} else {
			$written = @copy( $temp, $manifest );
			@unlink( $temp );
		}

		if ( $written && function_exists( 'opcache_invalidate' ) ) {
			opcache_invalidate( $manifest, true );
		}

		return $written;
	}

	/**
	 * Delete a snippet.
	 */
	public static function delete( $id ) {
		$remaining = array();
		$removed = null;

		foreach ( self::all() as $snippet ) {
			if ( ( $snippet['id'] ?? '' ) === $id ) {
				$removed = $snippet;
				continue;
			}
			$remaining[] = $snippet;
		}

		if ( ! $removed ) {
			return false;
		}

		if ( ! self::manifest( $remaining ) ) {
			return false;
		}

		self::delete_file( $removed['file'] ?? '' );
		self::reset_cache();
		return true;
	}

	/**
	 * Execute PHP snippets.
	 */
	public static function php() {
		foreach ( self::runtime( 'php', is_admin() ) as $snippet ) {
			$path = self::snippet_path( $snippet );
			if ( ! $path ) {
				continue;
			}

			try {
				include_once $path;
			} catch ( Throwable $error ) {
				self::log_error( $error );
			}
		}
	}

	/**
	 * Render content snippet with infinite recursion protection.
	 */
	public static function render_content_snippet( $snippet ) {
		$path = self::snippet_path( $snippet );
		if ( ! $path ) {
			return '';
		}

		$id = $snippet['id'] ?? '';
		if ( '' !== $id && isset( self::$executing[ $id ] ) ) {
			return '';
		}

		if ( '' !== $id ) {
			self::$executing[ $id ] = true;
		}

		ob_start();
		try {
			include $path;
			return (string) ob_get_clean();
		} catch ( Throwable $error ) {
			ob_end_clean();
			self::log_error( $error );
			return '';
		} finally {
			if ( '' !== $id ) {
				unset( self::$executing[ $id ] );
			}
		}
	}

	/**
	 * Content snippet hooks.
	 */
	public static function content_hooks() {
		if ( is_admin() ) {
			return;
		}

		$has_shortcode = false;
		foreach ( self::runtime( 'htmlphp', false ) as $snippet ) {
			$where = $snippet['where'] ?? 'shortcode';

			switch ( $where ) {
				case 'header':
					add_action(
						'wp_head',
						function () use ( $snippet ) {
							echo self::render_content_snippet( $snippet );
						},
						99
					);
					break;
				case 'body_open':
					add_action(
						'wp_body_open',
						function () use ( $snippet ) {
							echo self::render_content_snippet( $snippet );
						},
						99
					);
					break;
				case 'footer':
					add_action(
						'wp_footer',
						function () use ( $snippet ) {
							echo self::render_content_snippet( $snippet );
						},
						99
					);
					break;
				case 'before_content':
				case 'after_content':
					add_filter(
						'the_content',
						function ( $content ) use ( $snippet, $where ) {
							if ( ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
								return $content;
							}

							$output = self::render_content_snippet( $snippet );
							return 'before_content' === $where ? $output . $content : $content . $output;
						},
						10
					);
					break;
				case 'shortcode':
				default:
					$has_shortcode = true;
					break;
			}
		}

		if ( $has_shortcode ) {
			add_shortcode( 'alpha_snippet', array( __CLASS__, 'shortcode' ) );
		}
	}

	/**
	 * Alpha snippet shortcode handler with recursion guard.
	 */
	public static function shortcode( $atts = array(), $content = null ) {
		$atts = shortcode_atts( array( 'id' => '' ), $atts, 'alpha_snippet' );
		$id = sanitize_text_field( $atts['id'] ?? '' );

		if ( '' === $id ) {
			return '';
		}

		$snippet = self::one( $id );
		if ( ! $snippet || empty( $snippet['active'] ) || 'htmlphp' !== ( $snippet['type'] ?? '' ) || 'shortcode' !== ( $snippet['where'] ?? '' ) ) {
			return '';
		}

		return self::render_content_snippet( $snippet );
	}

	public static function assets() {
		self::inline( 'css', false );
	}

	public static function admin_inline_assets() {
		self::inline( 'css', true );
	}

	/**
	 * Enqueue JavaScript snippets cleanly (newline fixed).
	 */
	private static function scripts( $admin ) {
		$groups = array( 'header' => '', 'footer' => '' );
		foreach ( self::runtime( 'js', $admin ) as $snippet ) {
			$where = $snippet['where'] ?? 'footer';
			if ( $admin ) {
				if ( 'everywhere' === $where || 'admin' === $where ) {
					$where = 'footer';
				}
				if ( ! in_array( $where, array( 'admin_header', 'admin_footer' ), true ) ) {
					continue;
				}
				$key = 'admin_header' === $where ? 'header' : 'footer';
			} else {
				if ( in_array( $where, array( 'admin_header', 'admin_footer' ), true ) ) {
					continue;
				}
				$key = 'header' === $where ? 'header' : 'footer';
			}

			$path = self::snippet_path( $snippet );
			if ( ! $path ) {
				continue;
			}
			$code = @file_get_contents( $path );
			if ( false !== $code && '' !== $code ) {
				$groups[ $key ] .= ( '' === $groups[ $key ] ? '' : "\n" ) . $code;
			}
		}

		foreach ( $groups as $location => $code ) {
			if ( '' === $code ) {
				continue;
			}
			$handle = 'alphasnippets-' . ( $admin ? 'admin-' : '' ) . 'js-' . $location;
			$in_footer = 'footer' === $location;
			wp_register_script( $handle, false, array(), ALPHA_SNIPPETS_VERSION, $in_footer );
			wp_enqueue_script( $handle );
			wp_add_inline_script( $handle, $code );
		}
	}

	public static function script_assets() {
		self::scripts( false );
	}

	public static function admin_script_assets() {
		self::scripts( true );
	}

	/**
	 * Enqueue admin scripts & styles.
	 */
	public static function admin_assets() {
		$page = sanitize_key( $_GET['page'] ?? '' );
		if ( 0 !== strpos( $page, 'alphasnippets' ) ) {
			return;
		}

		$GLOBALS['alpha_snippets_page'] = true;

		wp_enqueue_style( 'alphasnippets-admin', ALPHA_SNIPPETS_URL . 'assets/admin.min.css', array(), ALPHA_SNIPPETS_VERSION );
		wp_enqueue_script( 'alphasnippets-admin', ALPHA_SNIPPETS_URL . 'assets/admin.min.js', array(), ALPHA_SNIPPETS_VERSION, true );

		wp_localize_script(
			'alphasnippets-admin',
			'ASAdmin',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'toggleNonce' => wp_create_nonce( 'as_toggle_edit' ),
				'i18n'        => array(
					'confirmDelete' => esc_html__( 'Delete this snippet permanently?', 'alphasnippets' ),
				),
			)
		);
	}

	private static function inline( $type, $admin ) {
		$code = '';
		foreach ( self::runtime( $type, $admin ) as $snippet ) {
			$path = self::snippet_path( $snippet );
			if ( ! $path ) {
				continue;
			}

			$value = @file_get_contents( $path );
			if ( false !== $value && '' !== $value ) {
				$code .= "\n" . $value;
			}
		}

		if ( '' === $code ) {
			return;
		}

		$handle = 'alphasnippets-inline-' . $type . ( $admin ? '-admin' : '' );
		if ( 'css' === $type ) {
			wp_register_style( $handle, false, array(), ALPHA_SNIPPETS_VERSION );
			wp_enqueue_style( $handle );
			wp_add_inline_style( $handle, $code );
		}
	}

	public static function plugin_row_meta( $meta, $plugin_file ) {
		if ( plugin_basename( ALPHA_SNIPPETS_FILE ) !== $plugin_file ) {
			return $meta;
		}

		$meta[] = '<a href="' . esc_url( admin_url( 'admin.php?page=alphasnippets-new' ) ) . '">' . esc_html__( 'New Snippet', 'alphasnippets' ) . '</a>';
		$meta[] = '<a href="' . esc_url( 'https://github.com/AlphaTechiess/alphasnippets' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'GitHub', 'alphasnippets' ) . '</a>';
		return $meta;
	}

	public static function menu() {
		add_menu_page( 'Alpha Snippets', 'Alpha Snippets', self::capability(), 'alphasnippets', array( __CLASS__, 'home' ), 'dashicons-editor-code', 58 );
		add_submenu_page( 'alphasnippets', 'All Snippets', 'All Snippets', self::capability(), 'alphasnippets', array( __CLASS__, 'home' ) );
		add_submenu_page( 'alphasnippets', 'New Snippet', 'New Snippet', self::capability(), 'alphasnippets-new', array( __CLASS__, 'edit' ) );
	}

	/**
	 * Admin header styling adjustments.
	 */
	public static function chrome() {
		if ( empty( $GLOBALS['alpha_snippets_page'] ) ) {
			return;
		}

		echo '<style>html,body{background:#11171d!important;overflow-x:hidden!important}#wpcontent,#wpbody,#wpbody-content{background:#11171d!important}#wpcontent{padding-left:0!important}#wpfooter{display:none!important}#wpbody-content{padding-bottom:0!important}</style>';
	}

	/**
	 * Safe mode admin notice banner.
	 */
	public static function safe_mode_notice() {
		echo '<div class="notice notice-warning is-dismissible"><p><strong>Alpha Snippets:</strong> Safe Mode is currently ACTIVE. Snippet execution is paused on both frontend and admin. <a href="' . esc_url( remove_query_arg( 'alphasnippets_safe_mode' ) ) . '">Exit Safe Mode</a></p></div>';
	}

	/**
	 * AJAX endpoint to toggle snippet active status.
	 */
	public static function ajax_toggle_edit() {
		if ( ! self::current_user_can_manage() ) {
			wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 );
		}

		check_ajax_referer( 'as_toggle_edit', 'nonce' );
		$id = sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) );
		if ( '' === $id ) {
			wp_send_json_error( array( 'message' => 'Missing snippet ID.' ), 400 );
		}

		$snippet = self::one( $id );
		if ( ! $snippet ) {
			wp_send_json_error( array( 'message' => 'Snippet not found.' ), 404 );
		}

		$new_state = self::toggle_active( $id );
		if ( false === $new_state ) {
			wp_send_json_error( array( 'message' => 'Could not update snippet status.' ), 500 );
		}

		wp_send_json_success( array( 'active' => $new_state ) );
	}

	/**
	 * Handle standard POST requests.
	 */
	public static function post() {
		if ( ! self::current_user_can_manage() || empty( $_POST['as_action'] ) ) {
			return;
		}

		$action = sanitize_key( $_POST['as_action'] );
		$nonce = sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) );
		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'as_' . $action ) ) {
			wp_die( 'Security check failed.' );
		}

		$id = sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) );

		switch ( $action ) {
			case 'delete':
				self::delete( $id );
				wp_safe_redirect( admin_url( 'admin.php?page=alphasnippets&deleted=1' ) );
				exit;

			case 'toggle':
				self::toggle_active( $id );
				wp_safe_redirect( admin_url( 'admin.php?page=alphasnippets' ) );
				exit;

			case 'duplicate':
				$original = self::one( $id );
				if ( $original ) {
					$code = self::code( $original );
					self::save(
						array(
							'name'     => ( $original['name'] ?? 'Snippet' ) . ' (Copy)',
							'type'     => $original['type'] ?? 'php',
							'priority' => $original['priority'] ?? 10,
							'where'    => $original['where'] ?? 'everywhere',
							'active'   => false,
							'code'     => $code,
						)
					);
					wp_safe_redirect( admin_url( 'admin.php?page=alphasnippets&duplicated=1' ) );
					exit;
				}
				wp_safe_redirect( admin_url( 'admin.php?page=alphasnippets' ) );
				exit;

			case 'save':
				$type = sanitize_key( $_POST['type'] ?? 'php' );
				$type = self::normalize_type( $type );
				$raw_code = wp_unslash( $_POST['code'] ?? '' );

				if ( 'php' === $type ) {
					$clean_code = self::clean( $raw_code, 'php' );
					$syntax_check = self::validate_php( $clean_code );
					if ( true !== $syntax_check ) {
						wp_die( 'Cannot save snippet. PHP Syntax Error: ' . esc_html( $syntax_check ) );
					}
				}

				$saved = self::save(
					array(
						'name'     => sanitize_text_field( wp_unslash( $_POST['name'] ?? 'Untitled Snippet' ) ),
						'type'     => $type,
						'priority' => (int) ( $_POST['priority'] ?? 10 ),
						'where'    => sanitize_key( $_POST['where'] ?? 'everywhere' ),
						'active'   => ! empty( $_POST['active'] ),
						'code'     => $raw_code,
					),
					$id
				);

				if ( false === $saved ) {
					wp_die( 'Could not save snippet. Please check that the upload directory is writable.' );
				}

				$redirect_id = $saved['id'] ?? $id;
				wp_safe_redirect( admin_url( 'admin.php?page=alphasnippets-new&id=' . rawurlencode( $redirect_id ) . '&saved=1' ) );
				exit;
		}
	}

	private static function code( $snippet ) {
		$path = self::snippet_path( $snippet );
		if ( ! $path ) {
			return '';
		}
		return self::clean( (string) @file_get_contents( $path ), $snippet['type'] ?? 'php' );
	}

	private static function base( $title ) {
		echo '<div class="alphasnippets-admin"><div class="as-shell"><header class="as-header"><a class="as-brand" href="' . esc_url( admin_url( 'admin.php?page=alphasnippets' ) ) . '"><img src="' . esc_url( ALPHA_SNIPPETS_URL . 'assets/images/logo.png' ) . '" alt=""><span>Alpha Snippets</span></a></header><main class="as-main">';
	}

	private static function end() {
		echo '</main><footer class="as-footer"><span>Thanks for using <strong>Alpha Snippets</strong>. Give a ⭐ on <a href="https://github.com/AlphaTechiess/alphasnippets" target="_blank" rel="noopener noreferrer">GitHub</a></span><span><a href="https://pages.razorpay.com/alphatechies" target="_blank" rel="noopener noreferrer">Contribute</a> • Powered by <strong>Alpha Techies</strong></span></footer></div></div>';
	}

	/**
	 * All Snippets Home Screen.
	 */
	public static function home() {
		self::assert_capability();
		$filter = sanitize_key( $_GET['type'] ?? 'all' );
		self::base( '' );

		if ( ! empty( $_GET['saved'] ) ) {
			echo '<div class="as-alert as-alert-success">✓ Snippet saved successfully!</div>';
		} elseif ( ! empty( $_GET['deleted'] ) ) {
			echo '<div class="as-alert as-alert-warning">✓ Snippet permanently deleted.</div>';
		} elseif ( ! empty( $_GET['duplicated'] ) ) {
			echo '<div class="as-alert as-alert-success">✓ Snippet duplicated as inactive draft.</div>';
		}

		if ( self::is_safe_mode() ) {
			echo '<div class="as-alert as-alert-warning">⚠️ Safe Mode Active: Snippet execution is currently paused. <a href="' . esc_url( remove_query_arg( 'alphasnippets_safe_mode' ) ) . '">Exit Safe Mode</a></div>';
		}

		echo '<section class="as-panel as-home-panel">';
		echo '<div class="as-panel-head"><h1>Code Snippets</h1><a class="as-primary" role="button" href="' . esc_url( admin_url( 'admin.php?page=alphasnippets-new' ) ) . '">+ New Snippet</a></div>';

		echo '<div class="as-filter-bar">';
		echo self::tabs( $filter );
		echo '<div class="as-search-wrap"><span class="as-search-icon"><svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg></span><input type="search" id="as-search" class="as-search-input" placeholder="Search snippets by name..."></div>';
		echo '</div>';

		echo '<div class="as-list">';

		$count = 0;
		foreach ( self::all() as $snippet ) {
			if ( 'all' === $filter || $filter === ( $snippet['type'] ?? '' ) ) {
				++$count;
				echo self::row( $snippet );
			}
		}

		if ( 0 === $count ) {
			echo '<div class="as-empty">No snippets found. Create your first snippet.</div>';
		}

		echo '</div></section>';
		self::end();
	}

	private static function tabs( $filter ) {
		$tabs = array(
			'all'     => array( 'All Snippets', '' ),
			'php'     => array( 'Functions', 'PHP' ),
			'htmlphp' => array( 'Content', 'PHP + HTML' ),
			'css'     => array( 'Styles', 'CSS' ),
			'js'      => array( 'Scripts', 'JS' ),
		);
		$output = '<nav class="as-tabs">';
		foreach ( $tabs as $key => $tab ) {
			$url = 'all' === $key ? 'admin.php?page=alphasnippets' : 'admin.php?page=alphasnippets&type=' . $key;
			$output .= '<a class="as-tab' . ( $filter === $key ? ' active' : '' ) . '" href="' . esc_url( admin_url( $url ) ) . '">' . esc_html( $tab[0] );
			if ( $tab[1] ) {
				$output .= '<b class="as-badge as-' . esc_attr( 'htmlphp' === $key ? 'htmlphp' : $key ) . '">' . esc_html( $tab[1] ) . '</b>';
			}
			$output .= '</a>';
		}
		return $output . '</nav>';
	}

	private static function row( $snippet ) {
		$id = esc_attr( $snippet['id'] ?? '' );
		$type = $snippet['type'] ?? 'php';
		$is_active = ! empty( $snippet['active'] );
		$toggle_nonce = wp_create_nonce( 'as_toggle' );
		$delete_nonce = wp_create_nonce( 'as_delete' );
		$duplicate_nonce = wp_create_nonce( 'as_duplicate' );

		$type_label = 'htmlphp' === $type ? 'PHP + HTML' : strtoupper( $type );

		$output = '<article class="as-row" data-id="' . $id . '">';
		$output .= '<div class="as-row-name-wrap"><div class="as-row-name">' . esc_html( $snippet['name'] ?? 'Untitled Snippet' ) . '</div><div class="as-row-meta">Priority: ' . (int) ( $snippet['priority'] ?? 10 ) . ' • Location: ' . esc_html( $snippet['where'] ?? 'everywhere' ) . '</div></div>';
		$output .= '<span class="as-badge as-' . esc_attr( $type ) . '">' . esc_html( $type_label ) . '</span>';
		$output .= '<div class="as-row-actions">';
		
		$output .= '<form method="post" class="as-inline"><input type="hidden" name="as_action" value="toggle"><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="_wpnonce" value="' . esc_attr( $toggle_nonce ) . '"><button class="as-switch' . ( $is_active ? ' on' : '' ) . '" type="submit" aria-label="Toggle active"><i></i></button></form>';

		$output .= '<a class="as-icon-btn" href="' . esc_url( admin_url( 'admin.php?page=alphasnippets-new&id=' . rawurlencode( $snippet['id'] ?? '' ) ) ) . '" aria-label="Edit" title="Edit"><img src="' . esc_url( ALPHA_SNIPPETS_URL . 'assets/images/pencil.svg' ) . '" alt=""></a>';

		$output .= '<form method="post" class="as-inline"><input type="hidden" name="as_action" value="duplicate"><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="_wpnonce" value="' . esc_attr( $duplicate_nonce ) . '"><button class="as-icon-btn" type="submit" aria-label="Duplicate" title="Duplicate Snippet"><img src="' . esc_url( ALPHA_SNIPPETS_URL . 'assets/images/copy.svg' ) . '" alt=""></button></form>';

		$output .= '<form method="post" class="as-inline as-delete-form"><input type="hidden" name="as_action" value="delete"><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="_wpnonce" value="' . esc_attr( $delete_nonce ) . '"><button class="as-icon-btn as-delete" type="submit" aria-label="Delete" title="Delete Snippet"><img src="' . esc_url( ALPHA_SNIPPETS_URL . 'assets/images/bin.svg' ) . '" alt=""></button></form>';

		$output .= '</div></article>';
		return $output;
	}

	/**
	 * Edit / Create Snippet Screen.
	 */
	public static function edit() {
		self::assert_capability();
		$id = sanitize_text_field( $_GET['id'] ?? '' );
		$snippet = $id ? self::one( $id ) : null;
		$type = $snippet ? ( $snippet['type'] ?? 'php' ) : ( sanitize_key( $_GET['type'] ?? 'php' ) );
		$type = self::normalize_type( $type );
		$default_where = 'htmlphp' === $type ? 'shortcode' : ( 'js' === $type ? 'footer' : 'everywhere' );
		$where = $snippet ? ( $snippet['where'] ?? $default_where ) : $default_where;
		$descriptions = self::where_options( $type );
		if ( ! isset( $descriptions[ $where ] ) ) {
			$where = $default_where;
		}

		$is_active = $snippet ? ! empty( $snippet['active'] ) : true;
		$code = $snippet ? self::code( $snippet ) : '';
		self::base( '' );

		if ( ! empty( $_GET['saved'] ) ) {
			echo '<div class="as-alert as-alert-success">✓ Snippet saved successfully!</div>';
		}

		if ( self::is_safe_mode() ) {
			echo '<div class="as-alert as-alert-warning">⚠️ Safe Mode Active: Snippet execution is currently paused. <a href="' . esc_url( remove_query_arg( 'alphasnippets_safe_mode' ) ) . '">Exit Safe Mode</a></div>';
		}

		$types = array(
			'php'     => array( 'Functions', 'PHP' ),
			'htmlphp' => array( 'Content', 'PHP + HTML' ),
			'css'     => array( 'Styles', 'CSS' ),
			'js'      => array( 'Scripts', 'JS' ),
		);

		$active_markup = '<button type="button" class="as-edit-switch' . ( $is_active ? ' on' : '' ) . '" id="as-edit-active" role="switch" aria-checked="' . ( $is_active ? 'true' : 'false' ) . '" aria-label="Toggle snippet active"><i></i><span>' . ( $is_active ? 'Active' : 'Inactive' ) . '</span></button>';

		echo '<section class="as-panel as-editor-panel">';
		echo '<div class="as-panel-head"><div class="as-head-left"><a href="' . esc_url( admin_url( 'admin.php?page=alphasnippets' ) ) . '" class="as-back-link" title="Back to All Snippets">←</a><h1>' . esc_html( $snippet ? 'Edit Snippet' : 'New Snippet' ) . '</h1></div><div class="as-publish-actions">' . $active_markup . '<button type="submit" form="as-editor-form" class="as-primary">' . esc_html( $snippet ? 'Update' : 'Publish' ) . '</button></div></div>';

		echo '<form id="as-editor-form" method="post" class="as-editor-grid">';
		echo '<input type="hidden" name="as_action" value="save">';
		echo '<input type="hidden" name="id" value="' . esc_attr( $id ) . '">';
		echo '<input type="hidden" name="type" id="as-type" value="' . esc_attr( $type ) . '">';
		echo '<input type="hidden" name="active" id="as-active" value="' . ( $is_active ? '1' : '0' ) . '">';
		wp_nonce_field( 'as_save' );

		// Left Column: Tabs & Code Editor
		echo '<div class="as-editor-left">';
		echo '<nav class="as-tabs as-editor-tabs">';
		foreach ( $types as $key => $tab ) {
			echo '<button type="button" class="as-tab' . ( $type === $key ? ' active' : '' ) . '" data-type="' . esc_attr( $key ) . '">' . esc_html( $tab[0] ) . ' <b class="as-badge as-' . esc_attr( $key ) . '">' . esc_html( $tab[1] ) . '</b></button>';
		}
		echo '</nav>';

		echo '<div class="as-code-wrap">';
		echo '<div class="as-code-prefix" id="as-code-prefix">' . self::prefix( $type ) . '</div>';
		echo '<div class="as-code-scroll" id="as-code-scroll">';
		echo '<textarea name="code" id="as-code" spellcheck="false" wrap="off" placeholder="// Enter your snippet code here...">' . esc_textarea( $code ) . '</textarea>';
		echo '</div>';
		echo '<div class="as-code-suffix" id="as-code-suffix">' . self::suffix( $type ) . '</div>';
		echo '</div>';
		echo '</div>';

		// Right Column: Settings Sidebar
		echo '<aside class="as-editor-right">';
		echo '<label class="as-field as-name-field"><span>Snippet Name</span><input name="name" value="' . esc_attr( $snippet['name'] ?? '' ) . '" placeholder="e.g. Custom Analytics Script" required></label>';

		echo '<div class="as-field"><span>Priority (1 = Highest, 100 = Lowest)</span><div class="as-stepper"><button type="button" data-step="-1">−</button><input id="as-priority" name="priority" type="number" min="1" max="100" value="' . esc_attr( $snippet['priority'] ?? 10 ) . '" inputmode="numeric"><button type="button" data-step="1">+</button></div></div>';

		echo '<div class="as-field"><span>Where to run?</span><input type="hidden" name="where" id="as-where" value="' . esc_attr( $where ) . '"><div class="as-dropdown" id="as-dropdown"><button class="as-dd-current" type="button" aria-expanded="false"><span><strong>' . esc_html( $descriptions[ $where ][0] ) . '</strong><small>' . esc_html( $descriptions[ $where ][1] ) . '</small></span><i></i></button><div class="as-dd-menu">';
		foreach ( $descriptions as $key => $description ) {
			echo '<button type="button" class="as-dd-option" data-value="' . esc_attr( $key ) . '"><strong>' . esc_html( $description[0] ) . '</strong><small>' . esc_html( $description[1] ) . '</small></button>';
		}
		echo '</div>';

		// Shortcode preview and copy button
		$is_shortcode_type = ( 'htmlphp' === $type && 'shortcode' === $where );
		echo '<div class="as-shortcode-hint" id="as-shortcode-hint"' . ( $is_shortcode_type ? '' : ' hidden' ) . '>';
		echo '<span>Insert this shortcode into any post, page, or widget:</span>';
		echo '<div class="as-shortcode-box">';
		echo '<code id="as-shortcode-value" data-id="' . esc_attr( $id ) . '">' . ( $id ? '[alpha_snippet id="' . esc_attr( $id ) . '"]' : 'Publish this snippet first to generate the shortcode.' ) . '</code>';
		if ( $id ) {
			echo '<button type="button" class="as-copy-btn" id="as-copy-shortcode">Copy</button>';
		}
		echo '</div></div></div>';

		// Secondary Save Button in Sidebar
		echo '<div class="as-sidebar-actions"><button type="submit" form="as-editor-form" class="as-primary as-btn-block">' . esc_html( $snippet ? 'Update Snippet' : 'Save Snippet' ) . '</button></div>';

		echo '</aside></form></section>';
		self::end();
	}

	private static function prefix( $type ) {
		return array(
			'php'     => '&lt;?php',
			'htmlphp' => '&lt;!-- begin content --&gt;',
			'css'     => '&lt;style&gt;',
			'js'      => '&lt;script&gt;',
		)[ $type ] ?? '';
	}

	private static function suffix( $type ) {
		return array(
			'css' => '&lt;/style&gt;',
			'js'  => '&lt;/script&gt;',
		)[ $type ] ?? '';
	}

	private static function normalize_type( $type ) {
		return in_array( $type, array( 'php', 'htmlphp', 'css', 'js' ), true ) ? $type : 'php';
	}

	private static function normalize_where( $type, $where ) {
		$options = self::where_options( $type );
		return isset( $options[ $where ] ) ? $where : ( 'htmlphp' === $type ? 'shortcode' : ( 'js' === $type ? 'footer' : 'everywhere' ) );
	}

	private static function where_options( $type ) {
		if ( 'htmlphp' === $type ) {
			return array(
				'shortcode'      => array( 'Shortcode', 'Only display when inserted into a post or page using shortcode' ),
				'header'         => array( 'Site Wide Header', 'Insert snippet between the head tags of your website (frontend).' ),
				'body_open'      => array( 'Site Wide Body Open', 'Insert snippet after the opening body tag of your website (frontend).' ),
				'footer'         => array( 'Site Wide Footer', 'Insert snippet before the closing body tag of your website on all pages (frontend).' ),
				'before_content' => array( 'Before Content', 'Insert snippet at the beginning of single post/page content.' ),
				'after_content'  => array( 'After Content', 'Insert snippet at the end of single post/page content.' ),
			);
		}

		if ( 'js' === $type ) {
			return array(
				'header'       => array( 'Site Wide Header', 'Run Javascript between the head tags of your website on all pages (frontend).' ),
				'footer'       => array( 'Site Wide Footer', 'Run Javascript before the closing body tag of your website on all pages (frontend).' ),
				'admin_header' => array( 'Admin Area Header', 'Run Javascript in admin area (/wp-admin/).' ),
				'admin_footer' => array( 'Admin Area Footer', 'Run Javascript in admin area (/wp-admin/) before the closing body tag.' ),
			);
		}

		return array(
			'everywhere' => array( 'Run Everywhere', 'Snippet gets executed everywhere (both frontend and admin area)' ),
			'frontend'   => array( 'Frontend Only', 'Snippet gets executed only in frontend area' ),
			'admin'      => array( 'Admin Only', 'Snippet gets executed only in admin area' ),
		);
	}

	private static function delete_file( $file ) {
		$file = basename( (string) $file );
		if ( '' === $file || preg_match( '/^snippet-[a-z0-9]+\.(?:php|css|js)$/i', $file ) !== 1 ) {
			return false;
		}

		$path = self::dir() . $file;
		if ( ! is_file( $path ) ) {
			return true;
		}

		$deleted = @unlink( $path );
		if ( $deleted && function_exists( 'opcache_invalidate' ) ) {
			opcache_invalidate( $path, true );
		}

		return $deleted;
	}

	private static function snippet_path( $snippet ) {
		$file = isset( $snippet['file'] ) ? basename( (string) $snippet['file'] ) : '';
		if ( '' === $file || preg_match( '/^snippet-[a-z0-9]+\.(?:php|css|js)$/i', $file ) !== 1 ) {
			return false;
		}

		$path = self::dir() . $file;
		return is_file( $path ) && is_readable( $path ) ? $path : false;
	}

	/**
	 * Capability check with Multisite Super Admin protection.
	 */
	private static function capability() {
		if ( null === self::$capability ) {
			$default = is_multisite() ? 'manage_network_options' : 'manage_options';
			self::$capability = apply_filters( 'alpha_snippets_capability', $default );
			if ( ! is_string( self::$capability ) || '' === self::$capability ) {
				self::$capability = $default;
			}
		}
		return self::$capability;
	}

	public static function current_user_can_manage() {
		if ( is_multisite() && ! is_super_admin() ) {
			return false;
		}
		return current_user_can( self::capability() );
	}

	private static function assert_capability() {
		if ( ! self::current_user_can_manage() ) {
			wp_die( 'Permission denied. Only Super Administrators can manage Alpha Snippets on multisite.' );
		}
	}

	private static function reset_cache() {
		self::$all = null;
		self::$runtime = array();
	}

	private static function log_error( Throwable $error ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'Alpha Snippets Error: ' . $error->getMessage() . ' in ' . $error->getFile() . ':' . $error->getLine() );
		}
	}
}
