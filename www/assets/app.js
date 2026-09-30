/**
 * 공통 화면 동작
 *  data-confirm="문구"        : 누르기 전에 확인 창
 *  data-check-all="이름"      : 목록 전체 선택 체크박스
 *  data-require-check="이름"  : 목록에서 하나 이상 골라야 실행되는 버튼
 *  data-autosubmit            : 값을 바꾸면 바로 조회
 *  data-popup="주소"          : 별도 창(수집 창)으로 열기. data-popup-name 이 같으면 이미 열린 창으로 이동
 *  #live-ind                  : 위쪽 "● 수집 중" 표시 (15초마다 확인)
 */
(function () {
  'use strict';

  var POPUP_FEATURES = 'width=1280,height=900,menubar=no,toolbar=no,location=no,status=no';

  /**
   * 이름 있는 창을 엽니다. 같은 이름의 창이 이미 열려 있으면 새로 불러오지 않고 그 창으로 이동만 합니다.
   * (수집 창을 다시 불러오면 수집이 끊기므로)
   */
  function openPopup(url, name) {
    var w = window.open('', name, POPUP_FEATURES);
    if (!w) return false;
    var blank = true;
    try { blank = !w.location.href || w.location.href === 'about:blank'; } catch (e) { blank = false; }
    if (blank) w.location.href = url;
    try { w.focus(); } catch (e) { /* 무시 */ }
    return true;
  }
  window.EndgamePopup = openPopup;

  // 메인 창 이름 (수집 창의 [메인 창] 버튼이 이 창으로 돌아옵니다)
  if (!document.body.classList.contains('collector-window')) {
    try { if (!window.name || window.name.indexOf('collector-') !== 0) window.name = 'endgame-main'; } catch (e) { /* 무시 */ }
  }

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
    var pop = e.target.closest('[data-popup]');
    if (pop && openPopup(pop.getAttribute('data-popup'), pop.getAttribute('data-popup-name') || '_blank')) {
      e.preventDefault();
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

  // ── 위쪽 "● 수집 중" 표시 ────────────────────────────────
  var ind = document.getElementById('live-ind');
  if (ind) {
    var current = null;
    var check = function () {
      fetch('api/status.php', { credentials: 'same-origin', cache: 'no-store' })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (j) {
          var list = (j && j.collecting) || [];
          if (!list.length) {
            current = null;
            ind.classList.add('hidden');
            return;
          }
          current = list[0];
          var more = list.length > 1 ? ' 외 ' + (list.length - 1) + '개 회차' : '';
          ind.textContent = (current.live ? '● 수집 중' : '◌ 재연결 대기') + ' · ' + current.title + more +
            ' · 채팅 ' + Number(current.chat_count).toLocaleString('ko-KR');
          ind.classList.toggle('waiting', !current.live);
          ind.classList.remove('hidden');
        })
        .catch(function () { /* 다음 확인 때 다시 시도 */ });
    };
    ind.addEventListener('click', function (e) {
      e.preventDefault();
      if (current) {
        var url = 'collector.php?id=' + current.broadcast_id + '&popup=1';
        if (!openPopup(url, 'collector-' + current.broadcast_id)) location.href = url;
      }
    });
    check();
    setInterval(check, 15000);
  }
})();
