# Implementation Plan: NeuroPro

**Spec**: `spec.md` · **Constitution**: `../../.specify/memory/constitution.md`

## Technical Context

- **Язык/рантайм**: PHP 8.4 (CLI + встроенный сервер / любой PHP‑хостинг).
- **Расширения**: curl, gd, pdo_sqlite, sqlite3, zip, dom, mbstring (все есть).
- **Хранилище**: SQLite (один файл, общий с настройками).
- **Внешние сервисы**: OpenRouter / Yandex Foundation Models (интерпретация),
  Yandex Vision OCR (скриншот), SMTP (письма). Всё — опционально и за ключами.
- **Без Composer.** Переиспользуем библиотеку `4neuropro` (перенесена в `app/lib`).

## Constitution Check

| Принцип | Как соблюдается |
| --- | --- |
| I. Диаграмма = математика | `Chart::svg()` — чистая геометрия SVG; нейросеть не участвует |
| II. Достоверность | `Excel`/`Phys` берут реальные числа; пустое → `null`/прочерк; промпты требуют честности |
| III. Чистый PHP | Свой OLE2+BIFF8 ридер вместо PhpSpreadsheet; SVG вместо тяжёлых граф‑либ |
| IV. Секреты вне кода | `config.php` overlay из `settings`; `app/data` gitignored |
| V. Версионирование | Таблицы `prompts`/`prompt_versions`/`interpretations`; правила в `Prompts` |

## Architecture / Modules

Слой данных (offline, тестируется без сети):
- `excel.php` — OLE2 (FAT/DIFAT/mini‑stream) + BIFF8 (SST+CONTINUE, LABELSST,
  NUMBER, RK, MULRK, FORMULA+STRING) → грид; плюс xlsx (zip+xml) и csv.
- `profile.php` — грид → профиль (метаданные из листа `import`, баллы из листа
  `in`, тип теста по «Методике»); короткие подписи осей для диаграммы.
- `chart.php` — радар‑SVG: кольца, оси, подписи, полигон когниции + наложенный
  полигон физиологии (Зна вокруг медианы), легенда, Verdana.
- `phys.php` — OCR‑текст → строки (label, Зна, p, *), выравнивание по осям по
  устойчивому стему названия параметра.

Слой нейросети (нужны ключи):
- `llm.php` — провайдеры + fallback; `LLM::ocrImage()` для скриншота.
- `interpret.php` — сборка пользовательского сообщения + вызов + сохранение.

Слой приложения:
- `db.php` / `prompts.php` — схема и версионирование.
- `report.php` — брендированный HTML (Verdana) → PDF (печать) / письмо; md→html.
- `public/index.php` — фронт‑контроллер; `public/setup.php` — настройки.
- `bin/watch.php` — демон папки.

## Data Model

- `profiles(id, name, age, sex, test_date, methodic, test_key, scores_json,
  phys_json, phys_ocr_text, source_file, status, created_at)`
- `prompts(id, test_key, name, active_version_id, created_at)`
- `prompt_versions(id, prompt_id, version_no, body, model_id, provider, comment,
  created_at)`
- `interpretations(id, profile_id, prompt_version_id, model_id, content,
  created_at)`
- `watch_seen(hash, path, profile_id, seen_at)` — идемпотентность watcher’а.

## Risks / Decisions

- **`.xls` BIFF8 ридер на чистом PHP** — самый рискованный участок. Решение:
  собственный компактный ридер, провалидирован на реальном образце (эталонные
  значения совпали). Поддержаны SST‑строки с CONTINUE (кириллица) и кэш‑значения
  формул.
- **PDF с Verdana + кириллица + SVG** — серверный рендер требует TTF/доп.‑libs.
  Решение для MVP: print‑CSS HTML (браузер «Сохранить как PDF», Verdana
  системная, SVG векторно). Серверный PDF — отдельной задачей.
- **Выравнивание физиологии по осям** зависит от OCR. Решение: эвристика по
  стему + (план) ручная правка значений в UI перед отрисовкой.

## Test Strategy

- Offline‑смоук на эталоне: парс `.xls` → 12 баллов, `Phys::parse` на
  синтетическом OCR‑тексте → 12 выровненных Зна, рендер отчёта.
- HTTP‑смоук всех роутов (200, без фаталов).
- Watcher: появление `.xls` в папке → профиль создан один раз (по sha1).
