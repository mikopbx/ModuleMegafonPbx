<?php
/*
 * MikoPBX - free phone system for small business
 * Copyright © 2017-2026 Alexey Portnov and Nikolay Beketov
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along with this program.
 * If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * repullRecords.php — принудительная ПЕРЕКАЧКА записей fs-megapbx за последние
 * N дней, чтобы получить их в исходной раскладке (например, стерео), когда на
 * диске лежат старые mono-файлы (скачанные прежним кодом с принудительным
 * downmix). Перекодировка на месте (`recodeRecords.php`) стерео не вернёт —
 * исходные каналы потеряны; единственный источник стерео — повторная загрузка
 * из CRM API ВАТС.
 *
 * Как это работает: `bin/synchCdr.php` (sync) скачивает запись, только если её
 * нет на финальном пути (`is_file && filesize>0` → skip-existing). Значит чтобы
 * заставить перекачать — нужно убрать файл с пути. Скрипт делает это БЕЗОПАСНО:
 * переименовывает файл рядом, добавляя суффикс (`--suffix`, по умолчанию
 * `.prestereo.bak`). Это атомарно (та же ФС), мгновенно и легко откатывается
 * (`--restore`), в отличие от `mv` в /tmp (там часто tmpfs/мало места).
 *
 * Источник правды — какие файлы «свои» — это CDR (`from_account='fs-megapbx'`),
 * НЕ имена файлов: в каталоге `monitor/` лежат вперемешку и обычные записи PBX.
 * Поэтому отбор идёт через `WorkerCdr::SELECT_CDR_TUBE` (как в recodeRecords.php),
 * а дата берётся из пути (`monitor/Y/m/d/H/...`).
 *
 * По умолчанию пропускаются файлы, уже являющиеся стерео (`channels>1`) — их
 * перекачивать незачем (если у них битые заголовки — чините на месте через
 * recodeRecords.php). Отключить отбор: `--all`.
 *
 * Поведение по умолчанию — dry-run (только показать план). Реальные действия —
 * только с `--apply`.
 *
 * Типовой сценарий (на боевой PBX):
 *   1) посмотреть план:
 *        php .../bin/repullRecords.php --days=7
 *   2) отодвинуть файлы и откатить offset (после этого crontab-sync и/или
 *      ручной запуск перекачают их):
 *        php .../bin/repullRecords.php --days=7 --apply
 *   3) (опц.) снять дедуп публикации и прогнать sync вручную:
 *        rm -f /tmp/megafon_published.json
 *        php .../bin/synchCdr.php
 *   4) проверить, что новые файлы появились и стали стерео (soxi), и удалить
 *      бэкапы:
 *        php .../bin/repullRecords.php --days=7 --restore     # dry-run: что вернётся
 *        find <monitor> -name '*.prestereo.bak' -delete       # если всё ок
 *   5) если перекачка где-то не удалась (API не отдал запись) — вернуть бэкап:
 *        php .../bin/repullRecords.php --days=7 --restore --apply
 *
 * ВНИМАНИЕ: пока бэкапы не удалены, перекачанные файлы занимают место в ДВА
 * раза (оригинал .bak + новый). Убедитесь в свободном месте на /storage.
 *
 * CDR-выборка идёт через beanstalkd-tube `WorkerCdr::SELECT_CDR_TUBE` с
 * пагинацией по id (БД `cdr.db` отдельная, прямой ORM-выборки нет).
 *
 * Exit-коды: 0 — успех; 1 — ошибка окружения; 2 — часть действий не удалась.
 */

use Modules\ModuleMegafonPbx\Models\ModuleMegafonPbx;
use MikoPBX\Core\System\BeanstalkClient;
use MikoPBX\Core\System\Util;
use MikoPBX\Core\Workers\WorkerCdr;

require_once 'Globals.php';

// ────────────────────── разбор аргументов ──────────────────────
$apply    = false;
$restore  = false;
$days     = 7;
$allFiles = false;            // --all: не пропускать уже-стерео
$suffix   = '.prestereo.bak';
$maxFiles = 0;                // 0 = без ограничения
$pageSize = 600;              // верхняя планка SELECT_CDR_TUBE
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--apply') {
        $apply = true;
    } elseif ($arg === '--restore') {
        $restore = true;
    } elseif ($arg === '--all') {
        $allFiles = true;
    } elseif (strpos($arg, '--days=') === 0) {
        $days = max(1, (int)substr($arg, 7));
    } elseif (strpos($arg, '--max-files=') === 0) {
        $maxFiles = max(0, (int)substr($arg, 12));
    } elseif (strpos($arg, '--suffix=') === 0) {
        $suffix = (string)substr($arg, 9);
    } elseif ($arg === '-h' || $arg === '--help') {
        printUsage();
        exit(0);
    } else {
        fwrite(STDERR, "ERROR: unknown argument '$arg'\n");
        printUsage();
        exit(1);
    }
}
if ($suffix === '') {
    fwrite(STDERR, "ERROR: --suffix must not be empty\n");
    exit(1);
}

