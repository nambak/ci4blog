<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('/', 'Home::index');
$routes->get('about', 'Pages::about');
$routes->get('health', 'Health::index');   // 헬스체크(#112) — 공개, session 그룹 밖
// 검색엔진용 사이트맵(#124) — 공개, session 그룹 밖. 점을 이스케이프하는 이유는
// CI4 가 라우트 문자열을 정규식에 그대로 넣어(Router::handle) '.' 이 임의 문자가 되기 때문.
$routes->get('sitemap\.xml', 'Sitemap::index');
// 구독자용 RSS 2.0 피드(#113) — 공개, session 그룹 밖. 리더는 로그인하지 않는다.
$routes->get('feed', 'Feed::index');
$routes->get('posts', 'Posts::index');
// 카테고리별 글 목록. 목록 화면(index)을 슬러그로 거른다.
$routes->get('categories/(:segment)', 'Posts::index/$1');
// 태그 목록(#114). 카테고리와 같은 자리에 둔다 — 둘 다 공개 글 목록의 필터다.
$routes->get('tags/(:segment)', 'Posts::byTag/$1');
// 업로드 이미지 서빙(writable/uploads 는 웹 루트 밖이라 컨트롤러로 내보낸다).
$routes->get('uploads/(:segment)', 'Uploads::show/$1');

// 발행 API. 원고 파일을 posts 테이블에 반영하는 창구다(#발행API).
//
// 인증은 Shield 액세스 토큰(tokens) + 관리자 그룹(api-admin) 두 겹이다.
// 토큰만으로 통과시키면 일반 회원 토큰으로도 글이 올라간다.
//
// Shield 의 group 필터를 쓰지 않는 이유는 App\Filters\ApiAdmin 주석에 있다 —
// 그 필터는 세션 인증자를 보기 때문에 토큰 요청을 로그인 페이지로 보낸다.
//
// session 그룹 밖에 둔다: 스크립트는 쿠키 세션을 갖지 않는다.
// CSRF 는 Config\Filters 에서 api/* 를 제외했다(토큰 인증이 대신 막는다).
$routes->group('api', ['filter' => ['tokens', 'api-admin']], static function ($routes) {
    $routes->post('posts', 'Api\Posts::upsert');          // slug 기준 upsert
    $routes->get('posts/(:segment)', 'Api\Posts::show/$1'); // 발행 상태 확인
    $routes->post('uploads', 'Api\Uploads::store');        // 대표 이미지 업로드
});

// 로그인(세션 인증)이 필요한 쓰기 라우트는 이 그룹 안에 둔다.
// 글 작성/수정/삭제 라우트가 ep12~ep15에서 여기에 채워진다.
$routes->group('', ['filter' => 'session'], static function ($routes) {
    $routes->get('posts/new', 'Posts::new');            // 글 작성 폼
    $routes->post('posts', 'Posts::create');            // 글 저장
    $routes->get('posts/(:num)/edit', 'Posts::edit/$1');     // 글 수정 폼
    $routes->post('posts/(:num)', 'Posts::update/$1');       // 글 수정 저장
    $routes->post('posts/(:num)/delete', 'Posts::delete/$1'); // 글 삭제
    $routes->post('posts/(:num)/like', 'Posts::like/$1', ['filter' => 'throttle:like']);     // 좋아요 토글
    $routes->post('posts/(:num)/comments', 'Comments::store/$1', ['filter' => 'throttle:comment']); // 댓글 저장
    $routes->post('comments/(:num)/delete', 'Comments::delete/$1'); // 댓글 삭제
    $routes->post('comments/(:num)/report', 'Comments::report/$1', ['filter' => 'throttle:report']); // 댓글 신고
    $routes->post('comments/(:num)/like', 'Comments::like/$1', ['filter' => 'throttle:like']);       // 댓글 좋아요 토글

    $routes->get('profile', 'Profile::edit');                       // 프로필 수정 폼
    $routes->post('profile', 'Profile::update');                    // 프로필 저장(사용자명·비번·아바타)
    $routes->post('profile/avatar/delete', 'Profile::deleteAvatar'); // 아바타 삭제
});

// 글 상세는 slug 기반(:segment). 위의 (:num) 쓰기 라우트보다 아래에 둬
// 'posts/5' 같은 숫자 경로가 먼저 매칭되도록 한다.
$routes->get('posts/(:segment)', 'Posts::show/$1');

$routes->group('admin', ['filter' => 'group:admin,superadmin'], static function ($routes) {
    $routes->get('/', 'Admin::index'); // 관리자 대시보드

    $routes->get('posts', 'Admin\Posts::index');   // 게시글 관리 목록
    $routes->post('posts/bulk', 'Admin\Posts::bulk'); // 일괄 작업(발행·임시저장·비공개·이동·삭제)

    $routes->get('comments', 'Admin\Comments::index');       // 댓글 관리 목록
    $routes->post('comments/bulk', 'Admin\Comments::bulk');  // 일괄 작업(숨김·복원·삭제)
    $routes->post('comments/(:num)/reply', 'Admin\Comments::reply/$1'); // 빠른 답글

    $routes->get('categories', 'Admin\Categories::index');                 // 목록 + 추가 폼
    $routes->post('categories', 'Admin\Categories::create');               // 생성
    $routes->get('categories/(:num)/edit', 'Admin\Categories::edit/$1');   // 수정 폼
    $routes->post('categories/(:num)', 'Admin\Categories::update/$1');     // 수정 저장
    $routes->post('categories/(:num)/delete', 'Admin\Categories::delete/$1'); // 삭제
    $routes->post('categories/(:num)/visibility', 'Admin\Categories::toggleVisibility/$1'); // 공개/숨김 토글
});

// 공개 회원가입은 막는다(관리자만 shield:user create 로 계정 생성).
service('auth')->routes($routes, ['except' => ['register']]);
