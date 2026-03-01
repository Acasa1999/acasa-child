<?php
/**
 * One-time content seed for "Misiunea noastră" and "Cum funcționează" pages.
 *
 * Usage (CLI, recommended):
 *   php wp-content/themes/acasa-child/tools/seed-mission-pages.php
 *
 * This script:
 * - creates/updates the two pages with curated Gutenberg content
 * - links them in the primary nav menu under "Despre" / "Despre noi" when found
 * - maps image blocks to existing media by filename (fallback: placeholder block)
 * - runs only once unless the guard option is removed manually
 */

if ( ! defined( 'ABSPATH' ) ) {
	$wp_load = dirname( __DIR__, 4 ) . '/wp-load.php';
	if ( ! file_exists( $wp_load ) ) {
		fwrite( STDERR, "Could not find wp-load.php\n" );
		exit( 1 );
	}
	require_once $wp_load;
}

if ( ! function_exists( 'wp_insert_post' ) ) {
	fwrite( STDERR, "WordPress did not bootstrap correctly.\n" );
	exit( 1 );
}

$guard_option = 'acasa_seed_mission_pages_v1_completed';
$already_done = get_option( $guard_option, '' );

if ( ! empty( $already_done ) ) {
	echo "Seed already executed at {$already_done}. No changes made.\n";
	exit( 0 );
}

function acasa_seed_find_attachment_id_by_filename( $filename ) {
	global $wpdb;

	$like = '%' . $wpdb->esc_like( $filename );
	$id   = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT post_id
			FROM {$wpdb->postmeta}
			WHERE meta_key = '_wp_attached_file'
			  AND meta_value LIKE %s
			ORDER BY post_id DESC
			LIMIT 1",
			$like
		)
	);

	return $id > 0 ? $id : 0;
}

function acasa_seed_image_block( $filename, $alt, $placeholder_size = '1200x800' ) {
	$attachment_id = acasa_seed_find_attachment_id_by_filename( $filename );

	if ( $attachment_id ) {
		$url = wp_get_attachment_url( $attachment_id );
		if ( $url ) {
			return '<!-- wp:image {"id":' . $attachment_id . ',"sizeSlug":"large","linkDestination":"none"} -->' . "\n"
				. '<figure class="wp-block-image size-large"><img src="' . esc_url( $url ) . '" alt="' . esc_attr( $alt ) . '" class="wp-image-' . $attachment_id . '"/></figure>' . "\n"
				. '<!-- /wp:image -->';
		}
	}

	$placeholder_text = '[Placeholder imagine ' . $placeholder_size . ': ' . $filename . ']';
	return '<!-- wp:group {"style":{"spacing":{"padding":{"top":"20px","right":"20px","bottom":"20px","left":"20px"}},"color":{"background":"#f7f7f7"}},"layout":{"type":"constrained"}} -->' . "\n"
		. '<div class="wp-block-group has-background" style="background-color:#f7f7f7;padding-top:20px;padding-right:20px;padding-bottom:20px;padding-left:20px"><!-- wp:paragraph {"align":"center"} -->' . "\n"
		. '<p class="has-text-align-center">' . esc_html( $placeholder_text ) . '</p>' . "\n"
		. '<!-- /wp:paragraph --></div>' . "\n"
		. '<!-- /wp:group -->';
}

function acasa_seed_replace_tokens( $content, array $tokens ) {
	foreach ( $tokens as $token => $value ) {
		$content = str_replace( $token, $value, $content );
	}
	return $content;
}

function acasa_seed_upsert_page( $slug, $title, $content ) {
	$existing = get_page_by_path( $slug, OBJECT, 'page' );
	$postarr  = array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_name'    => $slug,
		'post_title'   => $title,
		'post_content' => $content,
	);

	if ( $existing instanceof WP_Post ) {
		$postarr['ID'] = $existing->ID;
	}

	$post_id = wp_insert_post( $postarr, true );
	if ( is_wp_error( $post_id ) ) {
		throw new RuntimeException( 'Failed upserting page "' . $title . '": ' . $post_id->get_error_message() );
	}

	return (int) $post_id;
}

