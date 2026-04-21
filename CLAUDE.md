# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

`ModuleMegafonPbx` — расширение для MikoPBX (PHP 7.4, Phalcon 4). Назначение: периодически читать историю звонков из MegaPBX (МегаФон ВАТС) через CRM API и записывать её в CDR MikoPBX. Модуль read‑only по отношению к MegaPBX и не управляет звонками. Загруженную историю далее можно выгружать в 1С (см. `getHistory.epf` и REST‑эндпоинт `Lib/RestAPI/GetController.php`).

PSR‑4: `Modules\ModuleMegafonPbx\` → корень репозитория. Зависит от `mikopbx/core >= 2020.2.757` (см. `composer.json`, `module.json`).

## Build & release

Нет локальных команд сборки/линта/тестов — модуль собирается и публикуется CI:

- `.github/workflows/build.yml` делегирует общему workflow `mikopbx/.github-workflows/.github/workflows/extension-publish.yml@master` (триггеры: push в `master`/`develop`, ручной запуск). `initial_version: "1.4"`.
- Плейсхолдер `%ModuleVersion%` в `module.json` подставляется этим workflow при публикации; не редактировать вручную.
- Установка/деинсталляция в работающей PBX выполняется через `Setup/PbxExtensionSetup.php` (`installDB` создаёт таблицу `m_ModuleMegafonPbx` из аннотаций модели, регистрирует модуль и добавляет пункт сайдбара).

## Архитектура

Три слабо связанные части, объединённые одной моделью настроек `ModuleMegafonPbx`:

1. **Импорт CDR (cron worker)** — `bin/synchCdr.php`.
   - Регистрируется в crontab из `Lib/MegafonPbxConf.php::createCronTasks()` с расписанием `*/1 * * * *`.
   - Берёт окно `[settings.offset, now]` (по умолчанию −10 дней), запрашивает `https://{host}/crmapi/v1/history/json` и `/crmapi/v1/users` по `X-API-KEY`.
   - Маппит пользователей: `users[login] = user[extField] ?? user[telnum]` (см. константы `EXTENSION_FIELD_EXT|TEL` в `Models/ModuleMegafonPbx.php`).
   - Для каждой записи синтезирует CDR с фиксированным `from_account = 'fs-megapbx'` и `UNIQUEID/linkedid = "fs-megapbx-{ts}.{uid}"` — этот префикс является единственным способом отличить «свои» строки. Каналы PJSIP формируются как `PJSIP/{ext|megapbx}-{uid}`.
   - Запись‑файл скачивается с того же API и кладётся в `Storage::getMonitorDir()/Y/m/d/H/`.
   - CDR публикуются батчами по >9 строк в beanstalkd‑тьюб `WorkerCallEvents` с `action = insert_cdr` (insert делает `WorkerCallEvents` ядра MikoPBX, не модуль).
   - `settings.offset` (строка `Ymd\THis\Z`) обновляется только если все скачивания записей прошли без ошибок — иначе окно просто перепрочитается на следующей минуте.
   - `settings.gap` — сдвиг времени в часах; применяется к `start/answer/endtime`. Для расчёта `startTime` окна используется удвоенный модуль gap при отрицательном значении (см. строки 38–43 в `synchCdr.php`).

2. **REST‑эндпоинт для внешних потребителей (1С)** — `Lib/RestAPI/GetController.php`.
   - Регистрируется через `MegafonPbxConf::getPBXCoreRESTAdditionalRoutes()` как `GET /pbxcore/mega-pbx/cdr`.
   - Запрашивает CDR через beanstalkd‑тьюб `WorkerCdr::SELECT_CDR_TUBE` (фильтр `id > offset`, лимит ≤600), пропускает всё, у чего `linkedid` не начинается с `fs-megapbx-`, и отдаёт XML `<history>...</history>`. Возвращает заголовки `X-MIN-OFFSET`/`X-MAX-OFFSET` для пагинации потребителем.

