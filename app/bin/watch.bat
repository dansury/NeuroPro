@echo off
rem ============================================================================
rem  NeuroPro — запуск демона слежения за папкой на Windows.
rem
rem  Двойной клик запускает наблюдение за WATCH_DIR: новый .xls/.xlsx Эгоскопа
rem  автоматически превращается в профиль «ожидает скриншот».
rem
rem  Интервал опроса (сек) можно передать первым аргументом:  watch.bat 10
rem  PHP берётся из переменной окружения PHP_BIN, иначе — из PATH (php.exe).
rem  Для автозапуска при входе в систему добавьте ярлык в shell:startup или
rem  создайте задачу в «Планировщике заданий» (Task Scheduler).
rem ============================================================================

rem UTF-8 в консоли, чтобы кириллица в логах читалась корректно.
chcp 65001 >nul

setlocal
set "SCRIPT_DIR=%~dp0"
set "INTERVAL=%~1"
if "%INTERVAL%"=="" set "INTERVAL=5"

if defined PHP_BIN (
    set "PHP=%PHP_BIN%"
) else (
    set "PHP=php"
)

rem Проверяем, что PHP доступен.
"%PHP%" -v >nul 2>&1
if errorlevel 1 (
    echo [watch] PHP не найден. Установите PHP 8.4+ и добавьте php.exe в PATH,
    echo         либо задайте путь в переменной окружения PHP_BIN.
    pause
    exit /b 1
)

echo [watch] Запуск демона ^(интервал %INTERVAL%с^). Закройте окно для остановки.

rem Автоперезапуск: если демон упал (например, временная блокировка БД),
rem ждём 3 секунды и поднимаем снова.
:loop
"%PHP%" "%SCRIPT_DIR%watch.php" %INTERVAL%
echo [watch] Демон остановлен (код %errorlevel%). Перезапуск через 3с...
timeout /t 3 /nobreak >nul
goto loop