function acasa_seed_find_primary_menu_id() {
	$locations = get_nav_menu_locations();
	$candidates = array( 'primary', 'main', 'header', 'menu-1' );

	foreach ( $candidates as $loc ) {
		if ( ! empty( $locations[ $loc ] ) ) {
			return (int) $locations[ $loc ];
		}
	}

	$menu = wp_get_nav_menu_object( 'Principal' );
	if ( $menu && ! is_wp_error( $menu ) ) {
		return (int) $menu->term_id;
	}

	return 0;
}

function acasa_seed_normalize_label( $text ) {
	$text = remove_accents( (string) $text );
	$text = strtolower( trim( $text ) );
	return preg_replace( '/\s+/', ' ', $text );
}

function acasa_seed_find_despre_parent_item_id( $menu_id ) {
	if ( ! $menu_id ) {
		return 0;
	}

	$items = wp_get_nav_menu_items( $menu_id, array( 'post_status' => 'any' ) );
	if ( empty( $items ) ) {
		return 0;
	}

	foreach ( $items as $item ) {
		$label = acasa_seed_normalize_label( $item->title );
		if ( in_array( $label, array( 'despre', 'despre noi' ), true ) ) {
			return (int) $item->ID;
		}
	}

	return 0;
}

function acasa_seed_upsert_menu_item( $menu_id, $page_id, $title, $parent_item_id, $position ) {
	if ( ! $menu_id || ! $page_id ) {
		return 0;
	}

	$items     = wp_get_nav_menu_items( $menu_id, array( 'post_status' => 'any' ) );
	$existing  = 0;

	if ( ! empty( $items ) ) {
		foreach ( $items as $item ) {
			if ( 'post_type' === $item->type && 'page' === $item->object && (int) $item->object_id === (int) $page_id ) {
				$existing = (int) $item->ID;
				break;
			}
		}
	}

	$args = array(
		'menu-item-title'     => $title,
		'menu-item-object-id' => $page_id,
		'menu-item-object'    => 'page',
		'menu-item-type'      => 'post_type',
		'menu-item-status'    => 'publish',
		'menu-item-parent-id' => $parent_item_id,
		'menu-item-position'  => (int) $position,
	);

	$menu_item_id = wp_update_nav_menu_item( $menu_id, $existing, $args );
	if ( is_wp_error( $menu_item_id ) ) {
		throw new RuntimeException( 'Failed menu item "' . $title . '": ' . $menu_item_id->get_error_message() );
	}

	return (int) $menu_item_id;
}

$site_url = home_url( '/' );

$mission_image_block = acasa_seed_image_block(
	'2026-grup-si-casa.jpg',
	'Voluntari și familie în comunitatea ACASĂ'
);

$how_image_block_1 = acasa_seed_image_block(
	'2026-romani-si-americani-pe-santier.jpg',
	'Voluntari pe șantierul ACASĂ'
);

$how_image_block_2 = acasa_seed_image_block(
	'familie-cu-cheia-casei.png',
	'Familie care primește cheia noii locuințe'
);

