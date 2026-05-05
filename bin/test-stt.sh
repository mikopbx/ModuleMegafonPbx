#!/bin/sh
#
# MikoPBX - free phone system for small business
# Copyright © 2017-2026 Alexey Portnov and Nikolay Beketov
#
# This program is free software: you can redistribute it and/or modify
# it under the terms of the GNU General Public License as published by
# the Free Software Foundation; either version 3 of the License, or
# (at your option) any later version.
#
# This program is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
# GNU General Public License for more details.
#
# Простой end-to-end тест распознавания речи: один файл -> текст.
#
# Зачем: проверить, что записи МегаФон, скачанные модулем, корректно
# принимаются внешним STT-сервисом (`speech.mikolab.ru`). МегаФон отдаёт
# CBR mono 16 kbps, который async-парсер сервиса не принимает (`Unexpected
# EOF`). Скрипт повторяет ту же подготовку, что AudioRecodeHelper делает
# при импорте: ffmpeg/sox+lame в mono 32 kbps -> POST async -> поллинг.
#
# Запуск:
#   KEY=<api-key> bin/test-stt.sh <path-to-file.mp3>
#   URL=https://speech.mikolab.ru KEY=... bin/test-stt.sh /storage/.../file.mp3
#
# Для отладки `synchCdr.php`/`AudioRecodeHelper`: подайте на вход свежий
# файл из `cdr_general.recordingfile` с `from_account='fs-megapbx'`. Если
# результат — непустой `chunks[]` с `text`, цепочка работает.
#
# Зависимости: curl, ffprobe, ffmpeg ИЛИ sox+lame.
#
# Exit-коды: 0 — распознано; 1 — ошибка окружения / транспорта / таймаут.

set -u

URL="${URL:-https://speech.mikolab.ru}"
LANG_CODE="${LANG_CODE:-ru-RU}"
MODEL="${MODEL:-general}"
POLL_TIMEOUT_SEC="${POLL_TIMEOUT_SEC:-180}"
POLL_INTERVAL_SEC="${POLL_INTERVAL_SEC:-3}"

FILE="${1:-}"
if [ -z "${KEY:-}" ] || [ -z "$FILE" ]; then
    cat >&2 <<EOF
Usage: KEY=<api-key> $0 <file.mp3>

Env vars:
  KEY                 (required) STT API key
  URL                 STT base URL (default: https://speech.mikolab.ru)
  LANG_CODE           (default: ru-RU)
  MODEL               general | deferred-general (default: general)
  POLL_TIMEOUT_SEC    (default: 180)
  POLL_INTERVAL_SEC   (default: 3)
EOF
    exit 1
fi
if [ ! -f "$FILE" ]; then
    echo "ERROR: file not found: $FILE" >&2
    exit 1
fi

# ── 1. Probe + recode при необходимости ─────────────────────────────────
if ! command -v ffprobe >/dev/null 2>&1; then
    echo "ERROR: ffprobe not found in PATH" >&2
    exit 1
fi

BR=$(ffprobe -v error -of csv=p=0 -show_entries stream=bit_rate "$FILE" 2>/dev/null | head -1)
CH=$(ffprobe -v error -of csv=p=0 -show_entries stream=channels  "$FILE" 2>/dev/null | head -1)
echo "Source: bitrate=${BR:-?} channels=${CH:-?}"

SEND="$FILE"
TMP=""
# Порог 24 kbps — между 16k (МегаФон, ломается) и 32k (наш target). Файлы
# с битрейтом выше отправляем как есть, чтобы не делать lossy->lossy.
if [ "${BR:-0}" -lt 24000 ]; then
    TMP="/tmp/stt-test-$$.mp3"
    SEND="$TMP"
    echo "Recoding to mono 8k 32 kbps -> $SEND"
    if command -v ffmpeg >/dev/null 2>&1; then
        ffmpeg -y -loglevel error -i "$FILE" \
            -ar 8000 -ac "${CH:-1}" -codec:a libmp3lame -b:a 32k "$SEND"
    elif command -v sox >/dev/null 2>&1 && command -v lame >/dev/null 2>&1; then
        WAV="/tmp/stt-test-$$.wav"
        sox "$FILE" -t wav -r 8000 -c "${CH:-1}" -b 16 "$WAV" \
            && lame --quiet --cbr -b 32 -m m "$WAV" "$SEND"
        rm -f "$WAV"
    else
        echo "ERROR: need ffmpeg, or sox+lame, to recode" >&2
        exit 1
    fi
    if [ ! -s "$SEND" ]; then
        echo "ERROR: recode produced empty file" >&2
        rm -f "$SEND"
        exit 1
    fi
fi

# ── 2. POST async ────────────────────────────────────────────────────────
# MP3 принимается без указания Format — auto-detect по контейнеру.
REQ_ID="stt-test-$(date +%s)-$$"
RESP=$(curl -s -X POST "$URL/v1/stt/recognize" \
    -H "Authorization: Key $KEY" \
    -H "X-Request-ID: $REQ_ID" \
    -F "LanguageCode=$LANG_CODE" \
    -F "Model=$MODEL" \
    -F "file=@$SEND")
echo "POST: $RESP"
ID=$(echo "$RESP" | sed -n 's/.*"id":"\([^"]*\)".*/\1/p')
if [ -z "$ID" ]; then
    echo "ERROR: no task id in response" >&2
    [ -n "$TMP" ] && rm -f "$TMP"
    exit 1
fi

# ── 3. Поллинг ───────────────────────────────────────────────────────────
echo "Polling task $ID (timeout=${POLL_TIMEOUT_SEC}s, interval=${POLL_INTERVAL_SEC}s)..."
ELAPSED=0
RC=1
while [ "$ELAPSED" -lt "$POLL_TIMEOUT_SEC" ]; do
    sleep "$POLL_INTERVAL_SEC"
    ELAPSED=$((ELAPSED + POLL_INTERVAL_SEC))
    R=$(curl -s "$URL/v1/stt/result/$ID" -H "Authorization: Key $KEY")
    if echo "$R" | grep -q '"done":true'; then
        echo "Done in ${ELAPSED}s:"
        echo "$R"
        RC=0
        break
    fi
done
if [ "$RC" -ne 0 ]; then
    echo "ERROR: polling timeout after ${POLL_TIMEOUT_SEC}s, task $ID still pending" >&2
fi

[ -n "$TMP" ] && rm -f "$TMP"
exit "$RC"
