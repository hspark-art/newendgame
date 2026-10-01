/*
 * 송출 화면 동작: 0.3초마다 변경을 받아 반영한다.
 * - 요청이 실패하거나 응답이 이상하면 아무것도 바꾸지 않는다 (마지막 정상 화면 유지, 재시도).
 * - TAKE로 CG가 바뀌면: 보이는 CG를 내보낸 뒤(OUT) 새 CG로 바꾸고 들여보낸다(IN).
 * - 같은 CG의 값만 바뀌면(UPDATE LIVE) 제자리에서 바꾼다.
 */
(function () {
  'use strict';

  var stage = document.getElementById('stage');
  var pos = document.getElementById('pos');
  var cg = document.getElementById('cg');
  var api = stage.getAttribute('data-api');
  var only = stage.getAttribute('data-only') || '';
  var isProgram = stage.getAttribute('data-channel') === 'program';
  var heartbeat = stage.getAttribute('data-heartbeat') === '1';
  var clientId = Math.random().toString(36).slice(2, 12) + Date.now().toString(36);

  var cur = JSON.parse(stage.getAttribute('data-state'));
  var showing = cg.classList.contains('is-shown');
  var animating = false;
  var pending = null;
  var polls = 0;

  function wanted(s) {
    return !!s.visible && (!only || s.template === only);
  }

  function applyDisplay(d) {
    if (!d) { return; }
    pos.style.right = (d.right | 0) + 'px';
    pos.style.bottom = (d.bottom | 0) + 'px';
    pos.style.transform = 'scale(' + ((d.scale_pct || 100) / 100) + ')';
  }

  function setEffect(s) {
    var fx = isProgram ? (s.effect || 'slide') : 'cut';
    cg.classList.remove('fx-cut', 'fx-fade', 'fx-slide');
    cg.classList.add('fx-' + fx);
    cg.style.setProperty('--dur', (isProgram ? (s.dur_ms | 0) : 0) + 'ms');
  }

  /* 1920×1080 캔버스를 창 크기에 맞춘다 (비율 유지, 오른쪽 아래 기준). 창 크기를 알 수 없으면 그대로 둔다 */
  function fitStage() {
    var w = window.innerWidth;
    var h = window.innerHeight;
    var s = w > 0 && h > 0 ? Math.min(w / 1920, h / 1080) : 1;
    stage.style.transform = Math.abs(s - 1) < 0.001 ? '' : 'scale(' + s + ')';
  }

  /* 글자가 칸보다 길면 글자 크기를 줄여 한 줄에 맞춘다 (최소 60%) */
  function fit() {
    var items = cg.querySelectorAll('.cg-fit');
    for (var i = 0; i < items.length; i++) {
      var el = items[i];
      el.style.fontSize = '';
      var ps = window.getComputedStyle(el.parentNode);
      var box = el.parentNode.clientWidth - parseFloat(ps.paddingLeft) - parseFloat(ps.paddingRight) - 4;
      if (box > 0 && el.scrollWidth > box) {
        var base = parseFloat(window.getComputedStyle(el).fontSize);
        el.style.fontSize = Math.max(base * 0.6, base * box / el.scrollWidth) + 'px';
      }
    }
  }

  /* CG 디자인(관리자 설정): body에 테마 클래스, CSS 변수(폰트·크기·글자색)를 body에 직접 (테마보다 우선).
     폰트 파일을 다 받은 뒤 글자 맞춤을 다시 한다 */
  var designKey = '';
  function applyDesign(d) {
    if (!d) { return; }
    var key = JSON.stringify(d);
    if (key === designKey) { return; }
    designKey = key;
    var body = document.body;
    body.className = body.className.replace(/(^|\s)cg-theme-\S+/g, '').trim();
    if (d.theme) { body.classList.add('cg-theme-' + d.theme); }
    var old = body.getAttribute('data-vars');
    (old ? old.split(',') : []).forEach(function (k) { body.style.removeProperty(k); });
    var keys = Object.keys(d.vars || {});
    keys.forEach(function (k) { body.style.setProperty(k, d.vars[k]); });
    body.setAttribute('data-vars', keys.join(','));
    fit();
    if (document.fonts && document.fonts.load && d.families && d.families.length) {
      var loads = [];
      d.families.forEach(function (f) {
        ['400', '700', '800'].forEach(function (w) { loads.push(document.fonts.load(w + ' 30px "' + f + '"', '끝장전 0123 W')); });
      });
      Promise.all(loads.map(function (p) { return p.catch(function () {}); })).then(fit);
    }
  }

  function swap(s) {
    cg.innerHTML = s.html || '';
    applyDisplay(s.display);
    fit();
  }

  function show(on, animate) {
    if (!animate) {
      cg.classList.add('no-anim');
    }
    cg.classList.toggle('is-shown', on);
    if (!animate) {
      void cg.offsetWidth; // 즉시 반영 후 애니메이션 다시 켜기
      cg.classList.remove('no-anim');
    }
    showing = on;
  }

  function hideThen(ms, done) {
    animating = true;
    show(false, true);
    window.setTimeout(function () {
      animating = false;
      done();
      if (pending) {
        var p = pending;
        pending = null;
        apply(p);
      }
    }, ms + 30);
  }

  function apply(next) {
    if (animating) {
      pending = next;
      return;
    }
    var prev = cur;
    cur = next;
    var vis = wanted(next);
    var animate = isProgram && next.effect !== 'cut';
    if (next.take_id !== prev.take_id) {
      if (showing && isProgram && prev.effect !== 'cut') {
        hideThen(prev.dur_ms | 0, function () {
          applyDesign(next.design);
          setEffect(next);
          swap(next);
          if (wanted(cur)) { show(true, animate); }
        });
        return;
      }
      applyDesign(next.design);
      setEffect(next);
      swap(next);
      show(vis, animate && vis);
      return;
    }
    if (next.html !== prev.html || JSON.stringify(next.display) !== JSON.stringify(prev.display)) {
      swap(next);
    }
    applyDesign(next.design);
    if (vis !== showing) {
      setEffect(next);
      show(vis, animate);
    }
  }

  function schedule(ms) {
    window.setTimeout(poll, ms);
  }

  function poll() {
    polls++;
    var url = api + '&since=' + (cur.rev | 0);
    if (heartbeat && polls % 16 === 1) {
      url += '&hb=' + clientId;
    }
    var ctrl = window.AbortController ? new AbortController() : null;
    var timer = window.setTimeout(function () { if (ctrl) { ctrl.abort(); } }, 2000);
    fetch(url, { cache: 'no-store', credentials: 'same-origin', signal: ctrl ? ctrl.signal : undefined })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (s) {
        window.clearTimeout(timer);
        if (s && s.ok && !s.same && typeof s.html === 'string') {
          apply(s);
        } else if (s && s.ok && s.same) {
          cur.rev = s.rev;
        }
        schedule(s && s.ok ? 300 : 1000);
      })
      .catch(function () {
        // 연결 실패: 화면은 그대로 두고 다시 시도한다
        window.clearTimeout(timer);
        schedule(1000);
      });
  }

  fitStage();
  window.addEventListener('resize', fitStage);
  applyDesign(cur.design);
  setEffect(cur);
  applyDisplay(cur.display);
  fit();
  void cg.offsetWidth;
  cg.classList.remove('no-anim');
  schedule(300);
})();
