# ci4blog

CodeIgniter 4 프레임워크로 블로그를 **처음부터** 만들어 보는 학습용 프로젝트입니다.
정적 페이지와 라우팅부터 시작해 글 CRUD, 인증(Shield), 댓글, 카테고리·검색, 마크다운·이미지, 그리고 production 배포까지 한 흐름으로 다룹니다.

> 강좌는 **회차(ep) = 하나의 커밋 + 하나의 태그** 단위로 진행됩니다.
> 전체 회차별 작업 목록은 [`docs/curriculum.md`](docs/curriculum.md)에 정리되어 있습니다.

> [!NOTE]
> **강좌 완성본 = `ep01`~`ep30` 태그 / 실제 운영 코드 = `main` 최신 커밋**
> 강좌 30회차는 각 태그로 동결되어 있습니다(예: `git checkout ep07`). `main`은 강좌 완성(ep30) 이후 실제 블로그 운영을 위해 계속 업데이트되므로, 회차별 학습 내용을 보려면 태그를 체크아웃하세요.

## 학습 목표

- 라우트 → 컨트롤러 → 뷰로 이어지는 CI4의 기본 요청 흐름 이해
- 마이그레이션·시더·Model·Entity로 데이터 계층 구성
- Feature 테스트를 활용한 "실패 → 구현 → 통과" 개발 사이클
- 공식 인증 라이브러리 **Shield**로 로그인·권한·가드 구현
- 검증·CSRF·플래시 메시지를 갖춘 안전한 CRUD
- 마크다운 렌더링, 이미지 업로드, 그리고 production 배포까지

## 기술 스택

- **PHP** 8.1 이상
- **CodeIgniter** 4 (appstarter 기본 구조)
- **Composer** (의존성 관리)
- **Shield** — CodeIgniter 공식 인증 라이브러리
- **PHPUnit** — Feature/Unit 테스트
- 데이터베이스: MySQL/MariaDB 또는 SQLite (테스트는 SQLite 메모리 권장)

## 시작하기

### 사전 준비물

- PHP 8.1 이상 (`intl`, `mbstring` 등 CI4 권장 확장 포함)
- Composer
- 데이터베이스 (MySQL/MariaDB 또는 SQLite)

### 설치

```bash
# 1) 저장소 클론
git clone <repository-url> ci4blog
cd ci4blog

# 2) 의존성 설치
composer install

# 3) 환경 설정
cp env .env
# .env 편집: CI_ENVIRONMENT = development, app.baseURL, database.default.* 설정

# 4) 마이그레이션 & 시더
php spark migrate
php spark db:seed DatabaseSeeder

# 5) 개발 서버 실행
php spark serve
```

브라우저에서 `http://localhost:8080` 으로 접속합니다.

### 테스트 실행

```bash
composer test
# 또는
./vendor/bin/phpunit
```

## 배포 (production)

운영 환경은 개발과 두 가지가 다릅니다 — **디버그를 끄고**(`CI_ENVIRONMENT = production`), **설정은 `.env` 로 주입**합니다. 코드(`app/Config/*`)의 기본값은 그대로 두고, 서버의 `.env` 가 그 위를 덮습니다.

```bash
# 1) 코드 받기 & 운영용 의존성만 설치(dev 패키지 제외)
git pull --ff-only origin main
composer install --no-dev --optimize-autoloader

# 2) 운영 .env 작성
cp env.production.example .env
#    app.baseURL = 'https://내도메인/'
#    database.default.* = 운영 DB 접속 정보(비밀번호는 .env 에만)
php spark key:generate          # encryption.key 생성

# 3) 마이그레이션
php spark migrate --all

# 4) 캐시 정리(설정/라우트 변경 후)
php spark cache:clear
```

### 체크리스트

- [ ] `.env` 의 `CI_ENVIRONMENT = production` — 디버그 툴바·상세 에러 페이지가 꺼졌는지 확인
- [ ] `app.baseURL` 이 실제 도메인(끝에 `/`)이고 HTTPS 인지
- [ ] `app.forceGlobalSecureRequests = true` (HTTPS 강제), `app.indexPage = ''` (깨끗한 URL)
- [ ] `encryption.key` 가 채워졌는지(`php spark key:generate`)
- [ ] 웹 서버의 **document root 가 `public/`** 인지 (그 위 디렉터리가 공개되면 안 됨)
- [ ] `.env` 와 운영 비밀값이 저장소·CI 에 올라가지 않는지

