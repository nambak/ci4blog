/**
 * 관리자 댓글 관리의 일괄 작업 보조 스크립트.
 *
 * 진행적 향상: 이 파일이 없어도 체크 → 일괄 제출, <details> 펼치기 → 답글 제출이
 * 그대로 동작한다. 여기서는 선택 개수 표시, 전체선택 연동, 삭제 확인만 담당한다.
 *
 * 게시글 관리와 달리 체크박스가 폼 바깥에 있고 form="bulk-form" 속성으로 붙어 있다
 * (행마다 답글 폼이 들어가 폼을 중첩시킬 수 없기 때문). 그래서 form 이 아니라
 * document 에서 체크박스를 찾는다.
 */
(function () {
    'use strict';

    // 정렬 드롭다운: JS 가 있으면 값이 바뀌는 즉시 폼을 제출한다(<noscript> 버튼 대체).
    // 이 폼은 댓글이 없어도(빈 목록) 그려지므로 bulk-form 유무와 무관하게 먼저 처리한다.
    var sortSelect = document.querySelector('.ct-sort select[data-autosubmit]');
    if (sortSelect && sortSelect.form) {
        sortSelect.addEventListener('change', function () {
            sortSelect.form.submit();
        });
    }

    var form = document.getElementById('bulk-form');
    if (!form) {
        return;
    }

    var checkAll = document.getElementById('check-all');
    var bulkbar = document.getElementById('bulkbar');
    var selCount = document.getElementById('sel-count');
    var rowChecks = Array.prototype.slice.call(document.querySelectorAll('.row-check'));

    function sync() {
        var count = rowChecks.filter(function (cb) { return cb.checked; }).length;

        selCount.textContent = String(count);
        bulkbar.classList.toggle('is-empty', count === 0);

        if (checkAll) {
            checkAll.checked = count > 0 && count === rowChecks.length;
            checkAll.indeterminate = count > 0 && count < rowChecks.length;
        }
    }

    rowChecks.forEach(function (cb) {
        cb.addEventListener('change', sync);
    });

    if (checkAll) {
        checkAll.addEventListener('change', function () {
            rowChecks.forEach(function (cb) { cb.checked = checkAll.checked; });
            sync();
        });
    }

    // 삭제처럼 되돌릴 수 없는 작업은 한 번 더 묻는다.
    form.addEventListener('click', function (event) {
        var button = event.target.closest('[data-confirm]');
        if (button && !window.confirm(button.getAttribute('data-confirm'))) {
            event.preventDefault();
        }
    });

    sync();
}());
