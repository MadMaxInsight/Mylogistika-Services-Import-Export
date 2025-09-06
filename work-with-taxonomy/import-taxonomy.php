<?php
define('WP_USE_THEMES', false); // отключить загрузку темы
require_once( dirname(__FILE__, 6) . '/wp-load.php' );

$log_file = __DIR__ . '/taxanomy_import_log.txt';
$CSV_FILE = __DIR__ . '/service-category_import.csv'; // <-- укажите путь к вашему CSV
// $CSV_FILE = __DIR__ . '/category-import-file.csv'; // <-- укажите путь к вашему CSV
// $CSV_FILE = __DIR__ . '/service-category_export_2025-09-06.csv'; // <-- укажите путь к вашему CSV
// $DELIM    = "\t";     // ваш разделитель (например: "\t" или "," или ";")
$DELIM    = ",";     // ваш разделитель (например: "\t" или "," или ";")
$DELETE_EMPTY_META = false; // true — пустые значения из CSV будут удалять мету

if (!file_exists($CSV_FILE)) {
    exit("Файл не найден: {$CSV_FILE}\n");
}

$fh = fopen($CSV_FILE,'r');
if(!$fh) exit("Не удалось открыть CSV\n");

$header = fgetcsv($fh, 0, $DELIM);
if (!$header) exit("CSV пустой\n");
$header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]); // убрать BOM из первого заголовка
$header = array_map('trim', $header);

$core = ['term_id','name','taxonomy'];
$missing = array_diff($core, $header);
if (!empty($missing)) exit('В CSV отсутствуют колонки: ' . implode(', ', $missing) . "\n");

$idx = array_flip($header);
$meta_keys = array_values(array_diff($header, $core, ['_yoast_wpseo_title','_yoast_wpseo_metadesc']));

$rows = [];
while(($r = fgetcsv($fh, 0, $DELIM)) !== false){
    $row = [];
    foreach($header as $k){
        $row[$k] = isset($r[$idx[$k]]) ? (string)$r[$idx[$k]] : '';
    }
    $rows[] = $row;
}
fclose($fh);

$total=0; $created=0; $updated=0; $skipped=0;
$tids = []; // term_id по индексу строки для 2-го прохода (родители)

foreach($rows as $i => $row){
    $total++;
    $tax = $row['taxonomy'];
    // if (!taxonomy_exists($tax)) { $skipped++; $tids[$i]=0; echo "[{$total}] Таксономия не найдена: {$tax}\n"; continue; }
    $has_id = strlen(trim($row['term_id'])) > 0;
    $tid = 0;

    if ($has_id) {
        $tid = (int)$row['term_id'];
        $term = get_term($tid, $tax);
        if (!$term || is_wp_error($term)) {
            $skipped++; $tids[$i]=0; echo "[{$total}] Не найден term_id={$tid} в таксономии {$tax}\n"; continue;
        }
        $args_update = [];
        if ($row['name']        !== '') $args_update['name'] = $row['name'];
        if ($row['slug']        !== '') $args_update['slug'] = sanitize_title($row['slug']);
        if ($row['description'] !== '') $args_update['description'] = $row['description'];

        if (!empty($args_update)) {
            file_put_contents( $log_file,"----- ID: {$tid} -----\n" . print_r($args_update, true) . "\n\n", FILE_APPEND | LOCK_EX ); // ЛОГ
            $res = wp_update_term($tid, $tax, $args_update);
            if (is_wp_error($res)){ $skipped++; $tids[$i]=0; echo "[{$total}] Ошибка обновления term_id={$tid}: ".$res->get_error_message()."\n"; continue; }
        }
        $updated++;
    } else {
        // СОЗДАНИЕ новой записи
        $name = $row['name'] ?: ($row['slug'] ?: 'Без названия');
        $args = [];
        if ($row['slug']        !== '') $args['slug'] = sanitize_title($row['slug']);
        if ($row['description'] !== '') $args['description'] = $row['description'];
        // parent установим вторым проходом
        
        file_put_contents( $log_file,"----- ID: {$tid} -----\n" . print_r($args, true) . "\n\n", FILE_APPEND | LOCK_EX ); // ЛОГ
        $res = wp_insert_term($name, $tax, $args);
        if (is_wp_error($res)){ $skipped++; $tids[$i]=0; echo "[{$total}] Ошибка создания: ".$res->get_error_message()."\n"; continue; }
        $tid = (int)$res['term_id'];
        $created++;
    }

    // term meta: все не-ядровые колонки = ключи метаполей
    foreach($meta_keys as $mk){
        $val = $row[$mk];
        if ( $val==='' ) {
            if ($DELETE_EMPTY_META){
                delete_term_meta($tid, $mk);
            }
        }else {
            file_put_contents( $log_file, print_r($args_update, true) . "\n\n", FILE_APPEND | LOCK_EX ); // ЛОГ
            update_term_meta($tid, $mk, $val);
        }
    }

    // --- Yoast SEO (для термов) ---
    update_yoast_tax_meta(
        $tax,
        $tid,
        $row['_yoast_wpseo_title']     ?? false,
        $row['_yoast_wpseo_metadesc']  ?? false,
        $DELETE_EMPTY_META
    );

    $tids[$i] = $tid;
    echo "[{$total}] OK term_id={$tid} taxonomy={$tax}\n";
}

