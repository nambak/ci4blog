<?php

/**
 * CI4 예외 핸들러가 include 하는 404 어댑터. (#114)
 *
 * 여기서 디자인을 하지 않는다. 예외 핸들러는 이 파일을 컨트롤러 없이 평범한
 * include 로 넣기 때문에 $this 가 View 인스턴스가 아니고, 그래서 레이아웃을
 * 상속하는 $this->extend(...) 를 이 파일에서는 쓸 수 없다
 * (vendor/.../Debug/BaseExceptionHandler.php:264-272).
 *
 * view() 헬퍼를 거치면 정식 View 서비스로 들어가므로 레이아웃 상속이 동작한다.
 * 이 파일의 역할은 그 호출 하나뿐이다.
 *
 * $meta 를 반드시 명시 전달한다 — view() 는 데이터를 누적하고 renderer 는
 * 공유되므로, 넘기지 않으면 앞 렌더가 남긴 $meta 가 layouts/default.php:20 의
 * `?? []` 를 통과해 엉뚱한 글 제목이 이 페이지의 og:title 로 새어 나간다.
 *
 * @var string $message BaseExceptionHandler::collectVars() 가 넣어 주는 예외 메시지
 */

echo view('errors/not_found', [
    'meta'    => ['title' => '페이지를 찾을 수 없습니다'],
    'message' => $message ?? '',
]);
