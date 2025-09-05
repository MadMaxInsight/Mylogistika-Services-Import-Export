<?php
/**
 * Импорт страниц услуг из CSV в ACF Gutenberg блоки (serialize_blocks)
 */

require_once( dirname(__FILE__, 5) . '/wp-load.php' );
require_once ABSPATH . 'wp-admin/includes/post.php'; // для get_post()

$log_file = __DIR__ . '/import_log.txt';
$csv_file = __DIR__ . "/services_import.csv";
if (!file_exists($csv_file)) die("❌ Нет файла $csv_file\n");

$handle  = fopen($csv_file, 'r');
$headers = fgetcsv($handle, 0, ',');
if (!$headers) die("❌ Нет заголовков в CSV\n");

while (($data = fgetcsv($handle, 0, ',')) !== false) {
    $row = array_combine($headers, $data);

    // ---------- СОЗДАНИЕ / ОБНОВЛЕНИЕ ПОСТА ----------
    $post_id = !empty($row['ID']) ? (int)$row['ID'] : 0;
    $post_is_exist  = $post_id ? get_post($post_id) : null;


    // ---------- СБОРКА ----------
    $post_content = '';
    $post_content .= acf_block_html('calculator', []);
    $post_content .= acf_block_html('package', get_template_package());
    $post_content .= acf_block_html('price', build_price_data($row));
    $post_content .= acf_block_html('docs-text', get_template_docs_text());
    $post_content .= acf_block_html('what-in-price', build_what_in_price_data($row));
    $post_content .= acf_block_html('coop-state', build_coop_state_data($row));
    $post_content .= acf_block_html('routes', build_routes_data($row));

    $faq_data = build_faq_data($row);
    if ($faq_data) {
        $post_content .= acf_block_html('faq', $faq_data);
    }

    $post_content .= acf_block_html('documents', get_template_documents());
    $post_content .= acf_block_html('simple-image', get_template_simple_image());
    $post_content .= acf_block_html('end-block', build_end_block_data($row));

    // логируем ID и сгенерированный контент
    file_put_contents(
        $log_file,
        "----- POST ID: {$post_id} -----\n" . $post_content . "\n\n",
        FILE_APPEND | LOCK_EX
    );

    $postarr = [
        'ID'          => $post_is_exist ? $post_id : 0,
        'post_type'   => 'services',
        'post_status' => 'publish',
        'post_title'  => $row['Title'],
        'post_name'   => sanitize_title($row['Slug']),
        'post_content' => $post_content,
    ];

    // обновляем или создаём
    if (!$post_is_exist) {
        $post_id = wp_insert_post($postarr);
        update_yoast_wpseo($post_id, $row['_yoast_wpseo_title'] ?? false, $row['_yoast_wpseo_metadesc'] ?? false);
        echo "🆕 Создан новый пост #$post_id ({$row['Title']})\n";
    }else {
        wp_update_post($postarr);
        update_yoast_wpseo($post_id, $row['_yoast_wpseo_title'] ?? false, $row['_yoast_wpseo_metadesc'] ?? false);
        echo "♻️ Обновлён пост #$post_id ({$row['Title']})\n";
    }


}

fclose($handle);


/// ---------- ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ ----------


function update_yoast_wpseo($post_id, $title, $metadesc) {
    if (!empty($title)) {
        update_post_meta($post_id, '_yoast_wpseo_title', $title);
    }
    if (!empty($metadesc)) {
        update_post_meta($post_id, '_yoast_wpseo_metadesc', $metadesc);
    }

}