3. **Админ‑UI** — `App/Controllers/ModuleMegafonPbxController.php` + `App/Forms/ModuleMegafonPbxForm.php` + `App/Views/index.volt`.
   - Форма редактирует только `authApiKey`, `host`, `gap`, `extField`. Прочие поля модели (`text_area_field`, `password_field`, `integer_field`, `checkbox_field`, `toggle_field`, `dropdown_field`) — наследие шаблона `moduletemplate`, в UI не отображаются, но `saveAction()` их перебирает по именам атрибутов — при добавлении новой колонки в модель достаточно отрендерить её в форме.
   - `getTablesDescription()` и DataTables‑эндпоинты (`getNewRecordsAction`, `saveTableDataAction`, `deleteAction`, `changePriorityAction`) ссылаются на класс `Modules\ModuleMegafonPbx\Models\PhoneBook`, которого в репозитории нет — это «спящий» каркас под отсутствующую модель; вызовы к таблице `PhoneBook` упадут до момента её добавления.
   - JS источник — `public/assets/js/src/module-megafon-pbx-index.js`; собранный артефакт лежит рядом (`module-megafon-pbx-index.js` + `.js.map`). Минификации/бандлинга в этом репо нет — собранный файл коммитится напрямую.

## Гайдлайны для правок

- HTTP к MegaPBX везде идёт через GuzzleHttp\Client с короткими таймаутами (5 с) — сохраняйте этот паттерн, чтобы крон‑воркер не висел.
- Не меняйте формат `UNIQUEID/linkedid` (`fs-megapbx-…`): по нему фильтруют записи REST‑контроллер и (предположительно) внешние потребители; смена формата сломает совместимость с уже импортированными CDR.
- Идемпотентность импорта обеспечивается ключом `UNIQUEID` на стороне ядра MikoPBX (через `WorkerCallEvents`), а не самим модулем.
- Локализация: `Messages/en.php` и `Messages/ru.php`. Часть ключей (`module_megafon_pbxTextField...`) осталась от шаблона и не используется — при чистке убедитесь, что ключ не дёргается из Volt/JS.
- `Lib/MegafonPbxConf.php` наследуется от `MikoPBX\Modules\Config\ConfigClass` — добавление новых хуков в жизненный цикл PBX делается переопределением методов этого базового класса.
- В заголовках PHP‑файлов фигурирует пометка «Proprietary and confidential», но `composer.json` декларирует `GPL-3.0-or-later`, а `LICENSE` — GPLv3. Истинная лицензия — GPL; не воспроизводите proprietary‑шапку в новых файлах.

## Общие конвенции модулей MikoPBX

Эти принципы общие для большинства модулей в `…/MikoPBX/Extensions/Module*` и применимы к `ModuleMegafonPbx`. При сомнениях — посмотреть, как сделано в `ModuleMtsPbx` (наиболее близкий аналог: импорт CDR через REST стороннего PBX) или `ModulePT1CCore` (отдача CDR/записей в 1С через REST).

### Установка / Setup

