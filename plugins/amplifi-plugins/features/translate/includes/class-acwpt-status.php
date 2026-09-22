<?php
/**
 * Translation status + backup admin screen.
 *
 * Two questions this answers that nothing else could:
 *
 *   1. "Which pages have changed since we last translated them?"
 *      Compares each post's CURRENT content hash against the hash stored with
 *      its translation — the same test the renderer uses, so the report cannot
 *      disagree with what the site actually serves.
 *
 *   2. "Can I get the translations out?"
 *      A full JSON export of every string and post translation. Translations
 *      cost real money to produce; they should never live in exactly one place.
 *
 * @package amplifi.translate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACWPT_Status {

	const PAGE_SLUG  = 'amplifi-translate-status';
	const EXPORT_ACT = 'acwpt_export_translations';
	const IMPORT_ACT = 'acwpt_import_translations';

	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ), 20 );
		add_action( 'admin_post_' . self::EXPORT_ACT, array( __CLASS__, 'handle_export' ) );
		add_action( 'admin_post_' . self::IMPORT_ACT, array( __CLASS__, 'handle_import' ) );
	}

	public static function add_page() {
		add_submenu_page(
			'amplifi-studio',
			'Translation Status',
			'Translation Status',
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Stream the export as a JSON download.
	 */
	public static function handle_export() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions.' );
		}
		check_admin_referer( self::EXPORT_ACT );

		$language = isset( $_GET['language'] ) ? sanitize_text_field( wp_unslash( $_GET['language'] ) ) : '';
		$data     = ACWPT_String_Store::export( $language ?: null );

		$host  = wp_parse_url( home_url(), PHP_URL_HOST );
		$slug  = $language ? '-' . $language : '-all';
		$name  = 'acwpt-translations-' . $host . $slug . '-' . gmdate( 'Ymd-His' ) . '.json';

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );

		echo wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		exit;
	}

	/**
	 * Restore from an uploaded export. Additive — never deletes.
	 */
	public static function handle_import() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions.' );
		}
		check_admin_referer( self::IMPORT_ACT );

		$redirect = admin_url( 'admin.php?page=' . self::PAGE_SLUG );

		if ( empty( $_FILES['backup']['tmp_name'] ) ) {
			wp_safe_redirect( add_query_arg( 'acwpt_msg', 'nofile', $redirect ) );
			exit;
		}

		$raw  = file_get_contents( $_FILES['backup']['tmp_name'] ); // phpcs:ignore
		$data = json_decode( $raw, true );

		if ( ! is_array( $data ) || ( empty( $data['strings'] ) && empty( $data['posts'] ) ) ) {
			wp_safe_redirect( add_query_arg( 'acwpt_msg', 'badfile', $redirect ) );
			exit;
		}

		$counts = ACWPT_String_Store::import( $data );

		wp_safe_redirect( add_query_arg( array(
			'acwpt_msg'     => 'imported',
			'acwpt_strings' => (int) $counts['strings'],
			'acwpt_posts'   => (int) $counts['posts'],
		), $redirect ) );
		exit;
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$languages = ACWPT_Languages::get_enabled_codes();
		$all       = ACWPT_Languages::get_all();
		$status    = $languages ? ACWPT_String_Store::translation_status( $languages ) : array();

		// Roll up per language.
		$totals = array();
		foreach ( $languages as $l ) {
			$totals[ $l ] = array( 'current' => 0, 'stale' => 0, 'missing' => 0 );
		}
		foreach ( $status as $row ) {
			foreach ( $row['languages'] as $l => $info ) {
				if ( isset( $totals[ $l ][ $info['state'] ] ) ) {
					$totals[ $l ][ $info['state'] ]++;
				}
			}
		}

		$dirty = ACWPT_String_Store::dirty_posts();
		$msg   = isset( $_GET['acwpt_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['acwpt_msg'] ) ) : '';
		?>
		<div class="wrap">
			<h1>Translation Status</h1>

			<?php if ( 'imported' === $msg ) : ?>
				<div class="notice notice-success"><p>
					Restored <?php echo (int) ( $_GET['acwpt_strings'] ?? 0 ); ?> strings and
					<?php echo (int) ( $_GET['acwpt_posts'] ?? 0 ); ?> post translations.
				</p></div>
			<?php elseif ( 'badfile' === $msg ) : ?>
				<div class="notice notice-error"><p>That file is not a valid amplifi.translate backup.</p></div>
			<?php elseif ( 'nofile' === $msg ) : ?>
				<div class="notice notice-error"><p>No file was uploaded.</p></div>
			<?php endif; ?>

			<?php if ( ! $languages ) : ?>
				<div class="notice notice-warning"><p>No target languages are enabled yet.</p></div>
				</div>
				<?php
				return;
			endif;
			?>

			<h2>Coverage</h2>
			<table class="widefat striped" style="max-width:820px">
				<thead>
					<tr>
						<th>Language</th>
						<th style="text-align:right">Up to date</th>
						<th style="text-align:right">Changed since translation</th>
						<th style="text-align:right">Never translated</th>
						<th style="text-align:right">Strings stored</th>
						<th></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $languages as $l ) :
					$t     = $totals[ $l ];
					$label = isset( $all[ $l ]['name'] ) ? $all[ $l ]['name'] : $l;
					?>
					<tr>
						<td><strong><?php echo esc_html( $label ); ?></strong> <code><?php echo esc_html( $l ); ?></code></td>
						<td style="text-align:right"><?php echo (int) $t['current']; ?></td>
						<td style="text-align:right">
							<?php if ( $t['stale'] ) : ?>
								<strong style="color:#b32d2e"><?php echo (int) $t['stale']; ?></strong>
							<?php else : ?>
								0
							<?php endif; ?>
						</td>
						<td style="text-align:right">
							<?php if ( $t['missing'] ) : ?>
								<strong style="color:#996800"><?php echo (int) $t['missing']; ?></strong>
							<?php else : ?>
								0
							<?php endif; ?>
						</td>
						<td style="text-align:right"><?php echo (int) ACWPT_String_Store::count( $l ); ?></td>
						<td style="text-align:right">
							<a class="button"
							   href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . self::EXPORT_ACT . '&language=' . rawurlencode( $l ) ), self::EXPORT_ACT ) ); ?>">
								Download <?php echo esc_html( strtoupper( $l ) ); ?>
							</a>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<p>
				<a class="button button-primary"
				   href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . self::EXPORT_ACT ), self::EXPORT_ACT ) ); ?>">
					Download full backup (all languages)
				</a>
			</p>

			<?php if ( $dirty ) : ?>
				<p class="description">
					<?php echo count( $dirty ); ?> recently edited page(s) are queued for re-translation in the background.
				</p>
			<?php endif; ?>

			<h2>Restore from backup</h2>
			<p class="description">
				Adds translations from a backup file. Existing translations are updated, never deleted.
			</p>
			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::IMPORT_ACT ); ?>">
				<?php wp_nonce_field( self::IMPORT_ACT ); ?>
				<input type="file" name="backup" accept="application/json" required>
				<button class="button">Restore</button>
			</form>

			<h2>Pages needing attention</h2>
			<?php
			$attention = array();
			foreach ( $status as $row ) {
				foreach ( $row['languages'] as $info ) {
					if ( 'current' !== $info['state'] ) {
						$attention[] = $row;
						continue 2;
					}
				}
			}
			if ( ! $attention ) :
				?>
				<p>Every page is translated and up to date in all enabled languages.</p>
			<?php else : ?>
				<p class="description">Showing <?php echo min( 100, count( $attention ) ); ?> of <?php echo count( $attention ); ?>.</p>
				<table class="widefat striped">
					<thead>
						<tr>
							<th>Page</th>
							<th>Type</th>
							<th>Last edited</th>
							<?php foreach ( $languages as $l ) : ?>
								<th><?php echo esc_html( strtoupper( $l ) ); ?></th>
							<?php endforeach; ?>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( array_slice( $attention, 0, 100 ) as $row ) : ?>
						<tr>
							<td>
								<a href="<?php echo esc_url( get_edit_post_link( $row['post_id'] ) ); ?>">
									<?php echo esc_html( $row['title'] ); ?>
								</a>
							</td>
							<td><code><?php echo esc_html( $row['post_type'] ); ?></code></td>
							<td><?php echo esc_html( $row['modified'] ); ?></td>
							<?php foreach ( $languages as $l ) :
								$s = $row['languages'][ $l ]['state'];
								$c = 'current' === $s ? '#1a7f37' : ( 'stale' === $s ? '#b32d2e' : '#996800' );
								$x = 'current' === $s ? 'ok' : ( 'stale' === $s ? 'changed' : 'missing' );
								?>
								<td style="color:<?php echo esc_attr( $c ); ?>"><?php echo esc_html( $x ); ?></td>
							<?php endforeach; ?>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}
