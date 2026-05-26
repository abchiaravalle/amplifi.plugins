/**
 * Extends @wordpress/scripts default config.
 *
 * Only an entry override is needed: we want a single `index.js` built from
 * `assets/src/index.jsx`. Everything else (Sass, JSX, WP externals, .asset.php
 * generation) is handled by the default config.
 */
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );

module.exports = {
	...defaultConfig,
	entry: {
		index: path.resolve( __dirname, 'assets/src/index.jsx' ),
	},
};
