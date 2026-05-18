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
# Простой end-to-end тест распознавания: один файл -> текст. Файл
# отправляется в STT КАК ЕСТЬ, без перекодирования — это позволяет
# проверить, корректно ли сервис принимает то, что лежит на диске
# (например, после `synchCdr.php` + `AudioRecodeHelper`). Если после
# импорта файл всё ещё 16 kbps mono — здесь это и проявится.
#
# Зависимости: curl.
#
# Запуск:
#   KEY=<api-key> bin/test-stt.sh <file.mp3>
#   URL=https://speech.mikolab.ru KEY=... bin/test-stt.sh /storage/.../file.mp3
#
# Exit-коды: 0 — распознано; 1 — ошибка / таймаут.

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

# POST async: MP3 принимается без указания Format — auto-detect по контейнеру.
REQ_ID="stt-test-$(date +%s)-$$"
RESP=$(curl -s -X POST "$URL/v1/stt/recognize" \
    -H "Authorization: Key $KEY" \
    -H "X-Request-ID: $REQ_ID" \
    -F "LanguageCode=$LANG_CODE" \
    -F "Model=$MODEL" \
    -F "file=@$FILE")
echo "POST: $RESP"
ID=$(echo "$RESP" | sed -n 's/.*"id":"\([^"]*\)".*/\1/p')
if [ -z "$ID" ]; then
    echo "ERROR: no task id in response" >&2
    exit 1
fi

# Поллинг
echo "Polling task $ID (timeout=${POLL_TIMEOUT_SEC}s, interval=${POLL_INTERVAL_SEC}s)..."
ELAPSED=0
while [ "$ELAPSED" -lt "$POLL_TIMEOUT_SEC" ]; do
    sleep "$POLL_INTERVAL_SEC"
    ELAPSED=$((ELAPSED + POLL_INTERVAL_SEC))
    R=$(curl -s "$URL/v1/stt/result/$ID" -H "Authorization: Key $KEY")
    if echo "$R" | grep -q '"done":true'; then
        echo "Done in ${ELAPSED}s:"
        echo "$R"
        exit 0
    fi
done
echo "ERROR: polling timeout after ${POLL_TIMEOUT_SEC}s, task $ID still pending" >&2
exit 1
