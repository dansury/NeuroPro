@echo off
rem ===========================================================================
rem  NeuroPro - drop-in folder watcher for Windows (single file, no install).
rem  Docs below are in Russian; this header stays ASCII because the console
rem  code page is switched to UTF-8 only on the next line.
rem ===========================================================================
chcp 65001 >nul
setlocal EnableExtensions EnableDelayedExpansion
title NeuroPro — наблюдение за папкой

rem ---------------------------------------------------------------------------
rem  Как пользоваться
rem
rem    1. Скачайте этот файл со страницы «Загрузка» сервиса.
rem    2. Положите его в ту папку, куда Эгоскоп сохраняет .xls-выгрузки.
rem    3. Запустите двойным кликом — один раз.
rem
rem  Дальше он всё делает сам: запоминает свою папку, копирует себя в профиль
rem  пользователя, прописывается в автозагрузку именно на эту папку и остаётся
rem  висеть в окне, показывая, что наблюдение идёт. Каждый новый .xls/.xlsx
rem  уходит на сервер NeuroPro и открывается в браузере как новый профиль
rem  («ожидает скриншот»).
rem
rem  Ключи (не обязательны):
rem    /status         — что стоит на автозагрузке
rem    /stop           — снять эту папку с автозагрузки
rem    /stop /all      — снять с автозагрузки все папки
rem    /once           — один проход без автозагрузки (проверка связи)
rem    /dir "C:\..."   — наблюдать другую папку, а не свою
rem    /interval 10    — интервал опроса в секундах (по умолчанию 5)
rem    /url http://... — адрес сервиса (сохраняется в настройках)
rem
rem  Требуется Windows 10/11 (используется встроенный curl.exe). PHP на машине
rem  оператора НЕ нужен — файлы уходят прямо в веб-сервис.
rem ---------------------------------------------------------------------------

set "SELF=%~f0"
set "SELF_NAME=%~nx0"
set "DEFAULT_URL=http://neuropro.skywood.club/app/"
set "HOME_DIR=%LOCALAPPDATA%\NeuroPro"
set "AGENT=%HOME_DIR%\neuropro-watch.cmd"
set "CFG=%HOME_DIR%\watch.cfg"
set "STARTUP=%APPDATA%\Microsoft\Windows\Start Menu\Programs\Startup"

set "MODE=install"
set "WATCH=%~dp0"
set "INTERVAL=5"
set "URL="
set "ALL="

rem ── Разбор аргументов ──────────────────────────────────────────────────────
:args
if "%~1"=="" goto args_done
if /i "%~1"=="/watch"    (set "MODE=watch"   & shift & goto args)
if /i "%~1"=="/once"     (set "MODE=once"    & shift & goto args)
if /i "%~1"=="/status"   (set "MODE=status"  & shift & goto args)
if /i "%~1"=="/stop"     (set "MODE=stop"    & shift & goto args)
if /i "%~1"=="/all"      (set "ALL=1"        & shift & goto args)
if /i "%~1"=="/dir"      (set "WATCH=%~2"    & shift & shift & goto args)
if /i "%~1"=="/interval" (set "INTERVAL=%~2" & shift & shift & goto args)
if /i "%~1"=="/url"      (set "URL=%~2"      & shift & shift & goto args)
echo [!] Неизвестный ключ: %~1
goto usage
:args_done

rem Нормализуем путь наблюдения: убираем хвостовой «\» (%~dp0 всегда с ним).
if "%WATCH:~-1%"=="\" set "WATCH=%WATCH:~0,-1%"
for %%I in ("%WATCH%") do set "WATCH=%%~fI"

rem Ключ папки для имён служебных файлов: «C:\Эгоскоп\Выгрузка» → «C-Эгоскоп-Выгрузка».
set "SLUG=%WATCH%"
set "SLUG=%SLUG::=-%"
set "SLUG=%SLUG:\=-%"
set "SLUG=%SLUG: =_%"
set "STUB=%STARTUP%\NeuroPro-Watch-%SLUG%.cmd"
set "SEEN=%HOME_DIR%\seen-%SLUG%.txt"
set "PREV=%HOME_DIR%\pass-%SLUG%.txt"

