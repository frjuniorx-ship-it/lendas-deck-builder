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

/* ─── WooCommerce: aba "Meus Baralhos" na Minha Conta ─── */

add_filter( 'woocommerce_account_menu_items', function ( $items ) {
    $new = [];
    foreach ( $items as $key => $label ) {
        $new[ $key ] = $label;
        if ( $key === 'dashboard' ) $new['meus-baralhos'] = 'Meus Baralhos';
    }
    return $new;
} );

add_action( 'init', function () {
    add_rewrite_endpoint( 'meus-baralhos', EP_ROOT | EP_PAGES );
}, 5 ); // prioridade 5 para registrar antes do handler OPTIONS sair com exit()

add_action( 'woocommerce_account_meus-baralhos_endpoint', function () {
    $user  = wp_get_current_user();
    $decks = get_user_meta( $user->ID, 'lb_decks', true );
    $count = is_array( $decks ) ? count( $decks ) : 0;
    $url   = home_url( '/ferramentas/' );
    ?>
    <h2 style="font-size:22px;margin-bottom:14px">Meus Baralhos</h2>
    <p style="margin-bottom:10px">
        Você tem <strong><?php echo $count; ?> baralho<?php echo $count !== 1 ? 's' : ''; ?></strong>
        salvo<?php echo $count !== 1 ? 's' : ''; ?>.
    </p>
    <?php if ( $count > 0 ) : ?>
    <ul style="margin:10px 0 20px;padding-left:20px;list-style:disc">
        <?php foreach ( array_slice( $decks, 0, 6 ) as $d ) : ?>
        <li style="margin-bottom:5px">
            <?php echo esc_html( $d['nome'] ?? 'Sem nome' ); ?>
            <span style="color:#999;font-size:13px">(<?php echo count( $d['cards'] ?? [] ); ?> cartas)</span>
        </li>
        <?php endforeach; ?>
        <?php if ( $count > 6 ) : ?>
        <li style="color:#999">… e mais <?php echo $count - 6; ?></li>
        <?php endif; ?>
    </ul>
    <?php endif; ?>
    <a href="<?php echo esc_url( $url ); ?>"
       style="display:inline-block;padding:11px 24px;background:#bea356;color:#1a130a;font-weight:700;border-radius:8px;text-decoration:none;font-size:14px">
        Abrir Deck Builder →
    </a>
    <?php
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
    // Inventário de coleção: GET = buscar, POST = salvar
    register_rest_route( 'lendas/v1', '/meu-inventario', [
        [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => 'lb_api_get_inventario',
            'permission_callback' => fn() => is_user_logged_in(),
        ],
        [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => 'lb_api_salvar_inventario',
            'permission_callback' => fn() => is_user_logged_in(),
        ],
    ] );

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

function lb_api_get_inventario( WP_REST_Request $req ): WP_REST_Response {
    $inv = get_user_meta( get_current_user_id(), 'lb_inventario', true );
    return rest_ensure_response( is_array( $inv ) ? $inv : (object) [] );
}

function lb_api_salvar_inventario( WP_REST_Request $req ): WP_REST_Response|WP_Error {
    $body = $req->get_json_params();
    if ( ! is_array( $body ) ) {
        return new WP_Error( 'invalid_data', 'JSON inválido', [ 'status' => 400 ] );
    }
    $inv = [];
    foreach ( $body as $id => $entry ) {
        if ( ! is_numeric( $id ) ) continue;
        $inv[ (int) $id ] = [
            'qty'   => min( absint( $entry['qty']   ?? 0 ), 999 ),
            'foil'  => min( absint( $entry['foil']  ?? 0 ), 999 ),
            'troca' => min( absint( $entry['troca'] ?? 0 ), 999 ),
        ];
    }
    update_user_meta( get_current_user_id(), 'lb_inventario', $inv );
    return rest_ensure_response( [ 'ok' => true ] );
}

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
