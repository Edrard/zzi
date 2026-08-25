#!/usr/bin/env bash

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
GPT_DIR="$ROOT/gpt"
REDIS_PORT=6399
LOCK_FILE="$GPT_DIR/phpunit-safe-redis.lock"
PID_FILE="$GPT_DIR/phpunit-redis.pid"
LOG_FILE="$GPT_DIR/phpunit-redis.log"
REDIS_STARTED=0
REDIS_PID=""

mkdir -p "$GPT_DIR"

cleanup() {
    if [[ "$REDIS_STARTED" -eq 1 && -n "$REDIS_PID" ]]; then
        if kill -0 "$REDIS_PID" 2>/dev/null; then
            kill "$REDIS_PID" 2>/dev/null || true

            for _ in $(seq 1 50); do
                if ! kill -0 "$REDIS_PID" 2>/dev/null; then
                    break
                fi
                sleep 0.1
            done

            if kill -0 "$REDIS_PID" 2>/dev/null; then
                kill -KILL "$REDIS_PID" 2>/dev/null || true
            fi
        fi
    fi

    rm -f "$PID_FILE" "$LOG_FILE"
}

trap cleanup EXIT INT TERM

cd "$ROOT" || {
    echo "ERROR: cannot cd to $ROOT" >&2
    exit 1
}

if [[ -e bootstrap/cache/config.php ]]; then
    echo "Tests aborted: bootstrap/cache/config.php exists." >&2
    echo "Use the protected config-cache backup/remove/restore protocol before running tests." >&2
    exit 1
fi

if ! command -v redis-server >/dev/null 2>&1; then
    echo "Tests aborted: redis-server is not installed." >&2
    exit 1
fi

if ! command -v php84 >/dev/null 2>&1; then
    echo "Tests aborted: php84 is not available." >&2
    exit 1
fi

exec 9>"$LOCK_FILE"
if ! flock -n 9; then
    echo "Tests aborted: another isolated PHPUnit Redis runner already owns $LOCK_FILE." >&2
    exit 1
fi

if ss -ltn 2>/dev/null | awk '{print $4}' | grep -Eq '(^|:)6399$'; then
    echo "Tests aborted: TCP port 6399 is already in use." >&2
    echo "The safe runner never reuses a pre-existing listener." >&2
    exit 1
fi

rm -f "$PID_FILE" "$LOG_FILE"

redis-server \
    --bind 127.0.0.1 \
    --protected-mode yes \
    --port "$REDIS_PORT" \
    --save "" \
    --appendonly no \
    --daemonize yes \
    --pidfile "$PID_FILE" \
    --logfile "$LOG_FILE" \
    --dir "$GPT_DIR"

for _ in $(seq 1 50); do
    if [[ -s "$PID_FILE" ]]; then
        REDIS_PID="$(cat "$PID_FILE" 2>/dev/null || true)"

        if [[ "$REDIS_PID" =~ ^[0-9]+$ ]] \
            && kill -0 "$REDIS_PID" 2>/dev/null \
            && ss -ltn 2>/dev/null | awk '{print $4}' | grep -Eq '(^|:)6399$'
        then
            REDIS_STARTED=1
            break
        fi
    fi

    sleep 0.1
done

if [[ "$REDIS_STARTED" -ne 1 ]]; then
    echo "Tests aborted: isolated Redis failed to start on 127.0.0.1:6399." >&2
    echo "Redis log: $LOG_FILE" >&2
    exit 1
fi

export APP_ENV=testing
export PHPUNIT_REDIS_ISOLATED=1

export REDIS_CLIENT=phpredis
export REDIS_HOST=127.0.0.1
export REDIS_PORT=6399
export REDIS_USERNAME=
export REDIS_PASSWORD=
export REDIS_URL=
export REDIS_DB=0
export REDIS_CACHE_DB=1
export REDIS_INLINE_IMAGE_DB=2
export REDIS_PREFIX=work_phpunit_

export CACHE_PREFIX=work_phpunit_cache_
export ZNUNY_INLINE_IMAGE_CACHE_STORE=array

php84 vendor/bin/phpunit "$@"
PHPUNIT_RC=$?

exit "$PHPUNIT_RC"
