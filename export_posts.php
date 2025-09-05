<?

require_once( dirname(__FILE__, 5) . '/wp-load.php' );

$csv_file = __DIR__ . "/services_export.csv";
$fp = fopen($csv_file, 'w');

// Запишем заголовки
fputcsv($fp, ["ID", "Slug", "Content"], "\t");

// Получаем все посты типа services
$args = [
    'post_type'      => 'services',
    'posts_per_page' => -1,
    'post_status'    => 'publish',
    'fields'         => 'all'
];
$posts = get_posts($args);

foreach ($posts as $post) {
    fputcsv($fp, [
        $post->ID,
        $post->post_name,   // slug
        $post->post_content
    ], "\t");
}

fclose($fp);

echo "✅ Экспортировано " . count($posts) . " записей в файл: {$csv_file}\n";
