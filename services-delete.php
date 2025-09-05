<?php
require_once( dirname(__FILE__, 5) . '/wp-load.php' );

// получаем все посты типа services
$posts = get_posts([
    'post_type'      => 'services',
    'post_status'    => 'any',
    'numberposts'    => -1,
    'fields'         => 'ids', // только ID для ускорения
]);

if ( empty( $posts ) ) {
    echo "Постов с post_type=services не найдено.\n";
    exit;
}

foreach ( $posts as $post_id ) {
    $result = wp_delete_post( $post_id, true ); // true = принудительное удаление, минуя корзину

    if ( $result === false ) {
        echo "Не удалось удалить post_id={$post_id}\n";
    } else {
        echo "Удалён post_id={$post_id}\n";
    }
}