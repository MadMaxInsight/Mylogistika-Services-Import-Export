<?php
require_once( dirname(__FILE__, 5) . '/wp-load.php' );

// исключаемые term_id
$exclude_ids = [105, 107, 104, 109, 106];

$terms = get_terms([
    'taxonomy'   => 'service-category',
    'hide_empty' => false,
]);
;
if ( is_wp_error( $terms ) ) {
    echo "Ошибка: " . $terms->get_error_message() . PHP_EOL;
    exit;
}

if ( empty( $terms ) ) {
    echo "Термины не найдены.\n";
    exit;
}

foreach ( $terms as $term ) {
    if ( in_array( $term->term_id, $exclude_ids ) ) {
        echo "Пропускаем term_id={$term->term_id} ({$term->name})\n";
        continue;
    }

    $result = wp_delete_term( $term->term_id, 'service-category' );

    if ( is_wp_error( $result ) ) {
        echo "Ошибка при удалении term_id={$term->term_id}: " . $result->get_error_message() . PHP_EOL;
    } elseif ( $result === false ) {
        echo "Не удалось удалить term_id={$term->term_id}\n";
    } else {
        echo "Удалён term_id={$term->term_id} ({$term->name})\n";
    }
}