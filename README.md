# ci4blog

CodeIgniter 4 프레임워크로 블로그를 **처음부터** 만들어 보는 학습용 프로젝트입니다.
정적 페이지와 라우팅부터 시작해 글 CRUD, 인증(Shield), 댓글, 카테고리·검색, 마크다운·이미지, 그리고 production 배포까지 한 흐름으로 다룹니다.

> 강좌는 **회차(ep) = 하나의 커밋 + 하나의 태그** 단위로 진행됩니다.
> 전체 회차별 작업 목록은 [`docs/curriculum.md`](docs/curriculum.md)에 정리되어 있습니다.

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
