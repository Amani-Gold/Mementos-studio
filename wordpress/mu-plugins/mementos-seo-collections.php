<?php
/**
 * Plugin Name: Mementos SEO — Collections
 * Description: Crawlable SEO metadata + Product JSON-LD for the /collections/ configurator pages, driven by the live
 *              mementos/v1/config catalog (no hardcoded prices). 301s legacy /product/ URLs and adds the collection
 *              URLs to the AIOSEO sitemap. Does not change the configurator itself.
 * Version:     1.0.1
 *
 * Install: copy to wp-content/mu-plugins/. Remove the file to roll back — it stores nothing in the database
 * except a 1-hour transient (mementos_seo_cfg).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** URL slug => config collection slug. The URL slugs are the ones the album-designer plugin already serves. */
function mementos_seo_collection_map() {
	return array(
		'solo-album'      => 'solo',
		'standard-albums' => 'standard',
		'luxury-albums'   => 'luxury',
		'sliding-box'     => 'sliding',
		'pocket-album'    => 'pocket',
	);
}

/** Legacy WooCommerce product URL slug => collection URL slug (these currently 302). */
function mementos_seo_product_redirects() {
	return array(
		'solo-albums'       => 'solo-album',
		'solo-album'        => 'solo-album',
		'standard-albums'   => 'standard-albums',
		'luxury-albums'     => 'luxury-albums',
		'sliding-album-box' => 'sliding-box',
		'pocket-albums'     => 'pocket-album',
	);
}

/** Current request path without query string, e.g. "collections/luxury-albums". */
function mementos_seo_request_path() {
	$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
	$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
	$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
	if ( $home && '/' !== $home && 0 === strpos( $path, $home ) ) {
		$path = substr( $path, strlen( $home ) - 1 );
	}
	return trim( rawurldecode( $path ), '/' );
}

/** The catalog config, from the same REST route the configurator uses. Cached for an hour. */
function mementos_seo_config() {
	$cfg = get_transient( 'mementos_seo_cfg' );
	if ( is_array( $cfg ) ) {
		return $cfg;
	}
	if ( ! function_exists( 'rest_do_request' ) ) {
		return null;
	}
	$res = rest_do_request( new WP_REST_Request( 'GET', '/mementos/v1/config' ) );
	if ( $res->is_error() ) {
		return null;
	}
	$cfg = $res->get_data();
	if ( ! is_array( $cfg ) || empty( $cfg['collections'] ) ) {
		return null;
	}
	set_transient( 'mementos_seo_cfg', $cfg, HOUR_IN_SECONDS );
	return $cfg;
}

