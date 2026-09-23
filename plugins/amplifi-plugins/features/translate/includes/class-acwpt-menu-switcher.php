<?php
/**
 * Nav-menu language switcher: admin affordance + presentation.
 *
 * The expansion logic already existed — a menu item whose URL is
 * #acwpt-language-switcher is replaced at render time with the current
 * language plus a submenu of the others. Two things were missing:
 *
 *   1. No way to ADD that item without hand-typing the magic URL into a custom
 *      link. An editor had no reason to know it existed.
 *   2. No styling. It inherited whatever the theme did with a dropdown, which
 *      on most themes means an unstyled list.
 *
 * This adds a proper metabox in Appearance → Menus, and self-contained CSS that
 * reads the theme's own colours instead of imposing a palette.
 *
 * @package amplifi-translate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACWPT_Menu_Switcher {

	const MAGIC_URL = '#acwpt-language-switcher';

	public static function init() {
		add_action( 'admin_head-nav-menus.php', array( __CLASS__, 'register_metabox' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 20 );
	}

	/**
	 * "Language Switcher" panel in Appearance → Menus.
	 */
	public static function register_metabox() {
		add_meta_box(
			'acwpt-menu-switcher',
			'Language Switcher',
			array( __CLASS__, 'render_metabox' ),
			'nav-menus',
			'side',
			'default'
		);
	}

	public static function render_metabox() {
		$enabled = ACWPT_Languages::get_enabled_codes();
		?>
		<div class="acwpt-switcher-metabox">
			<?php if ( empty( $enabled ) ) : ?>
				<p style="color:#b32d2e;margin:0 0 8px">
					No languages are enabled yet. Turn them on in
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=amplifi-translate' ) ); ?>">amplifi.studio → Translate</a>
					first.
				</p>
			<?php else : ?>
				<p style="margin:0 0 10px;color:#50575e">
					Adds a menu item showing the current language, with the other
					<?php echo count( $enabled ); ?> as a submenu. Drag it anywhere &mdash;
					put it first for a classic language picker.
				</p>
			<?php endif; ?>

			<div id="acwpt-switcher-items" class="posttypediv">
				<div class="tabs-panel tabs-panel-active" style="border:0;padding:0">
					<ul class="categorychecklist form-no-clear">
						<li>
							<label class="menu-item-title">
								<input type="checkbox" class="menu-item-checkbox" checked disabled>
								Language Switcher
							</label>
							<input type="hidden" class="menu-item-type"  name="menu-item[-97][menu-item-type]"  value="custom">
							<input type="hidden" class="menu-item-title" name="menu-item[-97][menu-item-title]" value="Language">
							<input type="hidden" class="menu-item-url"   name="menu-item[-97][menu-item-url]"   value="<?php echo esc_attr( self::MAGIC_URL ); ?>">
							<input type="hidden" class="menu-item-classes" name="menu-item[-97][menu-item-classes]" value="acwpt-menu-switcher">
						</li>
					</ul>
				</div>
				<p class="button-controls">
					<span class="add-to-menu">
						<input type="submit" class="button-secondary submit-add-to-menu right"
							value="Add to Menu" name="add-acwpt-switcher" id="submit-acwpt-switcher">
						<span class="spinner"></span>
					</span>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Front-end styles.
	 *
	 * Inlined rather than a separate request: it is under 2KB and a switcher
	 * that appears unstyled for one paint looks broken. Colours come from the
	 * theme via currentColor and inherit, so it adapts instead of clashing.
	 */
	public static function enqueue() {
		if ( empty( ACWPT_Languages::get_enabled_codes() ) ) {
			return;
		}

		$css = '
.acwpt-menu-switcher{position:relative}
.acwpt-menu-switcher>a{display:inline-flex;align-items:center;gap:.45em;cursor:pointer}
.acwpt-menu-switcher>a::after{content:"";width:.42em;height:.42em;border-right:2px solid currentColor;border-bottom:2px solid currentColor;transform:rotate(45deg) translateY(-.1em);opacity:.55;transition:transform .18s ease,opacity .18s ease}
.acwpt-menu-switcher:hover>a::after,.acwpt-menu-switcher:focus-within>a::after{transform:rotate(225deg) translateY(-.1em);opacity:.9}
.acwpt-lang-flag{font-size:1.05em;line-height:1;filter:saturate(1.08)}
.acwpt-lang-code{font-variant:small-caps;letter-spacing:.04em;opacity:.75;font-size:.82em}
.acwpt-menu-switcher .sub-menu,.acwpt-menu-switcher .children{min-width:11.5rem;padding:.35rem;border-radius:.6rem;box-shadow:0 10px 30px -8px rgba(16,20,24,.28),0 2px 6px -2px rgba(16,20,24,.14);background:#fff;border:1px solid rgba(16,20,24,.08)}
.acwpt-menu-switcher .sub-menu a,.acwpt-menu-switcher .children a{display:flex;align-items:center;gap:.55em;padding:.44rem .6rem;border-radius:.4rem;white-space:nowrap;transition:background .14s ease}
.acwpt-menu-switcher .sub-menu a:hover,.acwpt-menu-switcher .children a:hover{background:rgba(16,20,24,.06)}
.acwpt-menu-switcher .acwpt-current>a{font-weight:600}
.acwpt-menu-switcher .acwpt-current>a::before{content:"";width:.4em;height:.4em;border-radius:50%;background:currentColor;opacity:.5;flex:none}
@media (prefers-color-scheme:dark){.acwpt-menu-switcher .sub-menu,.acwpt-menu-switcher .children{background:#1f2328;border-color:rgba(255,255,255,.1)}.acwpt-menu-switcher .sub-menu a:hover,.acwpt-menu-switcher .children a:hover{background:rgba(255,255,255,.08)}}
';

		wp_register_style( 'acwpt-switcher', false, array(), ACWPT_VERSION );
		wp_enqueue_style( 'acwpt-switcher' );
		wp_add_inline_style( 'acwpt-switcher', preg_replace( '/\s*\n\s*/', '', $css ) );
	}
}
