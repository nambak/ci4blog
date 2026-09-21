<?php
/**
 * 애드센스 광고 스크립트. (#182)
 *
 * 이 파일을 include 하는 쪽이 "이 화면에는 게시자 콘텐츠가 있다" 고 보증하는
 * 것이다. 구글 게시자 정책은 콘텐츠가 없는 화면(오류 페이지, 결과가 0건인
 * 검색 화면 등)에 광고를 싣는 것을 금지한다.
 *
 * 그래서 호출 규약은 **기본값 꺼짐**이다 — layouts/default 와 home/index 는
 * $meta['ads'] 가 참일 때만 이 파일을 넣는다. 새 화면을 만들면 광고가 없는
 * 상태로 시작하고, 콘텐츠가 있다고 판단한 컨트롤러만 명시적으로 켠다.
 * 반대로 기본값이 켜짐이면 끄는 것을 잊는 실수가 계정 정지로 돌아온다.
 *
 * 게시자 ID 를 설정으로 빼지 않았다. ads.txt(public/ads.txt)에도 같은 값이
 * 박혀 있어 한쪽만 바꾸면 광고가 서빙되지 않는데, 설정으로 옮기면 그 사실이
 * 오히려 안 보인다. 계정을 바꾸는 날 둘을 함께 고치는 편이 안전하다.
 */
?>
<script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-pub-3760455502657641"
     crossorigin="anonymous"></script>
