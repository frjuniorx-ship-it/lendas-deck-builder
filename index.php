<?php
/**
 * Entry point para /ferramentas/ no servidor WordPress.
 * Injeta dados do usuário logado automaticamente — sem login separado.
 * Apache serve este arquivo antes do index.html (ver .htaccess).
 */

// Localiza o wp-load.php subindo na hierarquia de pastas
$wp_load = '';
$dir = __DIR__;
for ($i = 0; $i < 5; $i++) {
    $dir = dirname($dir);
    if (file_exists($dir . '/wp-load.php')) { $wp_load = $dir . '/wp-load.php'; break; }
}

$wp_data = 'null';
if ($wp_load) {
    require_once $wp_load;
    $user = wp_get_current_user();
    if ($user->ID) {
        $wp_data = wp_json_encode([
            'id'    => $user->ID,
            'nome'  => $user->display_name,
            'email' => $user->user_email,
            'nonce' => wp_create_nonce('wp_rest'),
        ]);
    }
}

// Lê o index.html e injeta os dados do WP antes do </head>
$html   = file_get_contents(__DIR__ . '/index.html');
$inject = '<script>window.LB_WP=' . $wp_data . ';</script>';
header('Content-Type: text/html; charset=utf-8');
echo str_replace('</head>', $inject . "\n</head>", $html);
