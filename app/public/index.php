<?php
/**
 * NeuroPro — лендинг.
 *
 * Простая стартовая страница: коротко о сервисе и крупная кнопка перехода
 * в рабочее приложение анализа (/app/). Само приложение — в app/public/app/.
 * Папку-watcher (app/bin/watch.php) настроен открывать именно /app/.
 */

declare(strict_types=1);

$brand = getenv('BRAND_NAME') ?: 'Центр корпоративной психологии «НейроПро»';
$phone = getenv('BRAND_PHONE') ?: '8-917-859-60-79';
$h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

header('Content-Type: text/html; charset=utf-8');
?><!doctype html><html lang="ru"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>НейроПро — психофизиологическая интерпретация тестов «Эгоскоп»</title>
<style>
  *{box-sizing:border-box}
  body{font-family:Verdana,Geneva,sans-serif;color:#2a3138;background:#f4f6f8;margin:0;
       min-height:100vh;display:flex;flex-direction:column}
  header{background:#fff;border-bottom:2px solid #b3203b;padding:14px 22px}
  header b{color:#b3203b;font-size:18px}
  main{flex:1;display:flex;align-items:center;justify-content:center;padding:24px}
  .hero{background:#fff;border:1px solid #e0e5ea;border-radius:12px;padding:44px 38px;
        max-width:560px;text-align:center;box-shadow:0 4px 24px rgba(42,49,56,.06)}
  .hero h1{font-size:26px;margin:0 0 10px;line-height:1.25}
  .hero p{color:#6b7682;font-size:14px;line-height:1.6;margin:0 0 28px}
  .btn{display:inline-block;background:#b3203b;color:#fff;border:0;padding:16px 34px;
       border-radius:6px;text-decoration:none;font:inherit;font-weight:bold;font-size:16px}
  .btn:hover{background:#8f1a30}
  footer{color:#8a949d;font-size:12px;text-align:center;padding:18px}
  footer b{color:#b3203b}
</style></head><body>
<header><b>НейроПро</b></header>
<main>
  <div class="hero">
    <h1>Психофизиологическая интерпретация тестов «Эгоскоп»</h1>
    <p>Загрузка Excel-выгрузки теста и скриншота таблицы значимости, построение
       диаграммы-паутинки (когниция ⊕ физиология) и брендированная интерпретация
       нейросетью — PDF или письмо клиенту.</p>
    <a class="btn" href="/app/">Перейти к анализу →</a>
  </div>
</main>
<footer><b><?= $h($brand) ?></b> · <?= $h($phone) ?></footer>
</body></html>
