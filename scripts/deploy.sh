#!/usr/bin/env bash
#
# 강의 글 배포 스크립트 (호스팅 무관 템플릿)
#
# 동작: 최신 코드를 받아 의존성·마이그레이션을 적용하고,
#       승인되어 커밋된 content/posts/*.md 를 posts 테이블에 반영한다.
#
# 전제:
#  - 이 스크립트는 "서버에서" 실행한다(SSH 접속 후 직접, 또는 배포 훅/Actions 가 SSH 로).
#  - DB 자격증명은 서버의 .env 에만 둔다(깃·CI 에 넣지 않는다).
#  - 서버 .env 는 CI_ENVIRONMENT=production, app.baseURL, database.default.* 가 설정돼 있어야 한다.
#
# 사용:
#   ./scripts/deploy.sh
#
set -euo pipefail

# 프로젝트 루트로 이동 (스크립트 위치 기준)
cd "$(dirname "$0")/.."

echo "▶ 1/10 최신 코드 받기"
git fetch --all --prune
git checkout main          # 배포 브랜치 (필요시 변경)
git pull --ff-only origin main

echo "▶ 2/10 의존성 설치 (production)"
composer install --no-dev --optimize-autoloader --no-interaction

echo "▶ 3/10 DB 백업 (마이그레이션 전 스냅샷)"
# 마이그레이션이 데이터를 망가뜨렸을 때 되돌릴 파일을 먼저 만든다.
# 실패하면 set -e 로 여기서 배포가 멈춘다 — 백업 없이 migrate 하지 않는다.
# (SQLite 가 아닌 구성에서는 커맨드가 이유를 알리고 건너뛴다.)
sudo -u www-data php spark db:backup

echo "▶ 4/10 DB 마이그레이션"
# spark 는 www-data 로 실행한다. writable/ 이 php-fpm(www-data) 소유라
# ubuntu 로 돌리면 로그·캐시·SQLite 쓰기 권한이 없어 부팅부터 실패한다.
sudo -u www-data php spark migrate --all

echo "▶ 5/10 강의 글 발행 (slug 기준 멱등 upsert)"
# 작성자 계정을 고정하려면 --author=<user_id> 를 붙인다(예: 강의용 관리자 id).
sudo -u www-data php spark posts:import

echo "▶ 6/10 캐시 정리"
sudo -u www-data php spark cache:clear || true

echo "▶ 7/10 오래된 로그 정리 (기본 30일 보관)"
# 실패해도 배포를 막지 않는다 — 로그 정리는 서비스 동작과 무관한 하우스키핑이다.
# (백업은 반대다: 3/10 이 실패하면 set -e 로 배포가 멈춘다.)
sudo -u www-data php spark logs:prune --force \
  || echo "⚠ 로그 정리 실패 — 배포는 계속합니다."

echo "▶ 8/10 만료 세션 정리"
# CSRF 필터가 요청마다 세션을 만들던 것은 App\Filters\Csrf 로 막았지만(#179),
# 이미 쌓인 것과 정상 세션의 만료분은 누군가 지워야 한다 — Ubuntu 의 PHP 는
# session.gc_probability 가 0 이고, 배포판 cron 은 CI4 의 savePath 를 모른다.
# 로그 정리와 같이 실패해도 배포를 막지 않는다.
sudo -u www-data php spark session:prune --force \
  || echo "⚠ 세션 정리 실패 — 배포는 계속합니다."

echo "▶ 9/10 보관 기간 넘긴 로그인 기록 파기 (기본 90일 보관)"
# Shield 는 로그인 시도마다 ip_address·user_agent 를 남긴다. 개인정보라 목적을
# 다하면 파기해야 하고, 그 기간을 개인정보처리방침에 적어 두었다(#184).
#
# 파괴적 삭제를 배포가 자동 실행하는 것은 db:prune 주석의 원칙과 어긋나 보이지만,
# 대상이 다르다 — 고아 행은 만료 정책이 없는 실데이터고 이것은 보관 기간이
# 명시된 로그성 개인정보다(logs:prune 이 같은 선을 그었다). 무엇보다 수동으로
# 두면 아무도 안 돌려서, 방침이 약속한 파기를 실제로는 안 하는 상태가 된다.
# 3/10 의 db:backup 이 이미 스냅샷을 남겨 두었다.
#
# 로그·세션 정리와 같이 실패해도 배포를 막지 않는다.
sudo -u www-data php spark auth:prune --force \
  || echo "⚠ 로그인 기록 정리 실패 — 배포는 계속합니다."

echo "▶ 10/10 writable 권한 보정 (php-fpm www-data 소유 유지)"
# spark 를 www-data 로 실행하므로 보통 이미 www-data 소유지만,
# 혹시 남은 ubuntu 소유 파일이 있으면 보정하는 안전망이다.
sudo chown -R www-data:www-data writable/

echo "✅ 배포 완료"
