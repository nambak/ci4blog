/**
 * 마크다운 미리보기 탭. (#149)
 *
 * 변환은 서버가 한다(POST posts/preview). 표 스크롤 컨테이너·코드 블록 접근성·XSS 차단이
 * Post 엔티티 한 곳에 모여 있어서, 여기서 파서를 따로 두면 미리보기와 실제 글이 어긋난다.
 *
 * 탭을 누를 때만 요청한다. 타이핑마다 보내면 요청이 쏟아지고, 얻는 것은 거의 없다.
 */
(function () {
    'use strict';

    var root = document.querySelector('[data-editor]');
    if (!root) return;

    var tabs = root.querySelector('[data-editor-tabs]');
    var textarea = root.querySelector('textarea');
    var preview = root.querySelector('[data-editor-panel="preview"]');
    var writePanel = root.querySelector('[data-editor-panel="write"]');
    if (!tabs || !textarea || !preview || !writePanel) return;

    // 마크업에는 hidden 으로 두고 JS 가 켠다. 스크립트가 없거나 죽으면 탭이 아예
    // 보이지 않고 textarea 만 남는다 — 글은 그대로 쓸 수 있다.
    tabs.hidden = false;

    var form = textarea.form;
    if (!form) return;

    var previewUrl = form.getAttribute('data-preview-url');
    // 주소를 모르면 미리보기를 켜지 않는다. 빈 URL 로 POST 하면 지금 페이지로 쏘게 된다.
    if (!previewUrl) return;

    // CSRF 가 전역으로 켜져 있다. "첫 번째 hidden" 으로 찾으면 나중에 다른 hidden 이
    // 앞에 붙는 순간 조용히 깨지므로, 폼이 알려 준 이름으로 집는다.
    var csrfName = form.getAttribute('data-csrf-name');
    var tokenField = csrfName ? form.querySelector('input[name="' + csrfName + '"]') : null;

    var lastRendered = null;
    // 요청을 한 번에 하나만 띄운다. 겹치면 먼저 보낸 응답이 나중에 도착해 뒷 내용을
    // 덮어쓸 수 있고, 무엇보다 **낡은 CSRF 토큰이 폼에 들어가 저장이 막힌다.**
    var inFlight = false;
    var queued = false;

    function select(name) {
        var isPreview = name === 'preview';

        writePanel.hidden = isPreview;
        preview.hidden = !isPreview;

        Array.prototype.forEach.call(tabs.querySelectorAll('[data-editor-tab]'), function (btn) {
            var active = btn.getAttribute('data-editor-tab') === name;
            btn.classList.toggle('is-active', active);
            btn.setAttribute('aria-selected', active ? 'true' : 'false');
        });

        if (isPreview) render();
    }

    function render() {
        var body = textarea.value;

        // 내용이 그대로면 다시 물을 이유가 없다.
        if (body === lastRendered) return;

        // 이미 하나가 날아가 있으면 끝난 뒤에 한 번만 더 돈다.
        if (inFlight) {
            queued = true;
            return;
        }

        inFlight = true;
        preview.setAttribute('aria-busy', 'true');

        var data = new FormData();
        data.append('body', body);
        if (tokenField) data.append(tokenField.name, tokenField.value);

        fetch(previewUrl, {
            method: 'POST',
            body: data,
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
        })
            .then(function (res) {
                if (!res.ok) throw new Error('preview failed: ' + res.status);
                return res.json();
            })
            .then(function (json) {
                // 서버가 이미 이스케이프한 HTML 이다(html_input=escape). 원시 HTML 은
                // 여기 도달하기 전에 문자열로 바뀐다.
                preview.innerHTML = json.html || '';
                lastRendered = body;

                // 토큰이 매 요청 재생성되므로(tokenRandomize) 폼의 값도 갱신해야
                // 이어지는 저장이 막히지 않는다.
                if (tokenField && json.token) tokenField.value = json.token;
            })
            .catch(function () {
                preview.innerHTML = '<p class="empty">미리보기를 불러오지 못했습니다. 잠시 후 다시 시도해 주세요.</p>';
                lastRendered = null;
            })
            .then(function () {
                inFlight = false;
                preview.setAttribute('aria-busy', 'false');

                // 기다리는 동안 내용이 바뀌었으면 그때 최신 내용으로 한 번 더.
                if (queued) {
                    queued = false;
                    render();
                }
            });
    }

    tabs.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-editor-tab]');
        if (btn) select(btn.getAttribute('data-editor-tab'));
    });
})();