if not exist "%HOME_DIR%" mkdir "%HOME_DIR%" >nul 2>&1

rem Адрес сервиса: аргумент → переменная окружения → сохранённая настройка.
if not defined URL if defined NEUROPRO_URL set "URL=%NEUROPRO_URL%"
if not defined URL if exist "%CFG%" (
    for /f "usebackq tokens=1,* delims==" %%A in ("%CFG%") do (
        if /i "%%~A"=="URL" set "URL=%%~B"
    )
)

if /i "%MODE%"=="status" goto status
if /i "%MODE%"=="stop"   goto stop
goto start

rem ── Установка + запуск ─────────────────────────────────────────────────────
:start
where curl.exe >nul 2>&1
if errorlevel 1 (
    echo.
    echo  [!] Не найден curl.exe — он входит в состав Windows 10/11.
    echo      Обновите Windows либо загружайте выгрузки вручную через сайт.
    goto fail
)

if not exist "%WATCH%\" (
    echo.
    echo  [!] Папка не найдена: %WATCH%
    goto fail
)

if not defined URL (
    echo.
    echo  Адрес сервиса NeuroPro. Enter — оставить по умолчанию:
    echo    %DEFAULT_URL%
    set /p "URL=  Адрес: "
)
if not defined URL set "URL=%DEFAULT_URL%"
if "%URL:~-1%"=="/" set "URL=%URL:~0,-1%"
> "%CFG%" echo URL=%URL%

if /i "%MODE%"=="once"  goto run
if /i "%MODE%"=="watch" goto run

rem Копия агента в профиле пользователя: автозагрузка не сломается, даже если
rem файл из наблюдаемой папки удалят или перенесут.
if /i not "%SELF%"=="%AGENT%" copy /y "%SELF%" "%AGENT%" >nul 2>&1
if not exist "%AGENT%" set "AGENT=%SELF%"

rem Стартовый ярлык — обычный .cmd в папке «Автозагрузка»: он виден оператору,
rem легко читается и удаляется, прав администратора не требует.
> "%STUB%" echo @echo off
>>"%STUB%" echo rem NPDIR=%WATCH%
>>"%STUB%" echo rem NeuroPro — автозапуск наблюдения. Снять: "%SELF_NAME%" /stop
>>"%STUB%" echo "%AGENT%" /watch /dir "%WATCH%" /interval %INTERVAL%

if not exist "%STUB%" (
    echo.
    echo  [!] Не удалось прописать автозагрузку: %STUB%
    echo      Наблюдение всё равно запускаю — оно проработает до закрытия окна.
)
goto run

rem ── Основной цикл ──────────────────────────────────────────────────────────
:run
if not exist "%SEEN%" type nul > "%SEEN%"
if not exist "%PREV%" type nul > "%PREV%"

echo.
echo  ==============================================================
echo    NeuroPro — папка отслеживается
echo  ==============================================================
call :kv "Папка      " "%WATCH%"
call :kv "Сервис     " "%URL%/"
call :kv "Опрос      " "каждые %INTERVAL% с; файлы .xls и .xlsx"
if /i "%MODE%"=="once" (
    call :kv "Режим      " "один проход, автозагрузку не трогаю"
) else if exist "%STUB%" (
    call :kv "Автозапуск " "включён — при входе в Windows поднимется сам"
) else (
    call :kv "Автозапуск " "НЕ включён"
)
echo  ==============================================================
echo.
echo  Новый файл Эгоскопа отсюда уйдёт в NeuroPro и откроется в браузере.
echo  Окно можно свернуть — наблюдение идёт, пока оно открыто.
echo  Остановить: закрыть окно или Ctrl+C.
echo  Снять с автозагрузки: %SELF_NAME% /stop
echo.

:loop
call :scan
if /i "%MODE%"=="once" goto once_done
timeout /t %INTERVAL% /nobreak >nul
goto loop

:once_done
call :log "Проход завершён — связь с сервисом проверена."
goto done