- Класс `Setup/PbxExtensionSetup.php` всегда наследует `MikoPBX\Modules\Setup\PbxExtensionSetupBase`. Базовый класс делает большую часть работы; модулю нужно лишь переопределить `installDB()` (порядок: `createSettingsTableByModelsAnnotations()` → `registerNewModule()` → `addToSidebar()`) и опционально `unInstallDB($keepSettings)` для бэкапа настроек перед удалением.
- Схема БД генерируется **из PHP‑аннотаций моделей** (`@Primary`, `@Identity`, `@Column(type=…, nullable=…, default=…)`) — отдельных SQL‑миграций нет. Соглашение об именовании таблицы: `m_{ModuleUniqueID}` (см. `setSource('m_ModuleMegafonPbx')`).
- Уникальный идентификатор модуля задаётся в `module.json` (`moduleUniqueID`) и должен совпадать с PSR‑4 неймспейсом `Modules\{moduleUniqueID}\`. Лицензионный гейт MIKO задаётся парой `lic_product_id` / `lic_feature_id`.
- **Никогда не устанавливать/обновлять модуль вручную через `cp`/`rsync`/распаковку zip в `/storage/usbdisk1/mikopbx/custom_modules/`**: это ломает регистрацию в `PbxExtensionModules`, права и симлинки. Единственный поддерживаемый путь — загрузка zip через UI «Маркетплейс модулей» или внутренний `WorkerModuleInstaller` ядра.

### Деплой / Build / CI

- Сборка и публикация делегированы общему reusable workflow `mikopbx/.github-workflows/.github/workflows/extension-publish.yml@master`. Локальный workflow (`.github/workflows/build.yml`) только передаёт `initial_version` и наследует `secrets`.
- Версионирование автоматическое: плейсхолдер `%ModuleVersion%` в `module.json` подставляется CI на основе `initial_version` + счётчика. Не коммитить вручную проставленную версию.
- В zip‑архив релиза не должны попадать: `.git*`, `CLAUDE.md`, `.DS_Store`, любые рабочие файлы вроде `tasks.md`, `sessions/`, `.claude/`. Если появляется `.gitattributes` с `export-ignore` — поддерживайте его актуальным.
- `release_settings` в `module.json` (`publish_release`, `changelog_enabled`, `create_github_release`) — флаги для CI, а не для PBX. `min_pbx_version` контролирует совместимость на этапе установки.
- Frontend‑пайплайн (если есть JS): источники в `public/assets/js/src/` (ES6+), скомпилированные ES5‑артефакты — рядом в `public/assets/js/`. Редактировать **только** `src/`; собранный файл и `*.js.map` — это коммитимый артефакт, генерируемый Babel (preset `airbnb`). В этом репозитории так уложен `module-megafon-pbx-index.js`.

### Разработка REST

- Дополнительные REST‑маршруты модуль регистрирует в `Lib/{ModuleName}Conf.php` (наследник `MikoPBX\Modules\Config\ConfigClass`) методом `getPBXCoreRESTAdditionalRoutes(): array`. Сигнатура одной записи в массиве — позиционная: `[ControllerClass, 'actionName', '/url/path', 'http_method', '/?', noAuth_bool]`. Пример из этого модуля:
  ```php
  [GetController::class, 'getDataAction', '/pbxcore/mega-pbx/cdr', 'get', '/', true]
  ```
  Последний `true` означает **публичный** эндпоинт без авторизации — будьте аккуратны с тем, что отдаётся.
- Базовый префикс пути по конвенции — `/pbxcore/...`. Часто используют `/pbxcore/api/modules/{Module}/` или специализированный сегмент (`/pbxcore/mega-pbx/`, `/pbxcore/mts-pbx/`). Под одним модулем удобно держать единый префикс — это упрощает фильтрацию в Nginx/логах.
- REST‑контроллеры наследуют `MikoPBX\PBXCoreREST\Controllers\BaseController` (не `AdminCabinet\Controllers\BaseController` — это для UI‑админки). Из контроллера доступны `$this->request` (Phalcon) и `$this->response`; для бинарного/нестандартного ответа использовать `setContent()` + `sendRaw()`, как в `GetController::getDataAction`.
- Тяжёлые операции (запросы к CDR, AMI, file I/O) **нельзя** делать синхронно в REST‑процессе. Канонический паттерн — отправить задачу в beanstalkd через `MikoPBX\Core\System\BeanstalkClient` в общую тьюбу ядра:
  - `WorkerCdr::SELECT_CDR_TUBE` — выборка CDR (ответ часто приходит как путь к временному файлу: `miko_result_in_file => true`, см. `GetController.php:34`).
  - `WorkerCallEvents::class` — вставка/изменение CDR (`action => 'insert_cdr'`, см. `bin/synchCdr.php`).
  Это интеграция с уже работающими воркерами ядра; собственный воркер модулю в большинстве случаев не нужен.
- Аутентификация: при `noAuth = false` запрос проходит штатную проверку PBXCoreREST (Bearer/сессия админки). Для эндпоинтов, дёргаемых из внешних систем (1С, биллинг) и закрытых только сетевыми средствами, ставится `noAuth = true` — обязательно ограничивайте доступ на уровне сети/firewall и не отдавайте ничего, что нельзя показать анонимно.
- Crontab для воркеров модуля регистрируется тем же `Conf`‑классом через `createCronTasks(&$tasks)`. Путь к PHP — `MikoPBX\Core\System\Util::which('php')`, путь к скрипту — `$this->moduleDir.'/bin/...'`. Скрипт должен подключать `Globals.php` в начале (см. `bin/synchCdr.php:25`).