// ────────────────────── проверка окружения ──────────────────────
$ffprobe = Util::which('ffprobe');
$soxBin  = Util::which('sox');
if (!$allFiles && $ffprobe === '' && $soxBin === '') {
    fwrite(STDERR, "ERROR: neither ffprobe nor sox found — cannot detect channels (use --all to skip channel check)\n");
    exit(1);
}

$settings = ModuleMegafonPbx::findFirst();
if ($settings === null) {
    fwrite(STDERR, "ERROR: module settings row not found (m_ModuleMegafonPbx empty)\n");
    exit(1);
}

// Граница «последние N дней» по дате звонка (00:00 дня N дней назад).
$cutoff = (new DateTime())->setTime(0, 0, 0)->modify("-" . ($days - 1) . " day");
$mode   = $restore ? 'RESTORE' : 'MOVE-ASIDE';
echo "Mode: $mode | window: last $days day(s) (since {$cutoff->format('Y-m-d')}) | suffix: $suffix | "
   . ($apply ? 'APPLY' : 'DRY-RUN') . "\n";
if (!$restore && !$allFiles) {
    echo "Channel check: ON (already-stereo files are skipped; use --all to override)\n";
}
echo "Scanning CDR for from_account='fs-megapbx'...\n";

// ────────────────────── обход CDR пакетами по id ──────────────────────
$cdrClient = new BeanstalkClient(WorkerCdr::SELECT_CDR_TUBE);
$lastId    = 0;
$seen      = [];   // recordingfile => true (дедуп)
$plan      = [];   // [path]
$scanned   = 0;
$skipOld   = 0;    // вне окна по дате
$skipNoFile = 0;   // нет ни файла, ни (для restore) бэкапа
$skipStereo = 0;   // уже стерео (move-режим)
$batchNum  = 0;

while (true) {
    $batchNum++;
    $filter = [
        'id>:id:',
        'bind'                => ['id' => $lastId],
        'order'               => 'id',
        'limit'               => $pageSize,
        'miko_result_in_file' => true,
    ];
    $reply = $cdrClient->request(json_encode($filter), 10);
    if ($reply === false) {
        fwrite(STDERR, "ERROR: SELECT_CDR_TUBE timeout at id=$lastId (batch=$batchNum)\n");
        exit(1);
    }
    $decoded = json_decode($reply, true);
    $rows = [];
    if (is_string($decoded) && file_exists($decoded)) {
        $rows = json_decode(file_get_contents($decoded), true) ?: [];
        @unlink($decoded);
    } elseif (is_array($decoded)) {
        $rows = $decoded;
    }
    if (empty($rows)) {
        break;
    }

    foreach ($rows as $row) {
        $scanned++;
        $lastId = max($lastId, (int)($row['id'] ?? $lastId));

        if (($row['from_account'] ?? '') !== 'fs-megapbx') {
            continue;
        }
        $path = (string)($row['recordingfile'] ?? '');
        if ($path === '' || isset($seen[$path])) {
            continue;
        }
        $seen[$path] = true;

        // Дата из пути: .../monitor/YYYY/MM/DD/HH/file.mp3 — это надёжнее, чем
        // парсить поле даты CDR (форматы/TZ разнятся).
        if (!preg_match('#/(\d{4})/(\d{2})/(\d{2})/\d{2}/[^/]+$#', $path, $m)) {
            continue;
        }
        $fileDay = DateTime::createFromFormat('Y-m-d H:i:s', "{$m[1]}-{$m[2]}-{$m[3]} 00:00:00");
        if ($fileDay === false || $fileDay < $cutoff) {
            $skipOld++;
            continue;
        }

        if ($restore) {
            // Откат: вернуть .bak на путь, только если самого файла нет.
            clearstatcache(true, $path);
            if (is_file($path) || !is_file($path . $suffix)) {
                $skipNoFile++;
                continue;
            }
            $plan[] = $path;
        } else {
            // Move-aside: файл должен существовать на пути.
            clearstatcache(true, $path);
            if (!is_file($path) || filesize($path) === 0) {
                $skipNoFile++;
                continue;
            }
            // Уже отодвинут ранее — пропускаем (идемпотентность).
            if (is_file($path . $suffix)) {
                continue;
            }
            // Уже стерео — перекачивать незачем (если не --all).
            if (!$allFiles) {
                $ch = probeChannels($path, $ffprobe, $soxBin);
                if ($ch !== null && $ch > 1) {
                    $skipStereo++;
                    continue;
                }
            }
            $plan[] = $path;
        }

        if ($maxFiles > 0 && count($plan) >= $maxFiles) {
            break 2;
        }
    }

    if (count($rows) < $pageSize) {
        break;
    }
    if (($batchNum % 10) === 0) {
        echo "  ... scanned $scanned CDR rows, lastId=$lastId, candidates=" . count($plan) . "\n";
    }
}

