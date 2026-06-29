<?php
/**
 * Wrapper genérico: injeta sessão WordPress em qualquer página HTML.
 * Usado pelo .htaccess para servir inventario.html, biblioteca.html, etc.
 */

$allowed = ['inventario', 'biblioteca', 'glossario', 'counter'];
$page    = preg_replace( '/[^a-z0-9\-_]/i', '', $_GET['f'] ?? '' );
if ( ! in_array( $page, $allowed, true ) ) { http_response_code( 404 ); exit( 'Not found' ); }

$file = __DIR__ . '/' . $page . '.html';
if ( ! file_exists( $file ) ) { http_response_code( 404 ); exit( 'Not found' ); }

// Localiza wp-load.php subindo na hierarquia de pastas
$wp_load = '';
$dir = __DIR__;
for ( $i = 0; $i < 5; $i++ ) {
    $dir = dirname( $dir );
    if ( file_exists( $dir . '/wp-load.php' ) ) { $wp_load = $dir . '/wp-load.php'; break; }
}

$wp_data = 'null';
if ( $wp_load ) {
    require_once $wp_load;
    $user = wp_get_current_user();
    if ( $user->ID ) {
        $wp_data = wp_json_encode( [
            'id'    => $user->ID,
            'nome'  => $user->display_name,
            'email' => $user->user_email,
            'nonce' => wp_create_nonce( 'wp_rest' ),
        ] );
    }
}

$html   = file_get_contents( $file );
$inject = '<script>window.LB_WP=' . $wp_data . ';</script>';
header( 'Content-Type: text/html; charset=utf-8' );
echo str_replace( '</head>', $inject . "\n</head>", $html );