/** Build everything the <head> needs for one collection. Pure function of the config — testable. */
function mementos_seo_collection_data( $cfg, $url_slug, $url ) {
	$map  = mementos_seo_collection_map();
	$slug = isset( $map[ $url_slug ] ) ? $map[ $url_slug ] : null;
	if ( ! $slug || empty( $cfg['collections'] ) ) {
		return null;
	}
	$col = null;
	foreach ( $cfg['collections'] as $c ) {
		if ( isset( $c['slug'] ) && $c['slug'] === $slug ) {
			$col = $c;
			break;
		}
	}
	if ( ! $col ) {
		return null;
	}

	$prices = array();
	$sizes  = array();
	if ( ! empty( $cfg['sizes'][ $slug ] ) && is_array( $cfg['sizes'][ $slug ] ) ) {
		foreach ( $cfg['sizes'][ $slug ] as $variant ) {
			foreach ( (array) $variant as $s ) {
				if ( isset( $s['base_price'] ) && is_numeric( $s['base_price'] ) && $s['base_price'] > 0 ) {
					$prices[] = (float) $s['base_price'];
				}
				if ( ! empty( $s['display'] ) && false === strpos( $s['display'], '+' ) ) {
					$sizes[ str_replace( 'x', '×', $s['display'] ) ] = true;
				}
			}
		}
	}
	$sizes = array_keys( $sizes );

	$materials = array();
	foreach ( (array) ( isset( $cfg['materials'] ) ? $cfg['materials'] : array() ) as $m ) {
		if ( ! empty( $m['slug'] ) ) {
			// The catalog slug/name is spelled "chamios"; the customer-facing word is "chamois".
			$materials[] = 'chamios' === $m['slug'] ? 'chamois' : strtolower( $m['name'] );
		}
	}

	$name  = ! empty( $col['presentation']['title'] ) ? $col['presentation']['title'] : $col['name'];
	$short = ! empty( $col['presentation']['short_desc'] ) ? $col['presentation']['short_desc'] : $col['description'];
	$short = rtrim( wp_strip_all_tags( $short ), '. ' ) . '.';

	$images = array();
	if ( ! empty( $col['presentation']['hero'] ) ) {
		$images[] = $col['presentation']['hero'];
	}
	if ( ! empty( $col['cover_image_url'] ) ) {
		$images[] = $col['cover_image_url'];
	}
	foreach ( (array) ( isset( $col['gallery'] ) ? $col['gallery'] : array() ) as $g ) {
		$images[] = $g;
	}
	$images = array_slice( array_values( array_unique( array_filter( $images ) ) ), 0, 6 );

	$currency = ( isset( $cfg['currency'] ) && 'BD' === strtoupper( $cfg['currency'] ) ) ? 'BHD' : ( isset( $cfg['currency'] ) ? $cfg['currency'] : 'BHD' );
	$low      = $prices ? min( $prices ) : null;
	$high     = $prices ? max( $prices ) : null;
	$fmt      = function ( $p ) {
		return rtrim( rtrim( number_format( $p, 3, '.', '' ), '0' ), '.' );
	};

	// Kept near Google's ~160-character snippet width; sizes live in the JSON-LD instead.
	$desc = $name . ' by Mementos Studio, Bahrain: ' . lcfirst( $short );
	if ( $materials ) {
		$desc .= ' Handcrafted in ' . implode( ' or ', $materials );
		$desc .= null !== $low ? ', from ' . $fmt( $low ) . ' BD.' : '.';
	} elseif ( null !== $low ) {
		$desc .= ' From ' . $fmt( $low ) . ' BD.';
	}

	$brand_id = home_url( '/#organization' );
	$product  = array(
		'@type'       => 'Product',
		'@id'         => $url . '#product',
		'name'        => $name,
		'description' => $short,
		'url'         => $url,
		'image'       => $images,
		'brand'       => array( '@type' => 'Brand', 'name' => 'Mementos Studio' ),
		'manufacturer' => array( '@id' => $brand_id ),
		'category'    => 'Photo Albums',
	);
	if ( $materials ) {
		$product['material'] = implode( ', ', array_map( 'ucfirst', $materials ) );
	}
	if ( $sizes ) {
		$product['size'] = implode( ', ', $sizes ) . ' in';
	}
	if ( null !== $low ) {
		$product['offers'] = array(
			'@type'         => 'AggregateOffer',
			'priceCurrency' => $currency,
			'lowPrice'      => $fmt( $low ),
			'highPrice'     => $fmt( $high ),
			'offerCount'    => count( $prices ),
			'url'           => $url,
			'seller'        => array( '@id' => $brand_id ),
		);
	}

	$graph = array(
		'@context' => 'https://schema.org',
		'@graph'   => array(
			array(
				'@type'           => 'BreadcrumbList',
				'@id'             => $url . '#breadcrumblist',
				'itemListElement' => array(
					array( '@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => home_url( '/' ) ),
					array( '@type' => 'ListItem', 'position' => 2, 'name' => $name, 'item' => $url ),
				),
			),
			array(
				'@type'      => 'WebPage',
				'@id'        => $url . '#webpage',
				'url'        => $url,
				'name'       => $name . ' — Mementos Studio',
				'inLanguage' => 'en-US',
				'isPartOf'   => array( '@id' => home_url( '/#website' ) ),
				'breadcrumb' => array( '@id' => $url . '#breadcrumblist' ),
				'mainEntity' => array( '@id' => $url . '#product' ),
			),
			$product,
		),
	);

	return array(
		'title'       => $name . ' — Handcrafted Photo Albums in Bahrain | Mementos Studio',
		'description' => $desc,
		'url'         => $url,
		'image'       => $images ? $images[0] : '',
		'jsonld'      => $graph,
	);
}

/** Render the <head> tags for one collection. */
function mementos_seo_head_html( $d ) {
	$e   = 'esc_attr';
	$out = "\n<!-- mementos-seo-collections -->\n";
	$out .= '<meta name="description" content="' . $e( $d['description'] ) . "\">\n";
	$out .= '<meta name="robots" content="index, follow, max-image-preview:large">' . "\n";
	$out .= '<link rel="canonical" href="' . esc_url( $d['url'] ) . "\">\n";
	$out .= '<meta property="og:type" content="product">' . "\n";
	$out .= '<meta property="og:site_name" content="Mementos Studio">' . "\n";
	$out .= '<meta property="og:locale" content="en_US">' . "\n";
	$out .= '<meta property="og:title" content="' . $e( $d['title'] ) . "\">\n";
	$out .= '<meta property="og:description" content="' . $e( $d['description'] ) . "\">\n";
	$out .= '<meta property="og:url" content="' . esc_url( $d['url'] ) . "\">\n";
	if ( $d['image'] ) {
		$out .= '<meta property="og:image" content="' . esc_url( $d['image'] ) . "\">\n";
	}
	$out .= '<meta name="twitter:card" content="summary_large_image">' . "\n";
	$out .= '<script type="application/ld+json">' . wp_json_encode( $d['jsonld'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "</script>\n";
	return $out;
}

/** Inject into the configurator page's own HTML document (it does not call wp_head). */
function mementos_seo_filter_html( $html, $d ) {
	if ( false !== stripos( $html, 'mementos-seo-collections' ) ) {
		return $html;
	}
	$html = preg_replace( '#<title>.*?</title>#is', '<title>' . esc_html( $d['title'] ) . '</title>', $html, 1 );
	$pos  = stripos( $html, '</head>' );
	if ( false === $pos ) {
		return $html;
	}
	return substr( $html, 0, $pos ) . mementos_seo_head_html( $d ) . substr( $html, $pos );
}

// 1) 301 legacy product URLs (the album-designer plugin currently answers them with a 302).
add_action(
	'template_redirect',
	function () {
		$path = mementos_seo_request_path();
		if ( preg_match( '#^product/([^/]+)$#', $path, $m ) ) {
			$r = mementos_seo_product_redirects();
			if ( isset( $r[ $m[1] ] ) ) {
				wp_safe_redirect( home_url( '/collections/' . $r[ $m[1] ] . '/' ), 301, 'Mementos SEO' );
				exit;
			}
		}
	},
	0
);

// 2) Buffer collection pages and add metadata + JSON-LD before </head>.
add_action(
	'template_redirect',
	function () {
		$path = mementos_seo_request_path();
		if ( ! preg_match( '#^collections/([^/]+)$#', $path, $m ) ) {
			return;
		}
		$map = mementos_seo_collection_map();
		if ( ! isset( $map[ $m[1] ] ) ) {
			return;
		}
		$cfg = mementos_seo_config();
		if ( ! $cfg ) {
			return;
		}
		$d = mementos_seo_collection_data( $cfg, $m[1], home_url( '/collections/' . $m[1] . '/' ) );
		if ( ! $d ) {
			return;
		}
		ob_start(
			function ( $html ) use ( $d ) {
				return mementos_seo_filter_html( $html, $d );
			}
		);
	},
	1
);

// 3) Add the collection URLs to the AIOSEO sitemap (they are virtual routes, so AIOSEO cannot see them).
add_filter(
	'aioseo_sitemap_additional_pages',
	function ( $pages ) {
		$pages = is_array( $pages ) ? $pages : array();
		foreach ( array_keys( mementos_seo_collection_map() ) as $slug ) {
			$pages[] = array(
				'loc'        => home_url( '/collections/' . $slug . '/' ),
				'lastmod'    => gmdate( 'c' ),
				'changefreq' => 'weekly',
				'priority'   => 0.9,
			);
		}
		return $pages;
	}
);

// 4) Drop the redirecting /product/ URLs from AIOSEO's product sitemap — they now 301 to the collections above —
//    and list the collection URLs in the page sitemap (filter 3 only runs when AIOSEO "Additional Pages" is on).
add_filter(
	'aioseo_sitemap_posts',
	function ( $entries, $post_type = '' ) {
		if ( ! is_array( $entries ) ) {
			return $entries;
		}
		$r       = mementos_seo_product_redirects();
		$entries = array_values(
			array_filter(
				$entries,
				function ( $e ) use ( $r ) {
					$loc = is_array( $e ) && isset( $e['loc'] ) ? $e['loc'] : '';
					return ! ( $loc && preg_match( '#/product/([^/]+)/?$#', $loc, $m ) && isset( $r[ $m[1] ] ) );
				}
			)
		);
		if ( 'page' === $post_type ) {
			$have = array();
			foreach ( $entries as $e ) {
				if ( is_array( $e ) && isset( $e['loc'] ) ) {
					$have[ $e['loc'] ] = true;
				}
			}
			foreach ( array_keys( mementos_seo_collection_map() ) as $slug ) {
				$loc = home_url( '/collections/' . $slug . '/' );
				if ( ! isset( $have[ $loc ] ) ) {
					$entries[] = array(
						'loc'        => $loc,
						'lastmod'    => gmdate( 'c' ),
						'changefreq' => 'weekly',
						'priority'   => 0.9,
					);
				}
			}
		}
		return $entries;
	},
	10,
	2
);

// Refresh the cached catalog when WooCommerce products change.
add_action(
	'save_post_product',
	function () {
		delete_transient( 'mementos_seo_cfg' );
	}
);
