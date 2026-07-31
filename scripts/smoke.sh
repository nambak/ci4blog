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

echo "▶ smoke test: ${BASE_URL}"
