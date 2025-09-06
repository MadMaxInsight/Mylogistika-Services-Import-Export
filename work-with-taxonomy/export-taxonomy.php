<?
define('WP_USE_THEMES', false); // отключить загрузку темы
require_once( dirname(__FILE__, 6) . '/wp-load.php' );

// НАСТРОЙКИ:
$TAXONOMY = 'service-category'; // слаг таксономии
$DELIM    = "\t";            // или '|'
$ADD_BOM  = true;            // для Excel на Windows


// ------- ЛОГИКА -------
$DELIM = substr((string)$DELIM, 0, 1);

// Берём все термины таксономии
$terms = get_terms([
    'taxonomy'   => $TAXONOMY,
    'hide_empty' => false,
    'number'     => 0,
]);

/* $terms = Array(
[0] => WP_Term Object (
    [term_id] => 868
    [name] => Выкуп товаров
    [slug] => vykup-tovarov
    [term_group] => 0
    [term_taxonomy_id] => 868
    [taxonomy] => service-category
    [description] => 
    [parent] => 0
    [count] => 0
    [filter] => raw )
...
[N] => WP_Term Object () */

if (is_wp_error($terms)) exit(1);

// Yoast (для SEO-колонок)
$yoast_opt = get_option('wpseo_taxonomy_meta');
if (!is_array($yoast_opt)) $yoast_opt = [];


// Собираем уникальные ACF-ключи
$array_acf_keys = [];
$array_terms_with_fields = [];
if (function_exists('get_fields')) {
    foreach ($terms as $t) {

        // получаем плоский массив с правильнцыми ключами для дочерних ACF-полей, например для repeater или sections
        $array_one_term_fields = acf_flatten(get_fields('term_' . $t->term_id));
        // накапливаем в массиве $array_acf_keys все встречающиеся мета-поля 
        $array_acf_keys = array_values(
            array_unique(
                array_merge($array_acf_keys, array_keys($array_one_term_fields)),
            )
        );
        echo '<pre>';
        print_r($array_one_term_fields);
        echo '</pre>';
        die();
        $terms_fields[$t->term_id] = $array_one_term_fields; 
    }
}


// Имя файла в директории скрипта
$result_csv_file = __DIR__ . '/' . sprintf('%s_export_%s.csv', $TAXONOMY, date('Y-m-d'));
$fh = fopen($result_csv_file, 'w');
if (!$fh) exit(1);
if ($ADD_BOM) { fwrite($fh, "\xEF\xBB\xBF"); } // UTF-8 BOM


/* Формируем заголовки для шапки таблицы и записываем в CSV-файл  */
// Заголовок CSV (первая строка)
sort($array_acf_keys);

$headers = array_merge(
    ['term_id','name','slug','_yoast_wpseo_title','_yoast_wpseo_metadesc','taxonomy','parent_id','parent_slug','description'],
    $array_acf_keys
);
fputcsv($fh, $headers, $DELIM, '"');

/* Формируем значения для строк данных и построчно записываем в CSV-файл  */
foreach ($terms as $t) {
    $parent_id   = (int)$t->parent ?? '';
    $parent_slug = '';
    if ($parent_id) {
        $p = get_term($parent_id, $TAXONOMY);
        if ($p && !is_wp_error($p)) $parent_slug = $p->slug;
    }


    $row = [
        $t->term_id,
        $t->name,
        $t->slug,
        $yoast_opt[$TAXONOMY][$t->term_id]['wpseo_title'] ?? '',
        $yoast_opt[$TAXONOMY][$t->term_id]['wpseo_desc'] ?? '',
        $t->taxonomy,
        $parent_id,
        $parent_slug,
        preg_replace('/\s+/', ' ', (string)$t->description),
    ];

    // добавляем только значения для ACF-ключей, в порядке $array_acf_keys
    foreach ($array_acf_keys as $col) {
        $row[] = $terms_fields[$t->term_id][$col] ?? '';
    }
    
    fputcsv($fh, $row, $DELIM);
}

fclose($fh);



/* Функция делюащая из вложенного ассоциативного массива плоский ассоциативный массив */
function acf_flatten(array $data): array {
    $out = [];

    $walk = function ($value, string $prefix) use (&$out, &$walk) {
        if (!is_array($value)) {
            $out[$prefix] = (string)($value ?? '');
            return;
        }

        // если это группа с картинкой
        if (array_key_exists('filename', $value) && isset($value['ID'])) {
            $out[$prefix] = (string)$value['ID'];
            return;
        }

        $is_assoc = array_keys($value) !== range(0, count($value) - 1);

        // фиксируем количество элементов на уровне parent
        $out[$prefix] = (string)count($value);

        if ($is_assoc) {
            foreach ($value as $k => $v) {
                $walk($v, $prefix . '_' . $k);
            }
        } else {
            $i = 1;
            foreach ($value as $v) {
                $walk($v, $prefix . '_' . $i);
                $i++;
            }
        }
    };

    foreach ($data as $k => $v) {
        $walk($v, (string)$k);
    }

    return $out;
}