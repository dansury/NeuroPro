# NeuroPro — Claude Code Guide

Сервис психофизиологической интерпретации тестов оборудования **«Эгоскоп»**.
Загружает Excel‑выгрузку теста + скриншот таблицы «смысло‑эмоциональной
значимости», строит **математическую** диаграмму‑паутинку (когнитивные
показатели ⊕ физиология), и выдаёт брендированную интерпретацию (PDF / e‑mail)
через выбранную нейросеть.

> Эта работа ведётся по двум методологиям: **Spec Kit** (spec‑driven
> development) и **BMAD‑METHOD** (agentic agile). Раздел «Рабочий процесс» ниже —
> обязателен к соблюдению.

---

## Рабочий процесс (Spec Kit + BMAD)

Мы совмещаем две методологии. Правило выбора простое:

| Задача | Инструмент | Команда / скилл |
| --- | --- | --- |
| Новая крупная фича «с нуля», нужен анализ → PRD → архитектура → истории | **BMAD** | `bmad-agent-analyst` → `bmad-create-prd` → `bmad-create-architecture` → `bmad-create-epics-and-stories` |
| Формализовать одну фичу в исполнимую спецификацию и план | **Spec Kit** | `/speckit-specify` → `/speckit-clarify` → `/speckit-plan` → `/speckit-tasks` → `/speckit-implement` |
| Реализация конкретной истории/таска | **BMAD** | `bmad-dev-story` / `bmad-quick-dev` |
| Ревью, edge‑cases, ретро | **BMAD** | `bmad-code-review`, `bmad-review-edge-case-hunter`, `bmad-retrospective` |
| Проверка согласованности артефактов спеки | **Spec Kit** | `/speckit-analyze`, `/speckit-checklist` |

**Базовый цикл для любой новой работы:**

1. **Spec first.** Не пишем код без спецификации. Для фичи запусти
   `/speckit-specify`, описав *что* и *зачем* (без деталей реализации). Спека
   ложится в `specs/NNN-<slug>/spec.md`.
2. **Уточнение.** `/speckit-clarify` снимает неоднозначности до планирования.
3. **План.** `/speckit-plan` — технический план под Конституцию проекта
   (`.specify/memory/constitution.md`). План — в `specs/NNN-*/plan.md`.
4. **Задачи.** `/speckit-tasks` раскладывает план в `tasks.md`.
5. **Реализация.** Веди разработку как BMAD‑истории (`bmad-dev-story`):
   маленькие вертикальные срезы, каждый — рабочий и проверяемый.
6. **Качество.** Перед PR — `bmad-code-review` + `/speckit-analyze`.

Артефакты живут в репозитории и версионируются вместе с кодом:

- `.specify/` — движок Spec Kit (шаблоны, скрипты, конституция).
- `specs/NNN-*/` — спеки, планы, задачи по фичам.
- `_bmad/` — модули BMAD (core, bmm) и их воркфлоу.
- `.claude/skills/` — установленные скиллы обеих методологий (`speckit-*`,
  `bmad-*`).

Текущая фича уже описана: `specs/001-neuropro-service/`.

---

## Конституция проекта

Принципы — в `.specify/memory/constitution.md`. Ключевое, что проверяется в
ревью каждой задачи:

1. **Диаграмма — математика, не нейросеть.** Любой расчёт/геометрия диаграммы —
   детерминированный код (`www/lib/chart.php`). Нейросети используются **только**
   для текстовой интерпретации и OCR.
2. **Никаких выдуманных данных.** Числа берутся из Excel и OCR; если данных нет —
   честно отмечаем прочерком (это же требование зашито в промпты).
3. **Чистый PHP, без Composer.** Только `ext-curl`, `ext-gd`, `ext-pdo_sqlite`,
   `ext-zip`, `ext-mbstring`. Переиспользуем библиотеку из `www/lib/`.
4. **Секреты — только через ENV / `.env` / `setup.php`.** В коде секретов нет.
   На хостинге, где нельзя положить файл над веб-корнем, всё (включая пароль
   администратора) задаётся через `setup.php`: значения хранятся в таблице
   `settings` (БД в `data/` над веб-корнем) и переживают деплой. Пароль
   администратора задаётся при **первом запуске** прямо в `setup.php` — править
   перезаписываемый `config.php` или загружать `.env` не нужно.
5. **Версионируемость промптов и интерпретаций.** Любая интерпретация привязана к
   конкретной версии промпта; история не теряется.

---

## Архитектура

> **Деплой:** прод обновляется через `https://<host>/pull.php`, который зеркалит
> в веб-корень хостинга **только** содержимое `www/` (см. `pull-config.php`,
> `subdir = /www`). Поэтому библиотека живёт в `www/lib/` — внутри деплоящегося
> веб-корня. Каталог данных и `.env` живут **над** веб-корнем
> (`cfg_data_root()` / `cfg_load_env_file()` в `config.php`): локально это
> `data/` и `.env` в корне репозитория.

