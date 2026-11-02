<?php
/**
 * Plugin Name: Mementos SEO — Collections
 * Description: Crawlable SEO metadata + Product JSON-LD for the /collections/ configurator pages, driven by the live
 *              mementos/v1/config catalog (no hardcoded prices). 301s legacy /product/ URLs and adds the collection
 *              URLs to the AIOSEO sitemap. Does not change the configurator itself.
 * Version:     1.2.0
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
	$highs  = array();
	$rows   = array();
	$sizes  = array();
	if ( ! empty( $cfg['sizes'][ $slug ] ) && is_array( $cfg['sizes'][ $slug ] ) ) {
		foreach ( $cfg['sizes'][ $slug ] as $variant_key => $variant ) {
			foreach ( (array) $variant as $s ) {
				if ( isset( $s['base_price'] ) && is_numeric( $s['base_price'] ) && $s['base_price'] > 0 ) {
					// A Luxury album must carry a resin or wood plate (the configurator has no "none"),
					// so its real starting price is base + the cheaper plate for that size.
					$plates = array();
					foreach ( array( 'plate_resin', 'plate_wood' ) as $pk ) {
						if ( isset( $s[ $pk ] ) && is_numeric( $s[ $pk ] ) && $s[ $pk ] > 0 ) {
							$plates[] = (float) $s[ $pk ];
						}
					}
					$from     = (float) $s['base_price'] + ( $plates ? min( $plates ) : 0 );
					$prices[] = $from;
					$highs[]  = (float) $s['base_price'] + ( $plates ? max( $plates ) : 0 );
					$rows[]   = array(
						'variant' => (string) $variant_key,
						'size'    => str_replace( 'x', '×', (string) ( isset( $s['display'] ) ? $s['display'] : $s['label'] ) ),
						'from'    => $from,
						'min'     => isset( $s['min_sheets'] ) ? (int) $s['min_sheets'] : 0,
						'max'     => isset( $s['max_sheets'] ) ? (int) $s['max_sheets'] : 0,
						'extra'   => isset( $s['extra_sheet_price'] ) ? (float) $s['extra_sheet_price'] : 0,
						'plate'   => (bool) $plates,
					);
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
	$high     = $highs ? max( $highs ) : null;
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
		'details'     => mementos_seo_details_html( $cfg, $col, $name, $short, $rows, $fmt ),
	);
}

/**
 * Visible, server-rendered collection details shown BELOW the configurator: the same live catalog
 * the configurator reads, so crawlers and shoppers get real text, sizes and prices.
 */
