<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('/', 'Home::index');
$routes->get('about', 'Pages::about');
$routes->get('posts', 'Posts::index');

// 로그인(세션 인증)이 필요한 쓰기 라우트는 이 그룹 안에 둔다.
// 글 작성/수정/삭제 라우트가 ep12~ep15에서 여기에 채워진다.
$routes->group('', ['filter' => 'session'], static function ($routes) {
    // (ep12부터 추가)
});

$routes->get('posts/(:segment)', 'Posts::show/$1');

service('auth')->routes($routes);
