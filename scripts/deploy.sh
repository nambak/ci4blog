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

echo "▶ 1/7 최신 코드 받기"
git fetch --all --prune
git checkout main          # 배포 브랜치 (필요시 변경)
git pull --ff-only origin main

echo "▶ 2/7 의존성 설치 (production)"
composer install --no-dev --optimize-autoloader --no-interaction

echo "▶ 3/7 DB 백업 (마이그레이션 전 스냅샷)"
# 마이그레이션이 데이터를 망가뜨렸을 때 되돌릴 파일을 먼저 만든다.
# 실패하면 set -e 로 여기서 배포가 멈춘다 — 백업 없이 migrate 하지 않는다.
# (SQLite 가 아닌 구성에서는 커맨드가 이유를 알리고 건너뛴다.)
sudo -u www-data php spark db:backup

echo "▶ 4/7 DB 마이그레이션"
# spark 는 www-data 로 실행한다. writable/ 이 php-fpm(www-data) 소유라
# ubuntu 로 돌리면 로그·캐시·SQLite 쓰기 권한이 없어 부팅부터 실패한다.
sudo -u www-data php spark migrate --all

echo "▶ 5/7 강의 글 발행 (slug 기준 멱등 upsert)"
# 작성자 계정을 고정하려면 --author=<user_id> 를 붙인다(예: 강의용 관리자 id).
sudo -u www-data php spark posts:import

echo "▶ 6/7 캐시 정리"
sudo -u www-data php spark cache:clear || true

echo "▶ 7/7 writable 권한 보정 (php-fpm www-data 소유 유지)"
# spark 를 www-data 로 실행하므로 보통 이미 www-data 소유지만,
# 혹시 남은 ubuntu 소유 파일이 있으면 보정하는 안전망이다.
sudo chown -R www-data:www-data writable/

echo "✅ 배포 완료"