function mementos_seo_details_html( $cfg, $col, $name, $short, $rows, $fmt ) {
	$h  = 'esc_html';
	$vl = array(
		'solo_hard' => 'Hardbound',
		'solo_soft' => 'Softbound',
		'single'    => 'Album',
		'double'    => 'Album set',
	);
	$o  = '<main class="msd" id="collection-details" aria-labelledby="msd-title"><div class="msd-in">';
	$o .= '<p class="msd-eyebrow">Mementos Studio · Handcrafted in Bahrain</p>';
	$o .= '<h1 id="msd-title">' . $h( $name ) . '</h1>';
	$o .= '<p class="msd-lead">' . $h( $short ) . ' Each album is made by hand in our Bahrain studio. Design it online above: choose your size, cover material, colour and finish';
	$s  = isset( $cfg['settings'] ) && is_array( $cfg['settings'] ) ? $cfg['settings'] : array();
	if ( ! empty( $s['method_design_my_album_enabled'] ) && ! empty( $s['method_upload_spreads_enabled'] ) ) {
		$o .= ', then lay out your pages in the album designer or upload finished spreads';
	}
	$o .= '.</p>';
	$o .= '<p><a class="msd-cta" href="#top" onclick="window.scrollTo({top:0,behavior:\'smooth\'});return false;">Start designing</a></p>';

	if ( $rows ) {
		$multi = count( array_unique( wp_list_pluck( $rows, 'variant' ) ) ) > 1;
		$o .= '<h2>Sizes and prices</h2><div class="msd-tw"><table class="msd-t"><thead><tr>';
		$o .= ( $multi ? '<th scope="col">Type</th>' : '' ) . '<th scope="col">Size (in)</th><th scope="col">From (BD)</th><th scope="col">Sheets included</th><th scope="col">Extra sheet (BD)</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$o .= '<tr>' . ( $multi ? '<td>' . $h( isset( $vl[ $r['variant'] ] ) ? $vl[ $r['variant'] ] : ucfirst( $r['variant'] ) ) . '</td>' : '' );
			$o .= '<td>' . $h( $r['size'] ) . '</td><td>' . $h( $fmt( $r['from'] ) ) . '</td>';
			$o .= '<td>' . ( $r['min'] ? $h( $r['min'] . ( $r['max'] ? ' (up to ' . $r['max'] . ')' : '' ) ) : '' ) . '</td>';
			$o .= '<td>' . ( $r['extra'] > 0 ? $h( $fmt( $r['extra'] ) ) : '' ) . '</td></tr>';
		}
		$o .= '</tbody></table></div>';
		$notes = array( 'Starting prices include the sheets shown; the designer calculates your exact total before you order.' );
		if ( ! empty( $s['linen_surcharge'] ) ) {
			$notes[] = 'Linen covers add ' . $fmt( (float) $s['linen_surcharge'] ) . ' BD.';
		}
		if ( in_array( true, wp_list_pluck( $rows, 'plate' ), true ) ) {
			$notes[] = 'Prices include the cheaper decorative plate for each size; the other plate type costs more.';
		}
		$o .= '<p class="msd-note">' . $h( implode( ' ', $notes ) ) . '</p>';
	}

	$colors = isset( $cfg['colors'] ) && is_array( $cfg['colors'] ) ? $cfg['colors'] : array();
	$mats   = isset( $cfg['materials'] ) && is_array( $cfg['materials'] ) ? $cfg['materials'] : array();
	if ( $mats ) {
		$o .= '<h2>Cover materials and colours</h2><dl class="msd-dl">';
		foreach ( $mats as $m ) {
			if ( empty( $m['slug'] ) ) {
				continue;
			}
			$label = 'chamios' === $m['slug'] ? 'Chamois' : (string) $m['name'];
			$names = array_filter( wp_list_pluck( isset( $colors[ $m['slug'] ] ) ? (array) $colors[ $m['slug'] ] : array(), 'name' ) );
			$o    .= '<dt>' . $h( $label ) . '</dt><dd>' . $h( rtrim( (string) ( isset( $m['description'] ) ? $m['description'] : '' ), '.' ) );
			if ( $names ) {
				$o .= '. ' . $h( count( $names ) . ' colours: ' . implode( ', ', $names ) ) . '.';
			}
			$o .= '</dd>';
		}
		$o .= '</dl>';
	}

	$extras = array();
	if ( ! empty( $col['is_luxury'] ) && ! empty( $cfg['plate'] ) ) {
		$pl = $cfg['plate'];
		$rc = isset( $pl['resin']['colors'] ) ? count( (array) $pl['resin']['colors'] ) : 0;
		$wc = isset( $pl['wood']['colors'] ) ? count( (array) $pl['wood']['colors'] ) : 0;
		$wd = isset( $pl['wood']['designs'] ) ? count( (array) $pl['wood']['designs'] ) : 0;
		$extras[] = 'Decorative cover plate: resin (' . $rc . ' colours) or wood (' . $wc . ' finishes, ' . $wd . ' designs).';
	}
	if ( ! empty( $cfg['foiling_colors'] ) ) {
		$extras[] = 'Cover foiling: ' . implode( ', ', array_map( 'strval', (array) $cfg['foiling_colors'] ) ) . '.';
	}
	$extras[] = ! empty( $col['has_box'] ) ? 'Presented in a matching box.' : 'Album only, without a presentation box.';
	$o .= '<h2>Finishing</h2><ul>';
	foreach ( $extras as $x ) {
		$o .= '<li>' . $h( $x ) . '</li>';
	}
	$o .= '</ul>';

	$o .= '<h2>Before you order</h2><ul class="msd-links">';
	foreach ( array(
		'/how-to-order-a-custom-album/'   => 'How to order a custom photo album',
		'/softbound-vs-hardbound-album/'  => 'Softbound vs hardbound albums',
		'/linen-vs-chamois-album-covers/' => 'Linen vs chamois covers',
	) as $path => $label ) {
		$o .= '<li><a href="' . esc_url( home_url( $path ) ) . '">' . $h( $label ) . '</a></li>';
	}
	$o .= '</ul></div></main>';

	$o .= '<style>.msd{background:#fbf8f2;color:#2b2622;font:16px/1.6 Georgia,"Times New Roman",serif;padding:56px 16px 72px;border-top:1px solid #e7dfd2}'
		. '.msd-in{max-width:880px;margin:0 auto}.msd h1{font-size:2rem;line-height:1.2;margin:.2em 0 .4em;font-weight:600}'
		. '.msd h2{font-size:1.3rem;margin:2em 0 .6em;font-weight:600}.msd-eyebrow{font:600 12px/1.4 system-ui,sans-serif;letter-spacing:.12em;text-transform:uppercase;color:#8a7a66;margin:0}'
		. '.msd-lead{font-size:1.08rem;max-width:68ch}.msd-cta{display:inline-block;background:#2b2622;color:#fbf8f2;text-decoration:none;font:600 14px/1 system-ui,sans-serif;letter-spacing:.06em;text-transform:uppercase;padding:14px 22px;border-radius:2px}'
		. '.msd-tw{overflow-x:auto}.msd-t{width:100%;border-collapse:collapse;font:15px/1.4 system-ui,sans-serif}.msd-t th,.msd-t td{text-align:left;padding:10px 12px;border-bottom:1px solid #e7dfd2;white-space:nowrap}'
		. '.msd-t th{font-weight:600;color:#5c5047}.msd-note{font:14px/1.5 system-ui,sans-serif;color:#6b5f55}.msd-dl dt{font-weight:600;margin-top:.8em}.msd-dl dd{margin:0}'
		. '.msd a{color:#2b2622}.msd a.msd-cta{color:#fbf8f2}.msd-links a{text-underline-offset:3px}</style>';
	return $o;
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
	if ( ! empty( $d['jsonld'] ) ) {
		$out .= '<script type="application/ld+json">' . wp_json_encode( $d['jsonld'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "</script>\n";
	}
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
	$html = substr( $html, 0, $pos ) . mementos_seo_head_html( $d ) . substr( $html, $pos );
	return empty( $d['details'] ) ? $html : mementos_seo_add_details( $html, $d['details'] );
}

/**
 * Let the page scroll past the full-screen configurator and add the details section under it.
 * Only when the album-designer markup is exactly as expected; otherwise the page is left untouched.
 */
function mementos_seo_add_details( $html, $details ) {
	$lock  = 'html,body{height:100%;overflow:hidden;margin:0}';
	$frame = '.mad-frame{position:fixed;inset:0;width:100vw;height:100vh;height:100dvh;border:0;display:block}';
	$end   = strripos( $html, '</body>' );
	if ( false === strpos( $html, $lock ) || false === strpos( $html, $frame ) || false === $end ) {
		return $html;
	}
	$html = str_replace( $lock, 'html,body{margin:0}', $html );
	$html = str_replace( $frame, '.mad-frame{position:relative;width:100%;height:100vh;height:100dvh;border:0;display:block}', $html );
	$html = preg_replace( '#<body([^>]*)>#i', '<body$1 id="top">', $html, 1 );
	$end  = strripos( $html, '</body>' );
	return substr( $html, 0, $end ) . $details . "\n" . substr( $html, $end );
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

// 5) Empty archives (demo categories/tags whose posts were unpublished, an empty /blog/) are thin pages:
//    tell crawlers not to index them. Re-indexes automatically once real posts exist.
add_action(
	'template_redirect',
	function () {
		if ( ( is_archive() || is_home() ) && ! have_posts() && ! headers_sent() ) {
			header( 'X-Robots-Tag: noindex, follow', true );
		}
	},
	20
);
add_filter(
	'wp_robots',
	function ( $robots ) {
		if ( ( is_archive() || is_home() ) && ! have_posts() ) {
			$robots['noindex'] = true;
			$robots['follow']  = true;
			unset( $robots['index'] );
		}
		return $robots;
	},
	99
);

// 6) Single WooCommerce product pages: AIOSEO (free) emits no Product schema and these products have no
//    description, so add a factual meta description and Product JSON-LD from the live product record.
/** Hand-written descriptions, from facts shown on the product pages. Slug => description. */
function mementos_seo_product_descriptions() {
	return array(
		'photo-print' => 'Single photo prints from Mementos Studio in Bahrain: choose your size and paper finish, upload your photos and order online, priced per print.',
		'dazza'       => 'Customisable jewelry and dazza boxes from Mementos Studio in Bahrain, made to order for gifts and keepsakes.',
		'wooden-frame' => 'A wooden frame in your chosen size, printed and mounted for you by Mementos Studio in Bahrain, ready to display.',
		'self-mount'   => 'A felt-covered self-mount album from Mementos Studio, Bahrain, that you fill yourself. Printed photographs are not included.',
		'white-filling' => 'A white filling album from Mementos Studio, Bahrain: white pages, ready for you to add your own printed photographs.',
		'memories-album' => 'The Memories Album by Mementos Studio, Bahrain: design your album online, choosing size, cover and finish.',
	);
}

function mementos_seo_current_product() {
	if ( ! function_exists( 'is_product' ) || ! is_product() || ! function_exists( 'wc_get_product' ) ) {
		return null;
	}
	$p = wc_get_product( get_queried_object_id() );
	return ( $p && 'publish' === $p->get_status() ) ? $p : null;
}

function mementos_seo_product_description( $p ) {
	$map  = mementos_seo_product_descriptions();
	$slug = $p->get_slug();
	if ( isset( $map[ $slug ] ) ) {
		$d = $map[ $slug ];
	} else {
		$d = trim( wp_strip_all_tags( $p->get_short_description() ? $p->get_short_description() : $p->get_description() ) );
		$d = $d ? wp_trim_words( $d, 24, '…' ) : wp_strip_all_tags( $p->get_name() ) . ' by Mementos Studio, Bahrain.';
	}
	$price = (float) $p->get_price();
	if ( $price > 0 ) {
		$d .= ' From ' . rtrim( rtrim( number_format( $price, 3, '.', '' ), '0' ), '.' ) . ' BD.';
	}
	return $d;
}

add_filter(
	'aioseo_description',
	function ( $desc ) {
		$p = mementos_seo_current_product();
		return ( $p && '' === trim( (string) $desc ) ) ? mementos_seo_product_description( $p ) : $desc;
	},
	20
);

function mementos_seo_product_jsonld( $p ) {
		$url    = get_permalink( $p->get_id() );
		$images = array();
		foreach ( array_merge( array( $p->get_image_id() ), $p->get_gallery_image_ids() ) as $img ) {
			$src = $img ? wp_get_attachment_image_url( $img, 'large' ) : '';
			if ( $src ) {
				$images[] = $src;
			}
		}
		$product = array(
			'@context'    => 'https://schema.org',
			'@type'       => 'Product',
			'@id'         => $url . '#product',
			'name'        => wp_strip_all_tags( $p->get_name() ),
			'description' => mementos_seo_product_description( $p ),
			'url'         => $url,
			'brand'       => array( '@type' => 'Brand', 'name' => 'Mementos Studio' ),
			'manufacturer' => array( '@id' => home_url( '/#organization' ) ),
		);
		if ( $images ) {
			$product['image'] = array_slice( $images, 0, 6 );
		}
		if ( $p->get_sku() ) {
			$product['sku'] = $p->get_sku();
		}
		$price = (float) $p->get_price();
		if ( $price > 0 ) {
			$product['offers'] = array(
				'@type'         => 'Offer',
				'price'         => rtrim( rtrim( number_format( $price, 3, '.', '' ), '0' ), '.' ),
				'priceCurrency' => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'BHD',
				'availability'  => $p->is_in_stock() ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
				'url'           => $url,
				'seller'        => array( '@id' => home_url( '/#organization' ) ),
			);
		}
		return "\n<!-- mementos-seo-product -->\n<script type=\"application/ld+json\">" . wp_json_encode( $product, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "</script>\n";
}

add_action(
	'wp_head',
	function () {
		$p = mementos_seo_current_product();
		if ( $p ) {
			echo mementos_seo_product_jsonld( $p ); // phpcs:ignore WordPress.Security.EscapeOutput -- JSON-LD built with wp_json_encode.
		}
	},
	30
);

// Product pages the album-designer plugin serves as a bare configurator shell (no wp_head), e.g. Memories Album:
// add the full head set from the WooCommerce product record.
add_action(
	'template_redirect',
	function () {
		$p = mementos_seo_current_product();
		if ( ! $p ) {
			return;
		}
		ob_start(
			function ( $html ) use ( $p ) {
				if ( false !== stripos( $html, 'mementos-seo-product' ) || false === stripos( $html, 'mad-frame' ) ) {
					return $html; // normal theme page, or already handled.
				}
				$img = wp_get_attachment_image_url( $p->get_image_id(), 'large' );
				$d   = array(
					'title'       => wp_strip_all_tags( $p->get_name() ) . ' — Handcrafted Photo Albums in Bahrain | Mementos Studio',
					'description' => mementos_seo_product_description( $p ),
					'url'         => get_permalink( $p->get_id() ),
					'image'       => $img ? $img : '',
					'jsonld'      => null,
				);
				$head = mementos_seo_head_html( $d ) . mementos_seo_product_jsonld( $p );
				$html = preg_replace( '#<title>.*?</title>#is', '<title>' . esc_html( $d['title'] ) . '</title>', $html, 1 );
				$pos  = stripos( $html, '</head>' );
				return false === $pos ? $html : substr( $html, 0, $pos ) . $head . substr( $html, $pos );
			}
		);
	},
	1
);

// Refresh the cached catalog when WooCommerce products change.
add_action(
	'save_post_product',
	function () {
		delete_transient( 'mementos_seo_cfg' );
	}
);
