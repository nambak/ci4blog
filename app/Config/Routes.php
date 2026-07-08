<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('/', 'Home::index');
$routes->get('about', 'Pages::about');
$routes->get('posts', 'Posts::index');
// 카테고리별 글 목록. 목록 화면(index)을 슬러그로 거른다.
$routes->get('categories/(:segment)', 'Posts::index/$1');
// 업로드 이미지 서빙(writable/uploads 는 웹 루트 밖이라 컨트롤러로 내보낸다).
$routes->get('uploads/(:segment)', 'Posts::image/$1');

// 로그인(세션 인증)이 필요한 쓰기 라우트는 이 그룹 안에 둔다.
// 글 작성/수정/삭제 라우트가 ep12~ep15에서 여기에 채워진다.
$routes->group('', ['filter' => 'session'], static function ($routes) {
    $routes->get('posts/new', 'Posts::new');            // 글 작성 폼
    $routes->post('posts', 'Posts::create');            // 글 저장
    $routes->get('posts/(:num)/edit', 'Posts::edit/$1');     // 글 수정 폼
    $routes->post('posts/(:num)', 'Posts::update/$1');       // 글 수정 저장
    $routes->post('posts/(:num)/delete', 'Posts::delete/$1'); // 글 삭제
    $routes->post('posts/(:num)/comments', 'Comments::store/$1'); // 댓글 저장
    $routes->post('comments/(:num)/delete', 'Comments::delete/$1'); // 댓글 삭제
});

// 글 상세는 slug 기반(:segment). 위의 (:num) 쓰기 라우트보다 아래에 둬
// 'posts/5' 같은 숫자 경로가 먼저 매칭되도록 한다.
$routes->get('posts/(:segment)', 'Posts::show/$1');

$routes->group('admin', ['filter' => 'group:admin,superadmin'], static function ($routes) {
    $routes->get('/', 'Admin::index'); // 관리자 대시보드

    $routes->get('categories', 'Admin\Categories::index');                 // 목록 + 추가 폼
    $routes->post('categories', 'Admin\Categories::create');               // 생성
    $routes->get('categories/(:num)/edit', 'Admin\Categories::edit/$1');   // 수정 폼
    $routes->post('categories/(:num)', 'Admin\Categories::update/$1');     // 수정 저장
    $routes->post('categories/(:num)/delete', 'Admin\Categories::delete/$1'); // 삭제
});

// 공개 회원가입은 막는다(관리자만 shield:user create 로 계정 생성).
service('auth')->routes($routes, ['except' => ['register']]);
