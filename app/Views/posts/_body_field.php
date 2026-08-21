<?php

/**
 * 본문 입력 + 마크다운 미리보기. (#149)
 *
 * 작성·수정 폼이 같은 마크업을 쓰던 자리라 부분 뷰로 뺐다. 한쪽만 고쳐 두 화면이
 * 어긋나는 일을 막는다.
 *
 * 미리보기는 **서버가 렌더한다**(POST posts/preview). 표 스크롤 컨테이너·코드 블록
 * 접근성·XSS 차단이 Post 엔티티 한 곳에 모여 있어서, 클라이언트 파서를 쓰면 미리보기와
 * 실제 글이 조용히 어긋난다.
 *
 * JS 가 꺼져 있으면 탭은 그냥 보이지 않고 textarea 만 남는다 — 글은 그대로 쓸 수 있다.
 *
 * 초기값은 여기서 정한다. CI4 의 $this->include() 는 데이터를 넘기지 못하고(뷰 옵션용),
 * 뷰 데이터는 렌더러가 공유하므로 수정 화면의 $post 가 여기서도 보인다.
 */
$value = old('body', isset($post) ? $post->body : '');
?>
<div class="editor" data-editor>
    <label for="body">본문</label>
    <p class="field-hint">마크다운으로 작성할 수 있습니다 — <code># 제목</code>, <code>**굵게**</code>, <code>[링크](https://…)</code></p>

    <div class="editor-tabs" role="tablist" aria-label="본문 편집" hidden data-editor-tabs>
        <button type="button" class="editor-tab is-active" role="tab" aria-selected="true"
                aria-controls="body-write" data-editor-tab="write">작성</button>
        <button type="button" class="editor-tab" role="tab" aria-selected="false"
                aria-controls="body-preview" data-editor-tab="preview">미리보기</button>
    </div>

    <div id="body-write" role="tabpanel" data-editor-panel="write">
        <textarea name="body" id="body" rows="12"><?= esc($value) ?></textarea>
    </div>

    <div id="body-preview" class="editor-preview prose" role="tabpanel"
         data-editor-panel="preview" hidden aria-busy="false"></div>
</div>
