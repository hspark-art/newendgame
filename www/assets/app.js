/**
 * 공통 화면 동작
 *  data-confirm="문구"        : 누르기 전에 확인 창
 *  data-check-all="이름"      : 목록 전체 선택 체크박스
 *  data-require-check="이름"  : 목록에서 하나 이상 골라야 실행되는 버튼
 *  data-autosubmit            : 값을 바꾸면 바로 조회
 */
(function () {
  'use strict';

  document.addEventListener('click', function (e) {
    var confirmEl = e.target.closest('[data-confirm]');
    if (confirmEl && !confirm(confirmEl.getAttribute('data-confirm'))) {
      e.preventDefault();
      e.stopImmediatePropagation();
      return;
    }
    var req = e.target.closest('[data-require-check]');
    if (req) {
      var form = req.form || req.closest('form');
      var name = req.getAttribute('data-require-check');
      if (form && !form.querySelector('input[type="checkbox"][name="' + name + '"]:checked')) {
        e.preventDefault();
        alert('먼저 목록에서 항목을 선택해 주세요.');
      }
    }
  });

  document.addEventListener('change', function (e) {
    var all = e.target.closest('[data-check-all]');
    if (all) {
      var scope = all.closest('form') || document;
      var boxes = scope.querySelectorAll('input[type="checkbox"][name="' + all.getAttribute('data-check-all') + '"]');
      for (var i = 0; i < boxes.length; i++) boxes[i].checked = all.checked;
    }
    if (e.target.matches('[data-autosubmit]') && e.target.form) {
      e.target.form.submit();
    }
  });
})();
