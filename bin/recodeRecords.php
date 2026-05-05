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
 * Бэкфилл-перекодирование ранее скачанных MP3-записей МегаФон.
 *
 * Запускается ВРУЧНУЮ; не подключается ни в crontab, ни в воркеры. Зачем:
 * до появления `AudioRecodeHelper` модуль клал на диск оригинальный MP3
 * от ВАТС МегаФон (mono CBR 16 kbps), который не парсится async-эндпоинтом
 * STT-сервиса (`speech.mikolab.ru` отбивает с `Unexpected EOF`). Этот
 * скрипт проходит по существующим CDR с `from_account = 'fs-megapbx'`,
 * находит файлы с битрейтом ≤ заданного порога и перекодирует их in-place
 * в mono 8 кГц 32 kbps через `AudioRecodeHelper`.
 *
 * Запуск (на боевой PBX):
 *   php /storage/usbdisk1/mikopbx/custom_modules/ModuleMegafonPbx/bin/recodeRecords.php           # dry-run, всё подряд
 *   php ... recodeRecords.php --apply                                                              # реально перекодировать
 *   php ... recodeRecords.php --apply --since-id=12345                                             # начать с конкретного CDR id
 *   php ... recodeRecords.php --apply --limit=500                                                  # ограничить число файлов
 *   php ... recodeRecords.php --apply --max-bitrate=20000                                          # порог «нужна перекодировка»
 *
 * Чтение CDR: используется тот же паттерн, что в `Lib/RestAPI/GetController.php`
 * — beanstalkd-tube `WorkerCdr::SELECT_CDR_TUBE` с пагинацией по id (БД
 * `cdr.db` лежит отдельно от `mikopbx.db`, прямой Phalcon-ORM-выборки нет).
 *
 * Поведение по умолчанию: dry-run — только показывает план. Для реального
 * запуска требуется `--apply`. Скрипт идёт последовательно (одно
 * ffmpeg/sox-задание за раз), безопасен для запуска на проде в рабочее
 * время, но для большого объёма лучше прогнать ночью.
 *
 * Exit-коды: 0 — успех; 1 — ошибка окружения; 2 — часть файлов не удалось
 * перекодировать (см. лог).
 */

use Modules\ModuleMegafonPbx\Lib\AudioRecodeHelper;
use MikoPBX\Core\System\BeanstalkClient;
use MikoPBX\Core\System\Util;
use MikoPBX\Core\Workers\WorkerCdr;

require_once 'Globals.php';

// ────────────────────── разбор аргументов ──────────────────────
$apply       = false;
$sinceId     = 1;
$limit       = 0;
$maxBitrate  = 20000;        // > 16 kbps: с запасом, чтобы не задеть нормальные файлы
$pageSize    = 600;          // верхняя планка SELECT_CDR_TUBE; см. Lib/RestAPI/GetController.php
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--apply') {
        $apply = true;
    } elseif (strpos($arg, '--since-id=') === 0) {
        $sinceId = max(0, (int)substr($arg, 11));
    } elseif (strpos($arg, '--limit=') === 0) {
        $limit = max(0, (int)substr($arg, 8));
    } elseif (strpos($arg, '--max-bitrate=') === 0) {
        $maxBitrate = max(1, (int)substr($arg, 14));
    } elseif ($arg === '-h' || $arg === '--help') {
        printUsage();
        exit(0);
    } else {
        fwrite(STDERR, "ERROR: unknown argument '$arg'\n");
        printUsage();
        exit(1);
    }
}

// ────────────────────── проверка окружения ──────────────────────
if (!class_exists(AudioRecodeHelper::class)) {
    fwrite(STDERR, "ERROR: AudioRecodeHelper not autoloaded — module not installed correctly?\n");
    exit(1);
}

$ffprobe = Util::which('ffprobe');
$soxBin  = Util::which('sox');
if ($ffprobe === '' && $soxBin === '') {
    fwrite(STDERR, "ERROR: neither ffprobe nor sox found — cannot detect bitrate\n");
    exit(1);
}

// Транскодер для самого --apply нужен отдельный (ffmpeg или lame). Без него
// dry-run отработает корректно (probe есть), но --apply будет складывать
// каждый файл в failed — бессмысленная работа. Проверяем заранее.
if ($apply && !AudioRecodeHelper::isTranscoderAvailable()) {
    fwrite(STDERR, "ERROR: --apply requested but no transcoder available (need ffmpeg, or sox+lame)\n");
    exit(1);
}

// ────────────────────── обход CDR пакетами по id ──────────────────────
echo "Scanning CDR for from_account='fs-megapbx' (since_id=$sinceId)...\n";

