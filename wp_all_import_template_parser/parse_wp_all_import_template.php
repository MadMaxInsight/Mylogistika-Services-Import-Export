<?php
// читаем содержимое файла
$json = file_get_contents(__DIR__ . '/templates.txt');

// декодируем JSON в массив
$data = json_decode($json, true);

if (!is_array($data)) {
    die("Ошибка: не удалось декодировать JSON\n");
}

// берём сериализованную строку из поля "options"
$serialized = $data[0]['options'] ?? null;

if (!$serialized) {
    die("Ошибка: не найдено поле 'options'\n");
}

// преобразуем в PHP-массив
$array = unserialize($serialized);

if ($array === false) {
    die("Ошибка: не удалось unserialize\n");
}

// выводим для проверки
print_r($array);
// сохраняем print_r в result.txt
file_put_contents(__DIR__ . '/result.txt', print_r($array, true));
// // или только ключи верхнего уровня:
// echo "\nКлючи верхнего уровня:\n";
// echo implode("\n", array_keys($array));