echo "\n";
echo "CDR rows scanned:                 $scanned\n";
echo "Unique fs-megapbx recordings:     " . count($seen) . "\n";
echo "Skip — out of $days-day window:   $skipOld\n";
if ($restore) {
    echo "Skip — no .bak to restore:        $skipNoFile\n";
    echo "To restore (file gone, .bak present): " . count($plan) . "\n\n";
} else {
    echo "Skip — file missing on disk:      $skipNoFile\n";
    if (!$allFiles) {
        echo "Skip — already stereo:            $skipStereo\n";
    }
    echo "To move aside (force re-pull):    " . count($plan) . "\n\n";
}

if (empty($plan)) {
    echo "Nothing to do.\n";
    exit(0);
}

// Найдём самую старую дату из плана — на неё откатим offset (move-режим).
$oldest = null;
foreach ($plan as $p) {
    if (preg_match('#/(\d{4})/(\d{2})/(\d{2})/#', $p, $m)) {
        $d = "{$m[1]}{$m[2]}{$m[3]}";
        if ($oldest === null || $d < $oldest) {
            $oldest = $d;
        }
    }
}

if (!$apply) {
    echo "DRY-RUN. Re-run with --apply to perform. Sample of first 10:\n";
    foreach (array_slice($plan, 0, 10) as $p) {
        echo "  $p\n";
    }
    if (!$restore && $oldest !== null) {
        echo "\nOn --apply, settings.offset will be rolled back to {$oldest}T000000Z\n";
        echo "so the next synchCdr (sync) re-pulls the moved window.\n";
    }
    exit(0);
}

// ────────────────────── выполнение ──────────────────────
$done   = 0;
$failed = [];
foreach ($plan as $p) {
    if ($restore) {
        $ok = @rename($p . $suffix, $p);
    } else {
        $ok = @rename($p, $p . $suffix);
    }
    if ($ok) {
        $done++;
    } else {
        $failed[] = $p;
    }
}

echo ($restore ? "Restored" : "Moved aside") . ": $done, failed: " . count($failed) . "\n";
if (!empty($failed)) {
    echo "Failed (first 20):\n";
    foreach (array_slice($failed, 0, 20) as $p) {
        echo "  $p\n";
    }
}

// Откат offset — только в move-режиме и только если что-то реально отодвинули.
if (!$restore && $done > 0 && $oldest !== null) {
    $newOffset = $oldest . 'T000000Z';
    $oldOffset = (string)$settings->offset;
    $settings->offset = $newOffset;
    if ($settings->save()) {
        echo "settings.offset rolled back: '{$oldOffset}' -> '{$newOffset}'\n";
        echo "Next synchCdr (sync) will re-pull the window. To trigger now:\n";
        echo "  rm -f /tmp/megafon_published.json\n";
        echo "  php " . __DIR__ . "/synchCdr.php\n";
    } else {
        fwrite(STDERR, "WARNING: failed to save settings.offset — set it manually to '{$newOffset}'\n");
    }
}

exit(empty($failed) ? 0 : 2);

// ────────────────────── helpers ──────────────────────

function probeChannels(string $path, string $ffprobe, string $soxBin): ?int
{
    if ($ffprobe !== '') {
        $cmd = escapeshellcmd($ffprobe)
            . ' -v error -select_streams a:0 -of csv=p=0 -show_entries stream=channels '
            . escapeshellarg($path) . ' 2>/dev/null';
        $out = trim((string)shell_exec($cmd));
        if ($out !== '' && ctype_digit($out)) {
            return (int)$out;
        }
    }
    if ($soxBin !== '') {
        // sox --i печатает строку вида "Channels       : 2"
        $cmd = escapeshellcmd($soxBin) . ' --i ' . escapeshellarg($path) . ' 2>/dev/null';
        $out = (string)shell_exec($cmd);
        if (preg_match('/Channels\s*:\s*(\d+)/i', $out, $m)) {
            return (int)$m[1];
        }
    }
    return null;
}

function printUsage(): void
{
    echo "Usage: php repullRecords.php [--days=N] [--apply] [--restore] [--all] [--max-files=N] [--suffix=S]\n";
    echo "  --days=N        process recordings from the last N days (default 7)\n";
    echo "  --apply         perform actions (default: dry-run)\n";
    echo "  --restore       restore .bak files back (recovery); without it: move aside\n";
    echo "  --all           do not skip already-stereo files (move-aside mode)\n";
    echo "  --max-files=N   cap number of files to act on\n";
    echo "  --suffix=S      backup suffix (default .prestereo.bak)\n";
}
