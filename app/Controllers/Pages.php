<?php

namespace App\Controllers;

class Pages extends BaseController
{
    public function about(): string
    {
        return view('pages/about', [
            'meta' => [
                'title' => '소개',
                // 목록 화면과 같은 이유로 자기 설명을 갖는다(#GSC 색인) —
                // 사이트 기본 문구를 그대로 쓰면 다른 페이지와 구별되지 않는다.
                'description' => '이 블로그가 무엇을 기록하는 곳인지, 어떤 순서로 읽으면 좋은지 정리한 소개 페이지입니다.',
            ],
        ]);
    }
}