function acf_block_html($name, $data) {
    $safe_data = [];
    foreach ($data as $key => $value) {
        // Если значение похоже на HTML (<ul>, <p>, ...)
        if (is_string($value) && preg_match('/<[^>]+>/', $value)) {
            // оборачиваем в RAW-JSON (оставляем как есть)
            $safe_data[$key] = $value;
        } else {
            $safe_data[$key] = $value;
        }
    }

    // Декодируем JSON, потом возвращаем HTML
    $json = wp_json_encode([
        'name' => "acf/$name",
        'data' => $safe_data,
        'mode' => 'preview',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return "<!-- wp:acf/$name $json /-->";
}


// ---------------- Шаблоны (фиксированные ID) ----------------

function get_template_package() {
    return [
        "badge"   => "Упаковка",
        "_badge"  => "field_packaging_badge",
        "heading" => "Варианты упаковки",
        "_heading"=> "field_packaging_heading",

        "packaging_options_0_image" => 534,
        "_packaging_options_0_image"=> "field_packaging_option_image",
        "packaging_options_0_title" => "Стретч-пленка",
        "_packaging_options_0_title"=> "field_packaging_option_title",
        "packaging_options_0_description" => "Обеспечивает надежную фиксацию грузов и защиту от влаги и повреждений.",
        "_packaging_options_0_description"=> "field_packaging_option_description",

        "packaging_options_1_image" => 535,
        "_packaging_options_1_image"=> "field_packaging_option_image",
        "packaging_options_1_title" => "Мешки из полиэтилена",
        "_packaging_options_1_title"=> "field_packaging_option_title",
        "packaging_options_1_description" => "Идеально подходят для упаковки мягких и объемных товаров, легко утилизируются.",
        "_packaging_options_1_description"=> "field_packaging_option_description",

        "packaging_options_2_image" => 532,
        "_packaging_options_2_image"=> "field_packaging_option_image",
        "packaging_options_2_title" => "Картонные коробки",
        "_packaging_options_2_title"=> "field_packaging_option_title",
        "packaging_options_2_description" => "Удобны для транспортировки различных товаров, обеспечивают защиту от ударов.",
        "_packaging_options_2_description"=> "field_packaging_option_description",

        "packaging_options_3_image" => 533,
        "_packaging_options_3_image"=> "field_packaging_option_image",
        "packaging_options_3_title" => "Полиэтиленовые пакеты",
        "_packaging_options_3_title"=> "field_packaging_option_title",
        "packaging_options_3_description" => "Гибкая и легкая упаковка, подходит для небольших и хрупких грузов.",
        "_packaging_options_3_description"=> "field_packaging_option_description",

        "packaging_options" => 4,
        "_packaging_options"=> "field_packaging_options",
    ];
}

function get_template_docs_text() {
    return [
        "heading" => "Документы для перевозки",
        "_heading" => "field_68489b44ecdfe",
        "documents_list" => clean_html("<ul><li>Инвойс</li><li>Упаковочный лист</li><li>Договор перевозки</li><li>Сертификаты</li></ul>"),
        "_documents_list" => "field_68489b82ecdff",

        "document_images_0_document_image" => 542,
        "_document_images_0_document_image"=> "field_68489bd082b80",
        "document_images_0_document_alt"   => "Документ на перевозку",
        "_document_images_0_document_alt"  => "field_68489c0a82b81",

        "document_images_1_document_image" => 539,
        "_document_images_1_document_image"=> "field_68489bd082b80",
        "document_images_1_document_alt"   => "Документ на перевозку",
        "_document_images_1_document_alt"  => "field_68489c0a82b81",

        "document_images_2_document_image" => 541,
        "_document_images_2_document_image"=> "field_68489bd082b80",
        "document_images_2_document_alt"   => "Документ на перевозку",
        "_document_images_2_document_alt"  => "field_68489c0a82b81",

        "document_images_3_document_image" => 542,
        "_document_images_3_document_image"=> "field_68489bd082b80",
        "document_images_3_document_alt"   => "Документ на перевозку",
        "_document_images_3_document_alt"  => "field_68489c0a82b81",

        "document_images" => 4,
        "_document_images"=> "field_68489bb482b7f",
    ];
}


function get_template_documents() {
    return [
        "title" => "Разрешающие документы",
        "_title"=> "field_67e058f0172e6",

        "documents_0_document_name" => "Лицензия на транспортные перевозки",
        "_documents_0_document_name"=> "field_67e05a3395e5c",
        "documents_0_document"      => 476,
        "_documents_0_document"     => "field_67e0597ec84ba",

        "documents_1_document_name" => "Свидетельство о регистрации компании",
        "_documents_1_document_name"=> "field_67e05a3395e5c",
        "documents_1_document"      => 477,
        "_documents_1_document"     => "field_67e0597ec84ba",

        "documents_2_document_name" => "Разрешение на таможенную деятельность",
        "_documents_2_document_name"=> "field_67e05a3395e5c",
        "documents_2_document"      => 475,
        "_documents_2_document"     => "field_67e0597ec84ba",

        "documents" => 3,
        "_documents"=> "field_67e0596cc84b9",
    ];
}


function get_template_simple_image() {
    return [
        "image" => 543,
        "_image"=> "field_simple_image",
        "image_alt" => "350",
        "_image_alt"=> "field_simple_image_height",
    ];
}

// ---------------- Генераторы (из CSV) ----------------
function clean_html($value) {
    if (!$value) return $value;
    // сначала пробуем как JSON-декод
    $decoded = json_decode('"' . $value . '"');
    if ($decoded !== null) {
        return $decoded;
    }
    // иначе просто заменяем \u003c -> <
    return str_replace(
        ['\u003c','\u003e','\u0026'],
        ['<','>','&'],
        $value
    );
}

function build_price_data($row) {
    $items = json_decode($row['blocks.price.delivery_options'], true) ?: [];
    $data = [
        "badge"   => $row['blocks.price.badge'],
        "_badge"  => "field_68489990c2a43",
        "heading" => $row['blocks.price.heading'],
        "_heading"=> "field_684899b1c2a44",
    ];
    foreach ($items as $i => $item) {
        $data["delivery_options_{$i}_title"]       = $item['title'];
        $data["_delivery_options_{$i}_title"]      = "field_684899e4c2a46";
        $data["delivery_options_{$i}_description"] = clean_html($item['description']);
        $data["_delivery_options_{$i}_description"]= "field_684899f4c2a47";
        $data["delivery_options_{$i}_price"]       = $item['price'];
        $data["_delivery_options_{$i}_price"]      = "field_68489a00c2a48";
    }
    $data["delivery_options"]  = count($items);
    $data["_delivery_options"] = "field_684899c2c2a45";
    return $data;
}

function build_what_in_price_data($row) {
    return [
        "heading"       => $row['blocks.what_in_price.what_in_price.heading'],
        "_heading"      => "field_pricing_heading",
        "description"   => clean_html($row['blocks.what_in_price.what_in_price.description']),
        "_description"  => "field_pricing_description",
        "list_title"    => $row['blocks.what_in_price.what_in_price.list_title'],
        "_list_title"   => "field_pricing_list_title",
        "services_list" => clean_html($row['blocks.what_in_price.what_in_price.services_list']),
        "_services_list"=> "field_pricing_services_list",
    ];
}

function build_coop_state_data($row) {
    $sections = json_decode($row['blocks.coop_state.sections'], true) ?: [];
    $data = [
        "heading"  => $row['blocks.coop_state.heading'],
        "_heading" => "field_cooperation_heading",
    ];
    foreach ($sections as $i => $s) {
        $data["sections_{$i}_section_title"] = $s['title'];
        $data["_sections_{$i}_section_title"]= "field_cooperation_section_title";
        $data["sections_{$i}_section_items"] = clean_html($s['items']);
        $data["_sections_{$i}_section_items"]= "field_cooperation_section_items";
    }
    $data["sections"] = count($sections);
    $data["_sections"]= "field_cooperation_sections";
    return $data;
}

function build_routes_data($row) {
    $routes = json_decode($row['blocks.routes.routes'], true) ?: [];
    $data = [
        "badge"     => $row['blocks.routes.badge'],
        "_badge"    => "field_routes_badge",
        "heading"   => $row['blocks.routes.heading'],
        "_heading"  => "field_routes_heading",
        "map_image" => 831,
        "_map_image"=> "field_routes_map_image",
    ];

    foreach ($routes as $r) {
        $mode = mb_strtolower(trim($r['mode']));

        if ($mode === 'авиа') {
            $data["air_route_title"]        = $r['title'];
            $data["_air_route_title"]       = "field_routes_air_title";
            $data["air_route_description"]  = clean_html($r['description']);
            $data["_air_route_description"] = "field_routes_air_description";
        }

        if ($mode === 'ж/д' || $mode === 'жд' || $mode === 'железнодорожный') {
            $data["rail_route_title"]        = $r['title'];
            $data["_rail_route_title"]       = "field_routes_rail_title";
            $data["rail_route_description"]  = clean_html($r['description']);
            $data["_rail_route_description"] = "field_routes_rail_description";
        }

        if ($mode === 'море' || $mode === 'морской') {
            $data["sea_route_title"]        = $r['title'];
            $data["_sea_route_title"]       = "field_routes_sea_title";
            $data["sea_route_description"]  = clean_html($r['description']);
            $data["_sea_route_description"] = "field_routes_sea_description";
        }

        if ($mode === 'авто' || $mode === 'автомобильный') {
            $data["auto_route_title"]        = $r['title'];
            $data["_auto_route_title"]       = "field_routes_auto_title";
            $data["auto_route_description"]  = clean_html($r['description']);
            $data["_auto_route_description"] = "field_routes_auto_description";
        }
    }

    return $data;
}


function build_faq_data($row) {
    $items = json_decode($row['blocks.faq.faq'], true) ?: [];
    if (!$items) return null;
    $data = [];
    foreach ($items as $i => $f) {
        $data["faq_{$i}_question"] = $f['question'];
        $data["_faq_{$i}_question"]= "field_680608682e5d1";
        $data["faq_{$i}_answer"]   = clean_html($f['answer']);
        $data["_faq_{$i}_answer"]  = "field_6806087b2e5d2";
    }
    $data["faq"]  = count($items);
    $data["_faq"] = "field_680608882e5d3";
    return $data;
}

function build_end_block_data($row) {
    return [
        "badge"          => $row['blocks.end_block.badge'],
        "_badge"         => "field_order_delivery_badge",
        "heading"        => $row['blocks.end_block.heading'],
        "_heading"       => "field_order_delivery_heading",
        "description"    => clean_html($row['blocks.end_block.description']),
        "_description"   => "field_order_delivery_description",
        "list_heading"   => $row['blocks.end_block.list_heading'],
        "_list_heading"  => "field_order_delivery_list_heading",
        "challenges_list"=> clean_html($row['blocks.end_block.challenges_list']),
        "_challenges_list"=> "field_order_delivery_challenges_list",
    ];
}