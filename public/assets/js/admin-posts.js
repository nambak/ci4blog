/**
 * 관리자 게시글 관리의 일괄 작업 보조 스크립트.
 *
 * 진행적 향상: 이 파일이 없어도 체크박스 → 버튼 제출은 그대로 동작한다.
 * 여기서는 선택 개수 표시, 전체선택 연동, 삭제 확인만 담당한다.
 */
(function () {
    'use strict';

    var form = document.getElementById('bulk-form');
    if (!form) {
        return;
    }

    var checkAll = document.getElementById('check-all');
    var bulkbar = document.getElementById('bulkbar');
    var selCount = document.getElementById('sel-count');
    var rowChecks = Array.prototype.slice.call(form.querySelectorAll('.row-check'));

    function selected() {
        return rowChecks.filter(function (cb) { return cb.checked; });
    }

    function sync() {
        var count = selected().length;

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