$mission_template = <<<'EOT'
<!-- wp:group {"style":{"spacing":{"padding":{"top":"24px","right":"24px","bottom":"24px","left":"24px"}},"border":{"left":{"color":"#fcd602","width":"5px"}},"color":{"background":"rgba(247,223,87,0.22)"}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group has-background" style="border-left-color:#fcd602;border-left-width:5px;background-color:rgba(247,223,87,0.22);padding-top:24px;padding-right:24px;padding-bottom:24px;padding-left:24px"><!-- wp:paragraph -->
<p>La ACASĂ, misiunea noastră este simplă: construim și renovăm locuințe decente pentru familii vulnerabile, iar în paralel folosim experiența din construcții pentru proiecte care cresc șansele de trai bun în comunitate.</p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->

<!-- wp:paragraph -->
<p>Credem că <strong>totul începe acasă</strong>: de aici pornesc sănătatea, educația, stabilitatea și încrederea că viața se poate schimba. De aceea lucrăm pe termen lung, împreună cu voluntari, familii, parteneri și donatori.</p>
<!-- /wp:paragraph -->

<!-- wp:columns -->
<div class="wp-block-columns"><!-- wp:column -->
<div class="wp-block-column"><!-- wp:heading {"level":4} -->
<h4 class="wp-block-heading">7 comunități</h4>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Programul de construcții non-profit este activ din 1999 și a construit 7 comunități în județul Cluj.</p>
<!-- /wp:paragraph --></div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column"><!-- wp:heading {"level":4} -->
<h4 class="wp-block-heading">116 locuințe noi</h4>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Pe lângă acestea, au fost realizate și peste 430 de renovări sau reabilitări termice prin proiecte dedicate.</p>
<!-- /wp:paragraph --></div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column"><!-- wp:heading {"level":4} -->
<h4 class="wp-block-heading">Impact în timp</h4>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>În rapoartele anuale găsim borne clare: familii mutate acasă, ore de muncă, voluntari și proiecte speciale pentru comunități vulnerabile.</p>
<!-- /wp:paragraph --></div>
<!-- /wp:column --></div>
<!-- /wp:columns -->

{{MISSION_IMAGE}}

<!-- wp:paragraph -->
<p>Misiunea înseamnă și dezvoltare comunitară: reparații, lucrări de eficientizare, intervenții punctuale pentru locuințe improprii, plus proiecte conexe în care folosim know-how-ul din șantier pentru a crește autonomia familiilor și a comunității.</p>
<!-- /wp:paragraph -->

<!-- wp:quote -->
<blockquote class="wp-block-quote"><p>În 2013, 4 familii au muncit 3.615 ore la casele lor, iar voluntarii au adăugat 8.599 de ore. Schimbarea se construiește împreună.</p></blockquote>
<!-- /wp:quote -->

<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button {"className":"is-style-outline"} -->
<div class="wp-block-button is-style-outline"><a class="wp-block-button__link wp-element-button" href="{{URL_CONSTRUCTII}}">Vezi programul de construcții</a></div>
<!-- /wp:button -->

<!-- wp:button {"className":"is-style-outline"} -->
<div class="wp-block-button is-style-outline"><a class="wp-block-button__link wp-element-button" href="{{URL_RAPOARTE}}">Rapoarte anuale</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons -->
EOT;

$how_template = <<<'EOT'
<!-- wp:group {"style":{"spacing":{"padding":{"top":"24px","right":"24px","bottom":"24px","left":"24px"}},"border":{"left":{"color":"#fcd602","width":"5px"}},"color":{"background":"rgba(247,223,87,0.22)"}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group has-background" style="border-left-color:#fcd602;border-left-width:5px;background-color:rgba(247,223,87,0.22);padding-top:24px;padding-right:24px;padding-bottom:24px;padding-left:24px"><!-- wp:paragraph -->
<p>Modelul ACASĂ este non-profit și circular: construim împreună, familiile plătesc costul real în timp, iar banii se întorc în alte locuințe pentru alte familii.</p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Pe scurt, în 5 pași</h2>
<!-- /wp:heading -->

<!-- wp:list {"ordered":true} -->
<ol class="wp-block-list"><li><strong>Selectăm familii vulnerabile</strong>, care au nevoie reală de o locuință decentă și pot susține un plan pe termen lung.</li><li><strong>Construim alături de voluntari și familie</strong>. Fiecare casă este ridicată prin muncă în echipă, nu prin profit.</li><li><strong>Costul construcției este achitat fără dobândă</strong>, în rate accesibile, pe o perioadă de până la 20 de ani.</li><li><strong>Ratele se întorc în proiect</strong>: fondurile recuperate finanțează, în timp, alte și alte locuințe.</li><li><strong>Familiile se mută acasă și devin proprietare</strong>, cu mai multă stabilitate și încredere în puterea lor de a-și schimba viața.</li></ol>
<!-- /wp:list -->

{{HOW_IMAGE_1}}

<!-- wp:columns -->
<div class="wp-block-columns"><!-- wp:column -->
<div class="wp-block-column"><!-- wp:heading {"level":4} -->
<h4 class="wp-block-heading">De ce este unic pentru donatori?</h4>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Donația de azi nu lucrează o singură dată. În timp, aceeași investiție socială susține noi familii, pentru că sistemul este gândit să se autosusțină.</p>
<!-- /wp:paragraph --></div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column"><!-- wp:heading {"level":4} -->
<h4 class="wp-block-heading">Demnitate, nu dependență</h4>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Familiile nu primesc doar chei, ci participă direct la schimbare. Asta construiește încredere, responsabilitate și o bază solidă pentru viitorul copiilor.</p>
<!-- /wp:paragraph --></div>
<!-- /wp:column --></div>
<!-- /wp:columns -->

<!-- wp:quote -->
<blockquote class="wp-block-quote"><p>În 2020, 4 familii au devenit proprietare pentru prima dată în viață. Acesta este sensul modelului ACASĂ.</p></blockquote>
<!-- /wp:quote -->

{{HOW_IMAGE_2}}

<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button {"className":"is-style-outline"} -->
<div class="wp-block-button is-style-outline"><a class="wp-block-button__link wp-element-button" href="{{URL_DONATII}}">Susține o familie</a></div>
<!-- /wp:button -->

<!-- wp:button {"className":"is-style-outline"} -->
<div class="wp-block-button is-style-outline"><a class="wp-block-button__link wp-element-button" href="{{URL_VOLUNTARI}}">Vino ca voluntar</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons -->
EOT;

$mission_content = acasa_seed_replace_tokens(
	$mission_template,
	array(
		'{{MISSION_IMAGE}}'  => $mission_image_block,
		'{{URL_CONSTRUCTII}}' => esc_url( trailingslashit( $site_url ) . 'constructii-non-profit/' ),
		'{{URL_RAPOARTE}}'   => esc_url( trailingslashit( $site_url ) . 'rapoarte-anuale/' ),
	)
);

$how_content = acasa_seed_replace_tokens(
	$how_template,
	array(
		'{{HOW_IMAGE_1}}' => $how_image_block_1,
		'{{HOW_IMAGE_2}}' => $how_image_block_2,
		'{{URL_DONATII}}' => esc_url( trailingslashit( $site_url ) . 'donatii-online/' ),
		'{{URL_VOLUNTARI}}' => esc_url( trailingslashit( $site_url ) . 'voluntari-pentru-acasa/' ),
	)
);

try {
	$mission_page_id = acasa_seed_upsert_page( 'misiunea-noastra', 'Misiunea noastră', $mission_content );
	$how_page_id     = acasa_seed_upsert_page( 'cum-functioneaza', 'Cum funcționează', $how_content );

	$menu_id         = acasa_seed_find_primary_menu_id();
	$despre_parent   = acasa_seed_find_despre_parent_item_id( $menu_id );

	if ( $menu_id ) {
		acasa_seed_upsert_menu_item( $menu_id, $mission_page_id, 'Misiunea noastră', $despre_parent, 999 );
		acasa_seed_upsert_menu_item( $menu_id, $how_page_id, 'Cum funcționează', $despre_parent, 1000 );
		echo "Menu links added/updated in menu ID {$menu_id}.\n";
	} else {
		echo "Primary menu not found. Pages were created, menu links skipped.\n";
	}

	update_option( $guard_option, gmdate( 'Y-m-d H:i:s' ), false );

	echo "Done.\n";
	echo "- Page ID {$mission_page_id}: /misiunea-noastra/\n";
	echo "- Page ID {$how_page_id}: /cum-functioneaza/\n";
	echo "Guard option set: {$guard_option}\n";
} catch ( Throwable $e ) {
	fwrite( STDERR, "Error: " . $e->getMessage() . "\n" );
	exit( 1 );
}
