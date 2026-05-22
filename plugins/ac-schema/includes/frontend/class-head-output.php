<?php
declare(strict_types=1);
namespace Amplifi\Schema\Frontend;

use Amplifi\Schema\Data\Entry_Store;
use Amplifi\Schema\Schema\Graph_Builder;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Head_Output {
	public function register(): void {
		$settings = get_option( 'ac_schema_settings', [] );
		$priority = (int) ( $settings['output_priority'] ?? 1 );
		add_action( 'wp_head', [ $this, 'emit' ], $priority );
	}

	public function emit(): void {
		$ctx = [
			'post_id'   => is_singular() ? get_queried_object_id() : 0,
			'url_rules' => $this->match_url_rules( $this->current_url() ),
		];
		$gb    = new Graph_Builder( new Entry_Store() );
		$graph = $gb->build( $ctx );
		if ( empty( $graph['@graph'] ) ) {
			return;
		}

		echo "\n<script type=\"application/ld+json\" id=\"amplifi-schema\">";
		echo wp_json_encode( $graph, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_PRETTY_PRINT );
		echo "</script>\n";
	}

	private function current_url(): string {
		$scheme = is_ssl() ? 'https' : 'http';
		$host   = isset( $_SERVER['HTTP_HOST'] ) ? wp_unslash( $_SERVER['HTTP_HOST'] ) : '';
		$uri    = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		return $scheme . '://' . $host . $uri;
	}

	/** @return string[] */
	private function match_url_rules( string $url ): array {
		$rules   = get_option( 'ac_schema_url_rules', [] );
		if ( ! is_array( $rules ) ) {
			return [];
		}
		$matched = [];
		$path    = (string) wp_parse_url( $url, PHP_URL_PATH );
		foreach ( $rules as $rule ) {
			$pattern    = (string) ( $rule['pattern'] ?? '' );
			$match_type = (string) ( $rule['match_type'] ?? 'glob' );
			if ( $pattern === '' ) {
				continue;
			}
			$hit = $match_type === 'regex'
				? @preg_match( $pattern, $path ) === 1
				: fnmatch( $pattern, $path );
			if ( $hit ) {
				$matched[] = (string) ( $rule['id'] ?? '' );
			}
		}
		return array_values( array_filter( $matched ) );
	}
}
