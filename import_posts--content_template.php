<?php
/**
 * Импорт страниц услуг из CSV в ACF Gutenberg блоки (content_template.txt + placeholders + авто-загрузка картинок)
 */

require_once( dirname(__FILE__, 5) . '/wp-load.php' );
require_once ABSPATH . 'wp-admin/includes/post.php'; // для get_post()
require_once ABSPATH . 'wp-admin/includes/image.php'; // для wp_generate_attachment_metadata

// Отключаем автофильтры WP для контента (чтобы не ломал кавычки и HTML в блоках)
remove_all_filters('content_save_pre');
remove_all_filters('content_filtered_save_pre');

$log_file      = __DIR__ . '/import_log.txt';
$csv_file      = __DIR__ . "/services_import.csv";
$template_file = __DIR__ . "/content_template.txt";

if (!file_exists($csv_file)) die("❌ Нет файла $csv_file\n");
if (!file_exists($template_file)) die("❌ Нет файла $template_file\n");

// ---------- Загружаем шаблон ----------
$template_raw = file($template_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
// Убираем комментарии (# в начале строки)
$template_clean = array_filter($template_raw, function($line) {
    return !preg_match('/^\s*#/', $line);
});
$template = implode("\n", $template_clean);

// ---------- CSV ----------
$handle  = fopen($csv_file, 'r');
$headers = fgetcsv($handle, 0, ',');
if (!$headers) die("❌ Нет заголовков в CSV\n");

while (($data = fgetcsv($handle, 0, ',')) !== false) {
    $row = array_combine($headers, $data);

    // ---------- СОЗДАНИЕ / ОБНОВЛЕНИЕ ПОСТА ----------
    $post_id = !empty($row['ID']) ? (int)$row['ID'] : 0;
    $post_is_exist  = $post_id ? get_post($post_id) : null;

    // ---------- Подстановка в шаблон ----------
    $post_content = $template;

    // Обрабатываем {{...}}
    $post_content = preg_replace_callback('/{{(.*?)}}/', function($matches) use ($row) {
        $key_or_path = trim($matches[1]);

        // 🔹 Если плейсхолдер с .item. (повторитель/секции)
        if (strpos($key_or_path, '.item.') !== false) {
            // пример: blocks.price.delivery_options.item.title
            // или: blocks.coop_state.sections.item.title
            $parts = explode('.item.', $key_or_path);
            $json_key = $parts[0]; // blocks.price.delivery_options / blocks.coop_state.sections
            $field    = $parts[1]; // title / description / items ...

            if (!isset($row[$json_key])) return '""';
            $items = json_decode($row[$json_key], true);
            if (!$items) return '""';

            $output = [];
            foreach ($items as $i => $item) {
                $val = $item[$field] ?? '';
                $val = html_entity_decode($val, ENT_QUOTES | ENT_HTML5, 'UTF-8');

                // --- определяем формат ключа ---
                if (str_contains($json_key, 'sections')) {
                    // для coop-state
                    $acf_key = "sections_{$i}_section_{$field}";
                } else {
                    // для delivery_options / packaging_options / faq и т.п.
                    $base_key = str_replace('blocks.', '', $json_key); // убираем префикс blocks.
                    $acf_key  = "{$base_key}_{$i}_{$field}";
                }

                $output[] = "\"{$acf_key}\":" . wp_json_encode($val, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            // добавляем служебное поле с количеством элементов
            if (str_contains($json_key, 'sections')) {
                $output[] = "\"sections\":" . count($items);
            } else {
                $base_key = str_replace('blocks.', '', $json_key);
                $output[] = "\"{$base_key}\":" . count($items);
            }

            return implode(",\n", $output);
        }


        // 🔹 Если плейсхолдер — это путь/URL к изображению
        if (preg_match('/\.(png|jpe?g|gif|svg)$/i', $key_or_path)) {
            $id = get_or_upload_attachment_id($key_or_path);
            return $id ? $id : '"' . $key_or_path . '"';
        }

        // 🔹 Если это ключ из CSV
        if (isset($row[$key_or_path])) {
            $value = $row[$key_or_path];

            // Если JSON → вернем как есть
            if ($this_json = json_decode($value, true)) {
                return json_encode($this_json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            // Если это путь к изображению из CSV
            if (preg_match('/\.(png|jpe?g|gif|svg)$/i', $value)) {
                $id = get_or_upload_attachment_id($value);
                return $id ? $id : '"' . $value . '"';
            }

            return wp_json_encode(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return '""';
    }, $post_content);

    // Чистим JSON от висячих запятых
    $post_content = preg_replace('/,(\s*})/', '$1', $post_content);

    // ---------- ЛОГ ----------
    file_put_contents(
        $log_file,
        "----- POST ID: {$post_id} -----\n" . $post_content . "\n\n",
        FILE_APPEND | LOCK_EX
    );

    // ---------- СОХРАНЕНИЕ ----------
    $postarr = [
        'ID'          => $post_is_exist ? $post_id : 0,
        'post_type'   => 'services',
        'post_status' => 'publish',
        'post_title'  => $row['Title'],
        'post_name'   => sanitize_title($row['Slug']),
        'post_content'=> $post_content,
    ];

    if (!$post_is_exist) {
        $post_id = wp_insert_post($postarr);
        update_yoast_wpseo($post_id, $row['_yoast_wpseo_title'] ?? false, $row['_yoast_wpseo_metadesc'] ?? false);
        echo "🆕 Создан новый пост #$post_id ({$row['Title']})\n";
    } else {
        wp_update_post($postarr);
        update_yoast_wpseo($post_id, $row['_yoast_wpseo_title'] ?? false, $row['_yoast_wpseo_metadesc'] ?? false);
        echo "♻️ Обновлён пост #$post_id ({$row['Title']})\n";
    }
}

fclose($handle);

// ---------- ФУНКЦИИ ----------

function update_yoast_wpseo($post_id, $title, $metadesc) {
    if (!empty($title)) {
        update_post_meta($post_id, '_yoast_wpseo_title', $title);
    }
    if (!empty($metadesc)) {
        update_post_meta($post_id, '_yoast_wpseo_metadesc', $metadesc);
    }
}

/**
 * Находит ID медиафайла по пути, при необходимости загружает.
 */
function get_or_upload_attachment_id($file_path) {
    global $wpdb;

    // Нормализуем путь
    if (filter_var($file_path, FILTER_VALIDATE_URL)) {
        // Убираем домен
        $parsed_url = wp_parse_url($file_path, PHP_URL_PATH);
        $relative_path = ltrim(str_replace('/wp-content/uploads/', '', $parsed_url), '/');
    } else {
        // Если относительный путь (/wp-content/uploads/2025/03/file.png)
        $relative_path = ltrim(str_replace('/wp-content/uploads/', '', $file_path), '/');
    }

    // Ищем в БД
    $attachment_id = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s",
            $relative_path
        )
    );

    if ($attachment_id) {
        return (int)$attachment_id;
    }

    // Физический путь
    $full_path = wp_get_upload_dir()['basedir'] . '/' . $relative_path;
    if (!file_exists($full_path)) {
        error_log("⚠️ Файл $file_path ($full_path) не найден на сервере");
        return 0;
    }

    // Загружаем новый
    $filetype = wp_check_filetype(basename($full_path), null);
    $attachment = [
        'guid'           => wp_get_upload_dir()['baseurl'] . '/' . $relative_path,
        'post_mime_type' => $filetype['type'],
        'post_title'     => preg_replace('/\.[^.]+$/', '', basename($full_path)),
        'post_content'   => '',
        'post_status'    => 'inherit',
    ];

    $attach_id = wp_insert_attachment($attachment, $full_path);
    $attach_data = wp_generate_attachment_metadata($attach_id, $full_path);
    wp_update_attachment_metadata($attach_id, $attach_data);

    return (int)$attach_id;
}