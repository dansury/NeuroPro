# NeuroPro — веб‑сервис интерпретации тестов «Эгоскоп»

Загрузка Excel‑выгрузки + скриншот таблицы значимости → математическая
диаграмма‑паутинка (когниция ⊕ физиология) + интерпретация нейросетью →
брендированный PDF (Verdana) / письмо клиенту.

## Запуск

```bash
php -S 127.0.0.1:8080 -t app/public
# UI:        http://127.0.0.1:8080/index.php
# Настройки: http://127.0.0.1:8080/setup.php   (пароль = ADMIN_PASSWORD)

# Слежение за папкой (auto-ingest .xls):
php app/bin/watch.php 5
```

## Конфигурация

Секреты — через ENV (см. `app/.env.example`) или `setup.php`:
`OPENROUTER_API_KEY`, `YANDEX_API_KEY`, `YANDEX_FOLDER_ID`, SMTP‑параметры,
`ADMIN_PASSWORD`, `WATCH_DIR`, `BRAND_*`.

## Требования

PHP 8.4+ с расширениями: curl, gd, pdo_sqlite, sqlite3, zip, dom, mbstring.
Composer не требуется.

## Структура

См. `../CLAUDE.md` (раздел «Архитектура») и `../specs/001-neuropro-service/`.
