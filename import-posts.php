<?php
/**
 * Импорт страниц услуг из CSV в ACF Gutenberg блоки (content_template.txt + placeholders + авто-загрузка картинок)
 */
define('WP_USE_THEMES', false); // отключить загрузку темы
require_once( dirname(__FILE__, 5) . '/wp-load.php' );
require_once ABSPATH . 'wp-admin/includes/post.php'; // для get_post()
require_once ABSPATH . 'wp-admin/includes/image.php'; // для wp_generate_attachment_metadata

// Отключаем автофильтры WP для контента (чтобы не ломал кавычки и HTML в блоках)
remove_all_filters('content_save_pre');
remove_all_filters('content_filtered_save_pre');

$log_file      = __DIR__ . '/import_log.txt';
$csv_file      = __DIR__ . "/service-post_import.csv";
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
    $post_content = preg_replace_callback('/{{(.*?)}}/', function($matches) use ($row, $template) {
        $placeholder = trim($matches[1]);

        // 1) Путь/URL к изображению, указанный прямо в шаблоне
        if (preg_match('/\.(png|jpe?g|gif|svg)$/i', $placeholder)) {
            $id = get_or_upload_attachment_id($placeholder);
            return $id ? $id : '"' . $placeholder . '"';
        }

        // 2) Повторители/секции: {{blocks.xxx.yyy.item.field}}
        if (strpos($placeholder, '.item.') !== false) {
            // Пример: blocks.price.delivery_options.item.title
            //         blocks.coop_state.sections.item.title
            [$csv_header, $field] = explode('.item.', $placeholder, 2);


            // Достаём JSON из CSV
            if (!isset($row[$csv_header])) return '""';
            $items = json_decode($row[$csv_header], true);
            if (!is_array($items) || !$items) return '""';

            $is_sections = str_contains($csv_header, 'sections'); // есть заголовок напрмиер blocks.coop_state.sections

            // Базовое имя для ACF-ключей:
            // - для секций: sections_%d_section_<field>
            // - для обычных повторителей (delivery_options и пр.): <basename>_%d_<field>
            if ($is_sections) {
                $acf_key_fmt       = 'sections_%d_section_' . $field;
                $acf_underscore_fmt= '_sections_%d_section_' . $field;
                $acf_zero_key      = sprintf($acf_key_fmt, 0);            // sections_0_section_title
                $acf_zero_us_key   = sprintf($acf_underscore_fmt, 0);     // _sections_0_section_title
            } else {
                // basename = последний сегмент перед .item (напр., delivery_options)
                $parts = explode('.', $csv_header);         // ['blocks','price','delivery_options']
                $basename = end($parts);                  // 'delivery_options'
                $acf_key_fmt        = $basename . '_%d_' . $field;      // delivery_options_%d_title
                $acf_underscore_fmt = '_' . $basename . '_%d_' . $field;// _delivery_options_%d_title
                $acf_zero_key       = sprintf($acf_key_fmt, 0);
                $acf_zero_us_key    = sprintf($acf_underscore_fmt, 0);
            }

            // Поищем в шаблоне field-key для подчёркнутого ключа индекса 0,
            // чтобы размножить его на 1..N (если он вообще есть в шаблоне)
            $field_key_val = null;
            $pattern = '/"' . preg_quote($acf_zero_us_key, '/') . '"\s*:\s*"([^"]+)"/u';
            if (preg_match($pattern, $template, $m)) {
                $field_key_val = $m[1]; // например "field_cooperation_section_title"
            }

            // Формируем подстановку:
            // - для i=0: возвращаем только значение (без ключа)
            // - для i>=1: добавляем , "ключ_i":значение_i
            //   + если нашёлся подчёркнутый ключ для индекса 0 — клонируем его тоже на i>=1
            $out = '';
            $counter = 0;
            $is_counter_printed = false;
            foreach ($items as $i => $item) {
                $val = $item[$field] ?? '';
                $val = html_entity_decode($val, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $encoded = wp_json_encode($val, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                if ($i === 0) {
                    // В шаблоне уже есть "ключ_0": {{...}} — подставляем только значение
                    $out .= $encoded;
                } else {
                    $acf_key_i = sprintf($acf_key_fmt, $i);
                    $out .= ",\n" . "\"{$acf_key_i}\":" . $encoded;

                    // Клонируем служебный подчёркнутый ключ (если он был в шаблоне для i=0)
                    if ($field_key_val !== null) {
                        $acf_us_key_i = sprintf($acf_underscore_fmt, $i);
                        $out .= ",\n" . "\"{$acf_us_key_i}\":\"{$field_key_val}\"";
                    }
                }
                $counter++;
            }

            if ($basename && !$is_counter_printed) {
                $out .= ",\n" . "\"{$basename}\":\"{$counter}\"";
                $is_counter_printed = true;
            }
            // Количество элементов (sections / delivery_options и т.п.) тут НЕ добавляем,
            // чтобы не дублировать — держите это поле в шаблоне вручную.
            return $out !== '' ? $out : '""';
        }

        // 3) Обычные плейсхолдеры — ключ из CSV
        if (isset($row[$placeholder])) {
            $value = $row[$placeholder];

            // Если JSON в CSV — возвращаем JSON как есть
            $decoded_json = json_decode($value, true);
            if (is_array($decoded_json)) {
                return json_encode($decoded_json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            // Если это путь к изображению — заменяем на ID вложения
            if (preg_match('/\.(png|jpe?g|gif|svg)$/i', $value)) {
                $id = get_or_upload_attachment_id($value);
                return $id ? $id : '"' . $value . '"';
            }

            // Строки/числа — безопасно кодируем (HTML-теги сохраняем)
            $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            return wp_json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        // 4) Ничего не нашли
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

    // die();

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

    // Привязка к категориям по колонке "Категории услуг"
    if (!empty($row['Категории услуг'])) {
        $taxonomy = 'service-category'; // таксономия
        $cat_path = array_map('trim', explode('>', $row['Категории услуг']));

        // $parent_id = 0;
        $assigned_ids = [];

        foreach ($cat_path as $cat_name) {
            if (!$cat_name) continue;

            // Проверяем, есть ли категория
            $term = term_exists($cat_name, $taxonomy);
            if ($term === 0 || $term === null) continue;
            
            $assigned_ids[] = (int)$term['term_id'];
            // $parent_id = $term_id; // следующий уровень будет дочерним к этому
        }

        if ($assigned_ids) {
            // Привязываем все категории
            wp_set_object_terms($post_id, $assigned_ids, $taxonomy);

            // Основной категорией делаем последнюю
            // if (function_exists('update_post_meta')) {
            //     update_post_meta($post_id, '_yoast_wpseo_primary_' . $taxonomy, end($assigned_ids));
            // }
        }
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