```
www/                  # ← веб-корень; ровно это зеркалится на прод через pull.php
  lib/
    config.php        # .env + ENV + overlay настроек из таблицы settings
                      #   AVAILABLE_MODELS — общий каталог моделей (Yandex AI Studio
                      #   + OpenRouter), сгруппированный по `group` для UI
    db.php            # SQLite-схема: profiles, prompts, prompt_versions, interpretations
    excel.php         # Чтение .xls (OLE2+BIFF8) / .xlsx / .csv — чистый PHP
    profile.php       # Грид Excel → структурный профиль (метаданные, баллы шкал, тип теста: СМУ/ИЖС/Басса-Дарки)
    chart.php         # МАТЕМАТИЧЕСКАЯ радар-диаграмма (SVG): когниция ⊕ физиология («Знач.», p<0.05 — жирным)
    phys.php          # Разбор OCR-текста таблицы значимости → выровненные «Знач.» + p по осям
    ocr (в llm.php)   # LLM::ocrImage() — Yandex Vision OCR скриншота
    llm.php           # Провайдеры Yandex (по умолчанию) + OpenRouter, fallback, OCR
                      #   Цепочка: выбранная модель у каждого провайдера
                      #   (LLM_PROVIDER_PRIORITY), затем LLM_FALLBACK_MODELS.
                      #   Слаг модели никогда не уходит чужому провайдеру.
    prompts.php       # Семейства промптов + версии (правила удаления/активации)
    prompts/          # Тексты автосидируемых версий промптов: *_v1.php (исходные,
                      #   вшиты в www/ чтобы деплоиться) + *_v2.php (под недорогие модели)
    interpret.php     # Сборка запроса + вызов нейросети + сохранение интерпретации
    report.php        # Брендированный отчёт (Verdana) → HTML/PDF/письмо
    mailer.php        # SMTP-отправка
    settings_store.php# key/value стор настроек
    bootstrap.php     # Загрузка config, init DB+LLM, сидинг промптов
  index.php           # Лендинг «/» (кнопка → /app/)
  app/index.php       # Фронт-контроллер приложения анализа «/app/» (роуты ?p=...)
  setup.php           # Настройки провайдера/модели/OCR/SMTP (пароль ADMIN_PASSWORD)
  assets/logo.png     # Логотип бренда
bin/
  watch.php           # Демон слежения за папкой: новый .xls → профиль «ожидает скриншот»;
                      # открывает APP_URL (/app/) в браузере оператора
data/                 # SQLite + логи + загрузки + incoming (gitignored; на проде — над веб-корнем)
.env                  # Секреты (gitignored; шаблон — .env.example; кладётся рядом с data/)
Sources/              # Исходники заказчика: промпты, образец .xls/.pdf, лого, референс
specs/001-neuropro-service/   # Spec Kit: спека, план, задачи этой фичи
```

### Поток данных (happy path)

1. **Ingest.** Загрузка/вставка Excel → `Profile::fromFile()` извлекает метаданные,
   тип теста (по строке «Методика») и когнитивные баллы.
   Папка‑watcher (`bin/watch.php`) делает то же автоматически при появлении файла.
2. **Скриншот.** Оператор вставляет (Ctrl+V) скриншот таблицы значимости →
   `LLM::ocrImage()` (Yandex OCR) → `Phys::parse()` выравнивает Зна по осям.
3. **Диаграмма.** `Chart::svg()` строит паутинку математически и накладывает
   физиологию на когницию.
4. **Интерпретация.** По типу теста выбирается активная версия промпта;
   `Interpret::run()` зовёт выбранную нейросеть, результат сохраняется к версии.
5. **Выдача.** `Report::html()` → брендированный PDF (печать из браузера, Verdana)
   или письмо клиенту (`Mailer`).

---

## Команды

```bash
# Запуск веб-сервиса (dev)
php -S 127.0.0.1:8080 -t www
# открыть http://127.0.0.1:8080/index.php   (настройки: /setup.php)

# Демон слежения за папкой (auto-ingest .xls)
php bin/watch.php 5             # интервал 5 c, папка = WATCH_DIR

# Линт
php -l www/lib/<файл>.php

# Конфигурация секретов — через .env в корне репозитория (см. .env.example)
# или setup.php. На хостинге .env лежит НАД корнем сайта, рядом с data/.
```

### Конвенции кода

- PHP 8.4, `declare(strict_types=1)` в новых файлах, классы — `final`,
  статические фасады (как в существующей библиотеке).
- Комментарии и UI — по-русски (язык команды); идентификаторы — латиницей.
- Совпадай по стилю с окружающим кодом: плотность комментариев, именование.
- Любую новую обработку Excel/диаграммы проверяй на реальных образцах:
  `Sources/Дронов Андрей 19-06-2026 15 02.xls` (эталон: 12 баллов
  9,9,9,8,9,9,7,7,9,8,9,9; методика СМУ) и
  `Sources/Дронов Андрей Басса-Дарки 23-06-2026 13 22.xls` (эталон: 8 баллов
  9,7,7,3,5,8,11,3; тип `bd`; индексы: агрессивности 27 из 34, враждебности
  13 из 18 — оба выше нормы).

---

## Известные ограничения / next

См. `specs/001-neuropro-service/tasks.md` — там размечено, что готово и что
осталось (серверный PDF с встроенным TTF Verdana, LSI-образец для проверки парсера,
аутентификация операторов, тонкая настройка выравнивания физиологии по осям).

## graphify

This project has a knowledge graph at graphify-out/ with god nodes, community structure, and cross-file relationships.

Rules:
- For codebase questions, first run `graphify query "<question>"` when graphify-out/graph.json exists. Use `graphify path "<A>" "<B>"` for relationships and `graphify explain "<concept>"` for focused concepts. These return a scoped subgraph, usually much smaller than GRAPH_REPORT.md or raw grep output.
- If graphify-out/wiki/index.md exists, use it for broad navigation instead of raw source browsing.
- Read graphify-out/GRAPH_REPORT.md only for broad architecture review or when query/path/explain do not surface enough context.
- After modifying code, run `graphify update .` to keep the graph current (AST-only, no API cost).
