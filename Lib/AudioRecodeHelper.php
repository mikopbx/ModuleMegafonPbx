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

namespace Modules\ModuleMegafonPbx\Lib;

use MikoPBX\Core\System\Util;

/**
 * Перекодировщик MP3-записей, скачанных из CRM API ВАТС МегаФон.
 *
 * МегаФон отдаёт mono CBR LAME 8 кГц 16 kbps — такие файлы не парсятся
 * MP3-декодером async-эндпоинта STT-сервиса (`speech.mikolab.ru`),
 * запрос отбивается ошибкой `400 Unexpected EOF`. Перекодирование в
 * mono 8 кГц 32 kbps снимает проблему: содержимое то же, заголовки
 * валидны, размер растёт ~×2.
 *
 * Класс умеет работать через ffmpeg (предпочтительно) или через
 * пайплайн sox→lame (для legacy-стендов без ffmpeg). Если ни одного
 * транскодера нет — тихо возвращает false и оставляет оригинал.
 */
class AudioRecodeHelper
{
    /** Целевой sample rate выходного MP3, Гц. */
    public const TARGET_SAMPLE_RATE = 8000;

    /** Целевой битрейт, kbps. */
    public const TARGET_BITRATE_KBPS = 32;

    /**
     * Флаг «однажды залогировали отсутствие транскодера за время жизни
     * процесса» — чтобы не спамить syslog по каждому из десятков файлов
     * за минуту работы крон-воркера.
     */
    private static bool $missingTranscoderLogged = false;

    /**
     * Доступен ли хотя бы один транскодер в системе (ffmpeg или sox+lame).
     * Полезно для бэкфилл-скрипта, чтобы выйти заранее с понятной ошибкой,
     * а не идти по всем файлам и складывать каждый в `failed`.
     */
    public static function isTranscoderAvailable(): bool
    {
        if (self::resolveBin('ffmpeg') !== '') {
            return true;
        }
        return self::resolveBin('sox') !== '' && self::resolveBin('lame') !== '';
    }

    /**
     * Найти полный путь к бинарю, либо вернуть ''. Защита от ситуации,
     * когда `Util::which()` отдаёт имя без пути (а PATH в `sh` отличается
     * от PATH у php.backend) — наблюдалось на проде: rc=127 «sh: ffmpeg:
     * not found» при наличии бинаря в системе.
     */
    private static function resolveBin(string $name): string
    {
        $candidate = Util::which($name);
        if ($candidate !== '' && strpos($candidate, '/') !== false && is_executable($candidate)) {
            return $candidate;
        }
        // fallback: типовые места установки в MikoPBX/Linux
        foreach (['/usr/bin/', '/usr/local/bin/', '/sbin/', '/usr/sbin/'] as $dir) {
            $p = $dir . $name;
            if (is_executable($p)) {
                return $p;
            }
        }
        return '';
    }

    /**
     * Перекодировать MP3 in-place в mono 8 кГц 32 kbps.
     *
     * @param string $path Полный путь к MP3-файлу. Файл должен существовать
     *                     и быть доступным на запись (атомарная замена).
     * @return bool true — файл успешно заменён; false — оставлен оригинал
     *              (нет транскодера или ошибка при перекодировании).
     */
    public static function recodeToMonoMp3(string $path): bool
    {
        if (!is_file($path) || filesize($path) === 0) {
            return false;
        }

        $ffmpeg = self::resolveBin('ffmpeg');
        $sox    = self::resolveBin('sox');
        $lame   = self::resolveBin('lame');

        if (empty($ffmpeg) && (empty($sox) || empty($lame))) {
            if (!self::$missingTranscoderLogged) {
                self::$missingTranscoderLogged = true;
                Util::sysLogMsg(
                    'MegafonPBX',
                    'audio recode skipped: neither ffmpeg nor sox+lame are available; '
                    . 'STT may reject 16 kbps recordings'
                );
            }
            return false;
        }

        $tmp = $path . '.recode.tmp.mp3';
        @unlink($tmp);

        $ok = !empty($ffmpeg)
            ? self::recodeWithFfmpeg($ffmpeg, $path, $tmp)
            : self::recodeWithSoxLame($sox, $lame, $path, $tmp);

        if (!$ok || !is_file($tmp) || filesize($tmp) === 0) {
            @unlink($tmp);
            return false;
        }

        // Атомарная замена. rename() в пределах одной FS — atomic POSIX.
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            Util::sysLogMsg('MegafonPBX', "audio recode: failed to replace $path");
            return false;
        }
        return true;
    }

    /**
     * Перекодирование через ffmpeg.
     */
    private static function recodeWithFfmpeg(string $ffmpeg, string $in, string $out): bool
    {
        // Путь к бинарю из Util::which() — доверенный (не пользовательский ввод),
        // экранируем только аргументы. escapeshellcmd() поверх escapeshellarg()
        // двойное экранирование, способное сломать пути со спецсимволами.
        $cmd = escapeshellarg($ffmpeg)
            . ' -y -loglevel error'
            . ' -i ' . escapeshellarg($in)
            . ' -ar ' . self::TARGET_SAMPLE_RATE
            . ' -ac 1'
            . ' -codec:a libmp3lame'
            . ' -b:a ' . self::TARGET_BITRATE_KBPS . 'k'
            . ' ' . escapeshellarg($out)
            . ' 2>&1';
        exec($cmd, $output, $rc);
        if ($rc !== 0) {
            Util::sysLogMsg('MegafonPBX', "ffmpeg recode failed (rc=$rc): " . implode(' | ', $output));
            return false;
        }
        return true;
    }

    /**
     * Перекодирование через sox→lame: sox декодирует MP3 в WAV (mono 8k),
     * lame жмёт обратно в MP3 32 kbps. Используем промежуточный wav в
     * /tmp вместо пайпа, чтобы не зависеть от proc_open и shell-фич:
     * exec() и popen() в php-cli могут вести себя по-разному на старых
     * сборках (на legacy-стенде PHP старый).
     */
    private static function recodeWithSoxLame(string $sox, string $lame, string $in, string $out): bool
    {
        $tmpWav = $in . '.recode.tmp.wav';
        @unlink($tmpWav);

        $cmd1 = escapeshellarg($sox)
            . ' ' . escapeshellarg($in)
            . ' -t wav -r ' . self::TARGET_SAMPLE_RATE . ' -c 1 -b 16'
            . ' ' . escapeshellarg($tmpWav)
            . ' 2>&1';
        exec($cmd1, $out1, $rc1);
        if ($rc1 !== 0 || !is_file($tmpWav) || filesize($tmpWav) === 0) {
            @unlink($tmpWav);
            Util::sysLogMsg('MegafonPBX', "sox decode failed (rc=$rc1): " . implode(' | ', $out1));
            return false;
        }

        $cmd2 = escapeshellarg($lame)
            . ' --quiet --cbr -b ' . self::TARGET_BITRATE_KBPS . ' -m m'
            . ' ' . escapeshellarg($tmpWav)
            . ' ' . escapeshellarg($out)
            . ' 2>&1';
        exec($cmd2, $out2, $rc2);
        @unlink($tmpWav);

        if ($rc2 !== 0) {
            Util::sysLogMsg('MegafonPBX', "lame encode failed (rc=$rc2): " . implode(' | ', $out2));
            return false;
        }
        return true;
    }
}
