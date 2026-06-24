<?php
/**
 * Plugin Name:  Lendas & Batalhas — Deck Sync
 * Description:  REST API para sincronização de baralhos dos jogadores (autenticação via JWT)
 * Version:      1.0
 * Author:       Lendas & Batalhas
 *
 * INSTALAÇÃO:
 *   1. Instale o plugin "JWT Authentication for WP-API" (gratuito no repositório WP)
 *   2. No wp-config.php, adicione ANTES do "That's all, stop editing!":
 *        define('JWT_AUTH_SECRET_KEY', 'uma-string-secreta-longa-e-aleatoria');
 *        define('JWT_AUTH_CORS_ENABLE', true);
 *   3. Suba este arquivo em wp-content/plugins/lb-deck-sync/lb-deck-sync.php
 *   4. Ative o plugin no painel WP → Plugins
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ─── CORS: permite requisições do deck builder (GitHub Pages) ─── */

add_filter( 'rest_pre_serve_request', function ( $served, $result, $request ) {
    $origin  = get_http_origin();
    $allowed = [
        'https://frjuniorx-ship-it.github.io',
        // Se mover o app para domínio próprio, adicione aqui, ex:
        // 'https://ferramentas.lendasebatalhas.com.br',
    ];
    if ( in_array( $origin, $allowed, true ) ) {
        header( 'Access-Control-Allow-Origin: ' . esc_url_raw( $origin ) );
        header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
        header( 'Access-Control-Allow-Headers: Authorization, Content-Type' );
        header( 'Vary: Origin' );
    }
    return $served;
}, 10, 3 );

add_action( 'init', function () {
    if ( 'OPTIONS' !== $_SERVER['REQUEST_METHOD'] ) return;
    if ( ! isset( $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'] ) ) return;
    $origin  = $_SERVER['HTTP_ORIGIN'] ?? '';
    $allowed = [ 'https://frjuniorx-ship-it.github.io' ];
    if ( ! in_array( $origin, $allowed, true ) ) return;
    header( 'Access-Control-Allow-Origin: ' . $origin );
    header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
    header( 'Access-Control-Allow-Headers: Authorization, Content-Type' );
    header( 'Access-Control-Max-Age: 86400' );
    http_response_code( 204 );
    exit;
} );

/* ─── Endpoints REST ─── */

add_action( 'rest_api_init', function () {

    // Perfil do usuário logado (usado pelo app para validar o token)
    register_rest_route( 'lendas/v1', '/me', [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'lb_api_me',
        'permission_callback' => fn() => is_user_logged_in(),
    ] );

    // Baralhos do usuário: GET = buscar, POST = salvar tudo de uma vez
    register_rest_route( 'lendas/v1', '/meus-decks', [
        [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => 'lb_api_get_decks',
            'permission_callback' => fn() => is_user_logged_in(),
        ],
        [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => 'lb_api_salvar_decks',
            'permission_callback' => fn() => is_user_logged_in(),
        ],
    ] );
} );

function lb_api_me( WP_REST_Request $req ): WP_REST_Response {
    $u = wp_get_current_user();
    return rest_ensure_response( [
        'id'    => $u->ID,
        'nome'  => $u->display_name,
        'email' => $u->user_email,
    ] );
}

function lb_api_get_decks( WP_REST_Request $req ): WP_REST_Response {
    $decks = get_user_meta( get_current_user_id(), 'lb_decks', true );
    return rest_ensure_response( is_array( $decks ) ? $decks : [] );
}

function lb_api_salvar_decks( WP_REST_Request $req ): WP_REST_Response|WP_Error {
    $body = $req->get_json_params();
    if ( ! is_array( $body ) ) {
        return new WP_Error( 'invalid_data', 'JSON inválido', [ 'status' => 400 ] );
    }

    $decks = array_values( array_map( function ( $d ) {
        return [
            'id'        => sanitize_text_field( $d['id']        ?? uniqid( 'd' ) ),
            'nome'      => sanitize_text_field( $d['nome']       ?? 'Sem nome' ),
            'fav'       => ! empty( $d['fav'] ),
            'isExample' => ! empty( $d['isExample'] ),
            'cards'     => array_values( array_map(
                fn( $c ) => [
                    'id'  => absint( $c['id']  ?? 0 ),
                    'qty' => min( absint( $c['qty'] ?? 0 ), 99 ),
                ],
                is_array( $d['cards'] ?? null ) ? $d['cards'] : []
            ) ),
        ];
    }, $body ) );

    update_user_meta( get_current_user_id(), 'lb_decks', $decks );
    return rest_ensure_response( [ 'ok' => true, 'count' => count( $decks ) ] );
}