$cdrClient   = new BeanstalkClient(WorkerCdr::SELECT_CDR_TUBE);
$lastId      = $sinceId;
$seen        = [];   // recordingfile => true (дедуп)
$plan        = [];   // [path, bitrate]
$skipMissing = 0;
$skipOk      = 0;
$skipProbe   = []; // файлы, которые есть на диске, но ffprobe/sox не вернули bit_rate
$scanned     = 0;
$batchNum    = 0;

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
        // Большие выборки приходят как путь к временному файлу.
        $rows = json_decode(file_get_contents($decoded), true) ?: [];
        @unlink($decoded);
    } elseif (is_array($decoded)) {
        // Малые/пустые выборки приходят inline.
        $rows = $decoded;
    }
    if (empty($rows)) {
        break;
    }

    foreach ($rows as $row) {
        $scanned++;
        $lastId = max($lastId, (int)$row['id']);

        if (($row['from_account'] ?? '') !== 'fs-megapbx') {
            continue;
        }
        $path = (string)($row['recordingfile'] ?? '');
        if ($path === '' || isset($seen[$path])) {
            continue;
        }
        $seen[$path] = true;

        // Сбрасываем stat-кэш: ModuleCleanRecords и подобные могут удалять
        // файлы фоном, между сканированием CDR и моментом probe.
        clearstatcache(true, $path);
        if (!is_file($path)) {
            $skipMissing++;
            continue;
        }
        $bitrate = probeBitrate($path, $ffprobe, $soxBin);
        if ($bitrate === null) {
            // Повторная проверка после probe — частый кейс: файл исчез
            // именно в этот момент. Тогда это не «probe failed», а
            // «удалили под нами» — учитываем как missing без шума в логе.
            clearstatcache(true, $path);
            if (!is_file($path)) {
                $skipMissing++;
            } else {
                $skipProbe[] = $path;
            }
            continue;
        }
        if ($bitrate > $maxBitrate) {
            $skipOk++;
            continue;
        }
        $plan[] = [$path, $bitrate];

        if ($limit > 0 && count($plan) >= $limit) {
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
echo "CDR rows scanned:                  $scanned\n";
echo "Unique fs-megapbx recordings seen: " . count($seen) . "\n";
echo "Skip — file missing on disk:       $skipMissing\n";
echo "Skip — bitrate > $maxBitrate (already OK): $skipOk\n";
echo "Skip — probe failed (file present but unreadable): " . count($skipProbe) . "\n";
echo "Need recode:                       " . count($plan) . "\n\n";

if (!empty($skipProbe)) {
    echo "Probe-failed files (first 10) — broken or non-MP3 content, recommend manual check:\n";
    foreach (array_slice($skipProbe, 0, 10) as $p) {
        echo "  $p\n";
    }
    echo "\n";
}

if (empty($plan)) {
    echo "Nothing to do.\n";
    exit(0);
}

if (!$apply) {
    echo "DRY-RUN. Re-run with --apply to perform recoding. Sample of first 10 files:\n";
    foreach (array_slice($plan, 0, 10) as [$p, $br]) {
        echo "  {$br}bps  $p\n";
    }
    exit(0);
}

echo "Applying recoding...\n";
$done   = 0;
$failed = [];
foreach ($plan as $i => [$path, $br]) {
    $ok = AudioRecodeHelper::recodeToMonoMp3($path);
    if ($ok) {
        $done++;
        if ((($i + 1) % 50) === 0) {
            echo "  ... processed " . ($i + 1) . " / " . count($plan)
               . " (done=$done failed=" . count($failed) . ")\n";
        }
    } else {
        $failed[] = $path;
    }
}

echo "\n";
echo "DONE: recoded=$done, failed=" . count($failed) . "\n";
if (!empty($failed)) {
    echo "Failed files (first 20):\n";
    foreach (array_slice($failed, 0, 20) as $p) {
        echo "  $p\n";
    }
    exit(2);
}
exit(0);

// ────────────────────── helpers ──────────────────────

function probeBitrate(string $path, string $ffprobe, string $soxBin): ?int
{
    if ($ffprobe !== '') {
        $cmd = escapeshellcmd($ffprobe)
            . ' -v error -of csv=p=0 -show_entries stream=bit_rate '
            . escapeshellarg($path) . ' 2>/dev/null';
        $out = trim((string)shell_exec($cmd));
        if ($out !== '' && ctype_digit($out)) {
            return (int)$out;
        }
    }
    if ($soxBin !== '') {
        // sox --i печатает строку вида "Bit Rate       : 16.0k"
        $cmd = escapeshellcmd($soxBin) . ' --i ' . escapeshellarg($path) . ' 2>/dev/null';
        $out = (string)shell_exec($cmd);
        if (preg_match('/Bit Rate\s*:\s*([\d.]+)k/i', $out, $m)) {
            return (int)round(((float)$m[1]) * 1000);
        }
    }
    return null;
}

function printUsage(): void
{
    echo "Usage: php recodeRecords.php [--apply] [--since-id=N] [--limit=N] [--max-bitrate=BPS]\n";
    echo "  --apply              perform recoding (default: dry-run)\n";
    echo "  --since-id=N         start scanning CDR from id > N (resume)\n";
    echo "  --limit=N            cap number of files to recode\n";
    echo "  --max-bitrate=BPS    files with bit_rate > BPS are skipped (default 20000)\n";
}
