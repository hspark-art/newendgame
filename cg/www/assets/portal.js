/* 웹 버전 계정 화면: 확인창과 주소 복사 (CSP 때문에 인라인 스크립트를 쓰지 않는다) */
(function () {
  'use strict';
  document.addEventListener('submit', function (e) {
    var msg = e.target.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) { e.preventDefault(); }
  });
  function copy(el) {
    var text = el.textContent.trim();
    var ok = function () { el.classList.add('copied'); window.setTimeout(function () { el.classList.remove('copied'); }, 1500); };
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(ok, function () { window.prompt('Ctrl+C로 복사하세요', text); });
    } else {
      window.prompt('Ctrl+C로 복사하세요', text);
    }
  }
  document.addEventListener('click', function (e) { if (e.target.classList.contains('copy')) { copy(e.target); } });
  document.addEventListener('keydown', function (e) {
    if (e.target.classList && e.target.classList.contains('copy') && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); copy(e.target); }
  });
})();
