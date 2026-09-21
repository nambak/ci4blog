<?php

namespace App\Controllers;

class Pages extends BaseController
{
    public function about(): string
    {
        return view('pages/about', [
            'meta' => [
                'title' => '소개',
                // 손으로 쓴 소개 글이 있는 화면이다(#182).
                'ads'   => true,
                // 목록 화면과 같은 이유로 자기 설명을 갖는다(#GSC 색인) —
                // 사이트 기본 문구를 그대로 쓰면 다른 페이지와 구별되지 않는다.
                'description' => '이 블로그가 무엇을 기록하는 곳인지, 어떤 순서로 읽으면 좋은지 정리한 소개 페이지입니다.',
            ],
        ]);
    }

    /**
     * 개인정보처리방침. (#182)
     *
     * 애드센스는 광고를 싣는 사이트에 이 문서를 요구한다. 제3자(구글)가 쿠키를
     * 쓴다는 사실과 이용자가 그것을 끌 수 있는 경로가 반드시 들어가야 한다.
     * 국내 개인정보보호법의 처리방침 공개·보호책임자 명시 요구도 함께 만족한다.
     *
     * 'ads' 를 넘기지 않는다 — 고지 문서에는 광고를 싣지 않는다.
     */
    public function privacy(): string
    {
        return view('pages/privacy', [
            'meta' => [
                'title'       => '개인정보처리방침',
                'description' => '이 블로그가 수집하는 개인정보, 쿠키와 제3자 광고 이용, 보관 기간과 이용자의 권리를 안내합니다.',
            ],
        ]);
    }
}