rem ── Один проход по папке ───────────────────────────────────────────────────
rem Файл забираем, только если его «имя|размер|дата» не изменились с прошлого
rem опроса: недописанная выгрузка (копирование ещё идёт) не уедет наполовину.
:scan
set "OPEN_URL="
set "CUR=%PREV%.new"
type nul > "%CUR%"
for %%F in ("%WATCH%\*.xls" "%WATCH%\*.xlsx") do (
    set "SIG=%%~nxF|%%~zF|%%~tF"
    >>"%CUR%" echo(!SIG!
    findstr /x /c:"!SIG!" "%SEEN%" >nul 2>&1
    if errorlevel 1 (
        findstr /x /c:"!SIG!" "%PREV%" >nul 2>&1
        if not errorlevel 1 call :ingest "%%~fF" "!SIG!"
    )
)
move /y "%CUR%" "%PREV%" >nul 2>&1
if defined OPEN_URL (
    start "" "!OPEN_URL!"
    call :log "→ Открываю профиль в браузере"
)
exit /b 0

rem ── Отправка одного файла в сервис ─────────────────────────────────────────
rem Три попытки: временная сетевая ошибка не должна стоить оператору файла.
:ingest
set "FILE=%~1"
set "SIG=%~2"
set "OK="
call :log "+ Новый файл: %~nx1 — отправляю…"
for /l %%T in (1,1,3) do (
    if not defined OK (
        if %%T GTR 1 (
            call :log "  сервис не ответил как надо (HTTP !CODE!) — повтор через 5 с, попытка %%T из 3"
            timeout /t 5 /nobreak >nul
        )
        call :upload "%FILE%"
    )
)
rem Файл помечаем обработанным в любом случае, иначе неудачный будет уходить
rem в сервис каждые несколько секунд. Про неудачу говорим оператору явно.
>>"%SEEN%" echo(!SIG!
if defined OK (
    set "OPEN_URL=!EFF!"
    call :log "  принят: !EFF!"
) else (
    call :log "! Файл не принят (HTTP !CODE!). Загрузите его вручную: %URL%/?p=upload"
    call :log "  ответ сервера сохранён в %TEMP%\np-watch-last.html"
)
exit /b 0

rem Одна попытка отправки. Успех = сервис ответил редиректом на карточку
rem профиля (?p=profile&id=...) — именно так act_upload() завершает загрузку.
:upload
set "CODE="
set "EFF="
for /f "usebackq tokens=1,*" %%A in (`curl.exe -s -S -L --max-time 180 -A "NeuroPro-Watch" -o "%TEMP%\np-watch-last.html" -w "%%{http_code} %%{url_effective}" -F file=@"%~1" "%URL%/?p=upload" 2^>nul`) do (
    set "CODE=%%A"
    set "EFF=%%B"
)
echo !EFF! | findstr /i "p=profile" >nul 2>&1
if not errorlevel 1 set "OK=1"
exit /b 0

rem ── Служебные ──────────────────────────────────────────────────────────────
:log
echo  [%time:~0,8%] %~1
exit /b 0

:kv
echo    %~1: %~2
exit /b 0

:status
echo.
echo  Папки на автозагрузке NeuroPro:
set "FOUND="
for %%S in ("%STARTUP%\NeuroPro-Watch-*.cmd") do (
    set "FOUND=1"
    for /f "usebackq tokens=1,* delims==" %%A in (`findstr /b /c:"rem NPDIR=" "%%~fS"`) do echo      %%B
)
if not defined FOUND echo      (пусто — ни одна папка не отслеживается автоматически)
echo.
echo  Настройки: %CFG%
if defined URL echo  Сервис   : %URL%/
goto done

:stop
if defined ALL (
    del /q "%STARTUP%\NeuroPro-Watch-*.cmd" >nul 2>&1
    echo.
    echo  [i] Все папки сняты с автозагрузки.
    goto done
)
if exist "%STUB%" (
    del /q "%STUB%" >nul 2>&1
    echo.
    echo  [i] Папка снята с автозагрузки: %WATCH%
) else (
    echo.
    echo  [i] Эта папка и не стояла на автозагрузке: %WATCH%
)
goto done

:usage
echo.
echo  Использование: %SELF_NAME% [/status ^| /stop [/all] ^| /once]
echo                 [/dir "C:\папка"] [/interval 5] [/url http://сервис/app/]
goto done

:fail
echo.
pause
exit /b 1

:done
echo.
pause
exit /b 0
