<?php

/**
 * 구조화 데이터(JSON-LD). (#GSC 색인)
 *
 * 컨트롤러가 $meta['jsonld'] 로 넘긴 배열을 그대로 직렬화한다. 배열을 만드는 일은
 * 컨트롤러 몫이다 — 무엇이 글이고 무엇이 목록인지는 도메인 지식이라 뷰가 알 바가
 * 아니다. 여기서는 "안전하게 내보내기" 만 책임진다.
 *
 * 플래그 셋이 다 이유가 있다.
 *
 *   JSON_HEX_TAG          '<' 와 '>' 를 < / > 로 바꾼다. 이것이 없으면
 *                         제목에 들어간 </script> 가 스크립트를 그 자리에서 끊는다.
 *                         JSON 문법상으로는 멀쩡해도 브라우저는 거기서 잘라 읽는다.
 *   JSON_UNESCAPED_UNICODE 한글을 \uXXXX 로 부풀리지 않는다. 크롤러가 읽는 값이
 *                         원문 그대로여야 제목 대조가 쉽다.
 *   JSON_UNESCAPED_SLASHES URL 의 / 가 \/ 로 나가지 않게 한다. '<' 는 위에서 막으므로
 *                         이 조합이 위험하지 않다.
 *
 * esc() 를 쓰지 않는 것도 의도다. 여기는 HTML 이 아니라 JSON 컨텍스트라,
 * esc() 를 태우면 &quot; 같은 엔티티가 값 안으로 들어가 파서가 원문과 다른 문자열을
 * 읽게 된다.
 *
 * 데이터는 부모 뷰의 스코프에서 직접 읽는다. View::include() 의 두 번째 인자는
 * 데이터가 아니라 **캐시 옵션**이라 넘겨도 변수가 되지 않는다 — 부분 뷰는 부모의
 * 데이터를 그대로 상속받을 뿐이다(실측으로 확인했다).
 *
 * @var array<string, mixed> $meta 컨트롤러가 view() 로 넘긴 값. jsonld 키가 없으면 아무것도 그리지 않는다.
 */

$jsonld = ($meta ?? [])['jsonld'] ?? [];

if ($jsonld === []) {
    return;
}
?>
<script type="application/ld+json">
<?= json_encode($jsonld, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?>
</script>