### writable/ 권한

`writable/` 아래(`cache`, `logs`, `session`, `uploads`)는 **웹 서버가 쓸 수 있어야** 합니다. 소유자를 웹 서버 사용자로 두거나 그룹 쓰기를 허용합니다.

```bash
# 예: 웹 서버가 www-data 인 경우
sudo chown -R www-data:www-data writable/
sudo find writable/ -type d -exec chmod 775 {} \;
sudo find writable/ -type f -exec chmod 664 {} \;
```

> 업로드 이미지는 웹 루트 밖(`writable/uploads/`)에 저장되고 `Posts::image` 컨트롤러로 서빙되므로, `writable/` 가 외부에서 직접 접근되지 않습니다.

## 프로젝트 구조

```text
ci4blog/
├─ app/
│  ├─ Config/         # 라우트, 필터, DB, Auth 설정
│  ├─ Controllers/    # Pages, Posts, Comments
│  ├─ Models/         # PostModel, CommentModel, CategoryModel
│  ├─ Entities/       # Post, Comment, Category
│  ├─ Database/       # Migrations, Seeds
│  └─ Views/          # layouts, partials, pages, posts, comments
├─ tests/Feature/     # 엔드포인트 단위 Feature 테스트
├─ writable/          # 캐시·로그·업로드
├─ docs/
│  └─ curriculum.md   # 전체 회차별 커밋 빌드 가이드
└─ public/            # 웹 루트 (index.php)
```

## 커리큘럼 개요

전체 **30회차 · 8개 섹션**으로 구성됩니다. 회차별 상세 작업 목록은 [`docs/curriculum.md`](docs/curriculum.md)를 참고하세요.

| 섹션 | 주제 | 회차 | 핵심 내용 |
| --- | --- | --- | --- |
| 1 | 스택, 세팅, 구조 | ep01 – ep04 | 프로젝트 생성, 라우팅, 공통 레이아웃, 첫 테스트 |
| 2 | 글 읽기의 기초 | ep05 – ep09 | 마이그레이션·시더, Model/Entity, 목록·페이지네이션·상세 |
| 3 | 인증 (Shield) | ep10 – ep11 | Shield 도입, 인증 필터와 현재 사용자 분기 |
| 4 | 글 작성 CRUD | ep12 – ep17 | 검증·CSRF, 작성·수정·삭제, 플래시, slug URL |
| 5 | 댓글 | ep18 – ep21 | 댓글 표시·저장·삭제, 인증 가드 |
| 6 | 분류와 검색 | ep22 – ep25 | 카테고리 구조·필터, 기본 검색, 상태 유지 |
| 7 | 콘텐츠 풍부하게 | ep26 – ep27 | 마크다운 렌더링, 이미지 업로드·썸네일 |
| 8 | 운영과 유지보수 | ep28 – ep30 | 리팩터링, 버전 업그레이드, production 배포 |

## 커밋 & 태그 규칙

- 한 회차의 파일을 모두 작업한 뒤 **한 번에 커밋**하고 태그를 답니다.
  ```bash
  git commit -m "feat: PostModel/Entity와 글 목록"
  git tag ep07
  ```
- 테스트 회차는 "실패 → 구현 → 통과"를 같은 커밋에 담되, 녹화는 빨강 → 초록 순서로 보여줍니다.
- 커밋 메시지는 `feat:`, `fix:`, `chore:`, `refactor:`, `test:` 접두사를 사용합니다.

## 참고 자료

- [CodeIgniter 4 공식 문서](https://codeigniter.com/user_guide/)
- [CodeIgniter Shield 문서](https://shield.codeigniter.com/)

## 라이선스

학습 목적의 프로젝트입니다. CodeIgniter 4 프레임워크는 MIT 라이선스를 따릅니다.
