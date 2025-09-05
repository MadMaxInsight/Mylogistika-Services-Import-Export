# Mylogistika Services Import/Export

Модуль для автоматизации импорта/экспорта страниц услуг (post-type == `services`) в WordPress на основе CSV и шаблона Gutenberg-блоков.

## 📂 Структура модуля

- `content_template.txt` — шаблон с Gutenberg-блоками (ACF Blocks), в котором указываются плейсхолдеры `{{...}}`.
- `import_posts.php` — основной скрипт импорта: читает `services_import.csv`, подставляет данные в шаблон и обновляет/создаёт посты.
- `export_posts.php` — экспорт существующих постов в CSV.
- `services_import.csv` — CSV-файл для импорта (данные постов).
- `services_export.csv` — CSV-файл, полученный при экспорте.
- `services-delete.php` — удаление всех постов типа `services`.
- `service-category-delete.php` — удаление таксономий/категорий услуг.
- `import_log.txt` — лог сгенерированного контента (каждый запуск сохраняется).

---

## 📝 Работа с `content_template.txt`

1. Файл содержит полный JSON-контент блоков (как в БД WP).  
2. Комментарии можно писать строками, начинающимися с `#`.  
3. **Динамические данные** подставляются через двойные фигурные скобки:
   ```json
   "heading":{{blocks.docs.heading}} → заменяется на значение из CSV (blocks.docs.heading).
4. **Повторители (Repeater ACF)** (например `delivery_options`, `faq`, `packaging_options`) в CSV передаются как JSON-массив:

   ```csv
   [{"title":"Авиадоставка","description":"Быстрая доставка...","price":"от $15/кг"}]
   ```

   В шаблоне указывается только один элемент с индексом `0` и плейсхолдерами:

   ```json
   "delivery_options_0_title":{{blocks.price.delivery_options.item.title}},
   "delivery_options_0_description":{{blocks.price.delivery_options.item.description}},
   "delivery_options_0_price":{{blocks.price.delivery_options.item.price}}
   ```

   Скрипт сам размножит блоки по количеству элементов в JSON.

5. **Служебные поля ACF**

   В шаблоне **можно смело исключать** большинство технических полей, которые ACF генерирует автоматически:  
   - все ключи, начинающиеся с `_` (например `_heading`, `_badge`, `_title`);  
   - параметр `"mode": "edit"` или `"mode": "preview"`.  

   ⚠️ Исключение: у **Repeater ACF** обязательно должны оставаться:  
   - поле с количеством элементов (`repeater_field_name: N`);  
   - системное поле `_repeater_field_name` с `field_XXXXXX`.  

   ❗Без этих двух значений **ACF не сможет правильно восстановить repeater**, и вызов `get_field('repeater_field_name')` вернёт `null`.

---

## 🖼 Работа с изображениями и файлами

Поддерживаются разные варианты:

1. **ID медиафайла**:

   ```json
   "document_images_0_document_image":542
   ```

2. **Путь в `uploads` (относительный)**:

   ```json
   "document_images_0_document_image":{{/wp-content/uploads/2025/03/doc-2.png}}
   ```

3. **Полный URL**:

   ```json
   "document_images_0_document_image":{{https://example.com/wp-content/uploads/2025/03/doc-1.png}}
   ```

4. **Плейсхолдер из CSV**:

   ```json
   "document_images_0_document_image":{{blocks.docs.image_from_csv}}
   ```

🔑 В результате в `post_content` всегда сохраняется **ID файла**.
Если путь/URL указывает на уже загруженный файл → берётся его ID.
Если файла ещё нет в медиатеке → скрипт загружает его и создаёт новый ID.

---

## ⚙️ Импорт (`import_posts.php`)

* Читает `services_import.csv`.
* Подставляет значения в `content_template.txt`.
* Создаёт новые посты или обновляет существующие (`services`).
* Обновляет SEO-поля Yoast (`_yoast_wpseo_title`, `_yoast_wpseo_metadesc`).
* Все собранные блоки пишутся в `import_log.txt`.

---

## 🔄 Экспорт (`export_posts.php`)

Экспортирует все посты CPT `services` в `services_export.csv` вместе с данными блоков.

---

## 🗑 Удаление

* `services-delete.php` — удаляет все посты CPT `services`.
* `service-category-delete.php` — очищает таксономии (категории услуг).

---

## 🚀 Как пользоваться

1. Настроить `content_template.txt` (оставить нужные блоки, заменить текст на `{{...}}`).
2. Подготовить `services_import.csv`.
3. Запустить:

   ```bash
   php import_posts.php
   ```
4. Проверить `import_log.txt` и админку WP.

---

## ✅ Примеры плейсхолдеров

### Текст

```json
"heading":{{blocks.docs.heading}}
```

### HTML-списки

```json
"documents_list":{{blocks.docs.documents_list}}
```

### Повторитель

```json
"delivery_options_0_title":{{blocks.price.delivery_options.item.title}}
```

### Изображение

```json
"document_images_0_document_image":{{/wp-content/uploads/2025/03/doc-2.png}}
```