// второй проход — установка родителей (после того как все термы созданы/обновлены)
$parents_set=0;
foreach($rows as $i => $row){
    $tid = (int)($tids[$i] ?? 0);
    if ($tid <= 0) continue;

    $tax = $row['taxonomy'];
    $pid = resolve_parent_id($tax, $row['parent_id'], $row['parent_slug']);
    if ($pid > 0) {
        $term = get_term($tid, $tax);
        if ($term && !is_wp_error($term) && (int)$term->parent !== $pid){
            $res = wp_update_term($tid, $tax, ['parent'=>$pid]);
            if (!is_wp_error($res)){ $parents_set++; echo "[P] parent set: {$tid} -> {$pid}\n"; }
        }
    }
}

echo "\nГотово.\nВсего строк: {$total}\nСоздано: {$created}\nОбновлено: {$updated}\nРодителей установлено: {$parents_set}\nПропущено: {$skipped}\n";



function resolve_parent_id($taxonomy, $parent_id, $parent_slug){
    if (!empty($parent_slug)) {
        $pt = get_term_by('slug', sanitize_title($parent_slug), $taxonomy);
        if ($pt && !is_wp_error($pt)) return (int)$pt->term_id;
    }
    if (is_numeric($parent_id) && (int)$parent_id > 0) {
        $pt = get_term((int)$parent_id, $taxonomy);
        if ($pt && !is_wp_error($pt)) return (int)$pt->term_id;
    }
    return 0;
}

function update_yoast_tax_meta($taxonomy, $term_id, $title, $desc, $delete_empty = false){
    $term_id = (int)$term_id;
    $opt = get_option('wpseo_taxonomy_meta');
    if (!is_array($opt)) $opt = [];
    if (!isset($opt[$taxonomy]) || !is_array($opt[$taxonomy])) $opt[$taxonomy] = [];
    if (!isset($opt[$taxonomy][$term_id]) || !is_array($opt[$taxonomy][$term_id])) $opt[$taxonomy][$term_id] = [];

    $changed = false;

    // title
    if ($title !== false) {
        if ($title !== '') {
            $opt[$taxonomy][$term_id]['wpseo_title'] = $title;
            $changed = true;
        } elseif ($delete_empty && isset($opt[$taxonomy][$term_id]['wpseo_title'])) {
            unset($opt[$taxonomy][$term_id]['wpseo_title']);
            $changed = true;
        }
    }

    // description
    if ($desc !== false) {
        if ($desc !== '') {
            $opt[$taxonomy][$term_id]['wpseo_desc'] = $desc;
            $changed = true;
        } elseif ($delete_empty && isset($opt[$taxonomy][$term_id]['wpseo_desc'])) {
            unset($opt[$taxonomy][$term_id]['wpseo_desc']);
            $changed = true;
        }
    }

    // подчистка пустых узлов
    if (isset($opt[$taxonomy][$term_id]) && empty($opt[$taxonomy][$term_id])) {
        unset($opt[$taxonomy][$term_id]);
        $changed = true;
    }
    if (isset($opt[$taxonomy]) && empty($opt[$taxonomy])) {
        unset($opt[$taxonomy]);
        $changed = true;
    }

    if ($changed) update_option('wpseo_taxonomy_meta', $opt);
}