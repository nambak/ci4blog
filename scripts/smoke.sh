#!/usr/bin/env bash
#
# 배포 후 smoke test — 공인 도메인으로 대표 경로가 살아 있는지 확인한다.
#
# 이 스크립트는 "GitHub Actions 러너에서" 실행한다(서버가 아니다).
# 공인 도메인으로 요청해 DNS·TLS·Nginx·PHP 전체 경로를 사용자와 같은 시선으로 본다.
#
# 사용:
#   ./scripts/smoke.sh https://blog.unwanted.me
#   SMOKE_RETRIES=1 SMOKE_DELAY=0 ./scripts/smoke.sh http://ci4blog.test
#
# 종료코드: 0 전부 통과 / 1 하나 이상 실패 / 2 인자 오류
#
set -euo pipefail

# ${#body} 가 로케일에 따라 문자 수가 되어 UTF-8 본문에서 바이트 수와 어긋난다.
export LC_ALL=C

BASE_URL="${1:-}"
if [[ -z "$BASE_URL" ]]; then
  echo "usage: $0 <base-url>" >&2
  echo "  예: $0 https://blog.unwanted.me" >&2
  exit 2
fi

# 뒤 슬래시를 떼서 경로를 붙일 때 '//' 가 되지 않게 한다.
BASE_URL="${BASE_URL%/}"

# 배포 직후에는 PHP-FPM 워밍업·캐시 정리 직후 첫 요청으로 순간 실패가 날 수 있다.
# 재시도가 없으면 멀쩡한 배포가 빨간색이 되고, 그런 위양성이 반복되면
# 이 검증 자체를 아무도 믿지 않게 된다.
RETRIES="${SMOKE_RETRIES:-3}"
DELAY="${SMOKE_DELAY:-3}"
TIMEOUT="${SMOKE_TIMEOUT:-10}"

BODY_FILE="$(mktemp)"
trap 'rm -f "$BODY_FILE"' EXIT

checked=0
failed=0
# 배열이 아니라 문자열로 모은다 — macOS 기본 bash(3.2)는 `set -u` 에서
# 빈 배열 확장 "${arr[@]}" 를 unbound variable 로 보고 죽는다.
failed_paths=""

# body_matches <본문> <있어야 할 문자열…>
#   인자가 없으면 "비어 있지 않음"만 확인한다.
body_matches() {
  local body="$1"
  shift

  if [[ $# -eq 0 ]]; then
    [[ -n "$body" ]]
    return
  fi

  local needle
  for needle in "$@"; do
    [[ "$body" == *"$needle"* ]] || return 1
  done
}

# check <경로> <Accept> <본문에 있어야 할 문자열…>
check() {
  local path="$1" accept="$2"
  shift 2

  local url="${BASE_URL}${path}"
  local attempt=1 status body

  checked=$(( checked + 1 ))

  while (( attempt <= RETRIES )); do
    status="$(curl -s -o "$BODY_FILE" -w '%{http_code}' \
      -H "Accept: ${accept}" --max-time "$TIMEOUT" "$url" 2>/dev/null || echo '000')"
    body="$(cat "$BODY_FILE" 2>/dev/null || true)"

    if [[ "$status" == "200" ]] && body_matches "$body" "$@"; then
      printf '  ✓ %-13s %s  (%s bytes)\n' "$path" "$status" "${#body}"
      return 0
    fi

    attempt=$(( attempt + 1 ))
    if (( attempt <= RETRIES )); then
      sleep "$DELAY"
    fi
  done

  printf '  ✗ %-13s %s\n' "$path" "$status"
  failed=$(( failed + 1 ))
  failed_paths="${failed_paths}${failed_paths:+, }${path}"

  # 여기서 죽지 않는다 — 나머지 경로도 검사해야 원인 범위를 알 수 있다.
  return 0
}

echo "▶ smoke test: ${BASE_URL}"

# production ExceptionHandler 는 Accept 에 text/html 이 없으면 JSON 으로 응답하고
# 본문을 비운다(#124 실측). 실제 브라우저와 같은 조건으로 봐야 판정이 정확하다.
check /health      'application/json' '"status":"ok"' '"db":"ok"'
check /            'text/html'
check /posts       'text/html'
check /sitemap.xml 'application/xml'  '<urlset'
check /feed        'application/rss+xml' '<rss'

echo

if (( failed > 0 )); then
  echo "❌ smoke test 실패: ${failed}/${checked} (${failed_paths})"
  exit 1
fi

echo "✅ smoke test 통과: ${checked}/${checked}"
