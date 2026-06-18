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

echo "▶ 1/5 최신 코드 받기"
git fetch --all --prune
git checkout main          # 배포 브랜치 (필요시 변경)
git pull --ff-only origin main

echo "▶ 2/5 의존성 설치 (production)"
composer install --no-dev --optimize-autoloader --no-interaction

echo "▶ 3/5 DB 마이그레이션"
php spark migrate --all

echo "▶ 4/5 강의 글 발행 (slug 기준 멱등 upsert)"
# 작성자 계정을 고정하려면 --author=<user_id> 를 붙인다(예: 강의용 관리자 id).
php spark posts:import

echo "▶ 5/5 캐시 정리"
php spark cache:clear || true

echo "✅ 배포 완료"
