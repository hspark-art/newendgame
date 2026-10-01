/*
 * CG 디자인 화면 (design.php): 고른 값을 미리보기에 바로 반영하고, [송출에 적용]·프리셋은 서버에 저장한다.
 * 송출 화면과 같은 방식으로 적용한다: 테마 클래스 + CSS 변수(폰트·크기·글자색) — output.js applyDesign과 같은 변수 이름.
 */
(function () {
  'use strict';

  var $ = function (id) { return document.getElementById(id); };
  var csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
  var boot = JSON.parse($('app').getAttribute('data-boot'));
  var V = norm(boot.view);      // {admin, current, presets, themes, fonts, sizes, colors}
  var draft = clone(V.current);
  var scopes = [$('stage'), $('strip')];
  var ROLES = ['title', 'name', 'num'];

  function clone(x) { return JSON.parse(JSON.stringify(x)); }
  /* 서버(PHP)는 빈 목록을 []로 보낸다 → 크기·색은 항상 {} 로 */
  function fix(d) {
    if (!d.k || Array.isArray(d.k)) { d.k = {}; }
    if (!d.colors || Array.isArray(d.colors)) { d.colors = {}; }
    return d;
  }
  function norm(v) {
    fix(v.current);
    v.presets.forEach(function (p) { fix(p.design); });
    return v;
  }
  /* 비교용: 키 순서와 관계없이 같은 값이면 같은 문자열 */
  function canon(d) {
    var sorted = function (o) { var r = {}; Object.keys(o).sort().forEach(function (k) { r[k] = o[k]; }); return r; };
    return JSON.stringify([d.theme, d.fonts.title, d.fonts.name, d.fonts.num, sorted(d.k), sorted(d.colors)]);
  }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function toast(msg, type) {
    var el = document.createElement('div');
    el.className = type || 'info';
    el.textContent = msg;
    $('toast').appendChild(el);
    window.setTimeout(function () { el.remove(); }, type === 'err' ? 6500 : 3500);
  }
  function api(action, payload) {
    return fetch('api/action.php', {
      method: 'POST', credentials: 'same-origin', cache: 'no-store',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
      body: JSON.stringify(Object.assign({ action: action }, payload || {}))
    }).then(function (r) {
      return r.json().catch(function () { return { ok: false, error: '서버 응답을 읽을 수 없습니다. (' + r.status + ')' }; });
    }, function () {
      return { ok: false, error: '서버에 연결할 수 없습니다.' };
    }).then(function (j) {
      if (j.state && j.state.csrf) { csrf = j.state.csrf; }
      if (!j.ok) {
        toast(j.error || '처리하지 못했습니다.', 'err');
        throw new Error(j.error || 'failed');
      }
      return j.result;
    });
  }

  /* 송출 화면과 같은 글자 맞춤: 칸보다 긴 글자는 최소 60%까지 줄인다 */
  function fit(root) {
    var items = root.querySelectorAll('.cg-fit');
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
  function fitAll() { scopes.forEach(fit); }
  function scaleStage() { $('stage').style.transform = 'scale(' + ($('frame').clientWidth / 1920) + ')'; }

  function hex(c) {
    var m = String(c).match(/\d+(\.\d+)?/g);
    if (!m || m.length < 3) { return '#000000'; }
    return '#' + m.slice(0, 3).map(function (x) { return ('0' + Math.round(+x).toString(16)).slice(-2); }).join('');
  }
  function summary(d) {
    var parts = [V.themes[d.theme]];
    ROLES.forEach(function (r) {
      if (d.fonts[r] !== 'default') { parts.push({ title: '제목', name: '이름', num: '숫자' }[r] + ' ' + V.fonts[d.fonts[r]].name); }
    });
    Object.keys(d.k).forEach(function (k) { parts.push(V.sizes[k].name + ' ' + d.k[k] + '%'); });
    if (Object.keys(d.colors).length) { parts.push('글자색 ' + Object.keys(d.colors).length + '개 지정'); }
    return parts.join(' · ');
  }

  /* 미리보기에 적용 (송출 화면 output.js applyDesign과 같은 변수) */
  function apply() {
    scopes.forEach(function (sc) {
      sc.className = sc.className.replace(/(^|\s)cg-theme-\S+/g, '').trim();
      if (draft.theme) { sc.classList.add('cg-theme-' + draft.theme); }
      ROLES.forEach(function (r) { sc.style.setProperty('--cg-font-' + r, V.fonts[draft.fonts[r]].family); });
      Object.keys(V.sizes).forEach(function (k) { sc.style.setProperty('--k-' + k, String((draft.k[k] || 100) / 100)); });
      Object.keys(V.colors).forEach(function (k) {
        if (draft.colors[k]) { sc.style.setProperty('--c-' + k, draft.colors[k]); } else { sc.style.removeProperty('--c-' + k); }
      });
    });
    Array.prototype.forEach.call(document.querySelectorAll('#themes .btn'), function (b) {
      b.setAttribute('aria-pressed', String(b.getAttribute('data-theme') === draft.theme));
    });
    ROLES.forEach(function (r) { $('font-' + r).value = draft.fonts[r]; });
    Array.prototype.forEach.call(document.querySelectorAll('#sizes input'), function (inp) {
      var v = draft.k[inp.getAttribute('data-k')] || 100;
      inp.value = v;
      inp.nextElementSibling.textContent = v + '%';
    });
    // 지정하지 않은 글자색은 지금 테마의 색을 보여 준다.
    // 1위 줄 글자는 테마에 기본값이 없으면 "항목별"(1위 줄도 다른 줄과 같은 색) — 고르면 그때부터 1위 줄 전체가 그 색
    var probe = document.createElement('span');
    $('stage').appendChild(probe);
    Object.keys(V.colors).forEach(function (k) {
      var themeHas = window.getComputedStyle($('stage')).getPropertyValue('--c-' + k).trim() !== '';
      probe.style.color = 'var(--c-' + k + ', var(--c-text))';
      var val = draft.colors[k] || hex(window.getComputedStyle(probe).color);
      var row = document.querySelector('.dz-color[data-c="' + k + '"]');
      row.querySelector('input').value = val;
      var code = row.querySelector('code');
      code.textContent = draft.colors[k] || themeHas ? val.toUpperCase() : '항목별';
      code.className = draft.colors[k] ? '' : 'is-theme';
      row.querySelector('button').disabled = !V.admin || !draft.colors[k];
    });
    probe.remove();
    var changed = canon(draft) !== canon(V.current);
    $('btnApply').disabled = !V.admin || !changed;
    $('btnRevert').disabled = !changed;
    fitAll();
    if (document.fonts && document.fonts.load) {
      Promise.all(ROLES.map(function (r) {
        var m = V.fonts[draft.fonts[r]].family.match(/^"([^"]+)"/);
        return m ? document.fonts.load('700 30px "' + m[1] + '"', '끝장전 0123').catch(function () {}) : null;
      })).then(fitAll);
    }
  }

  function renderPresets() {
    $('presets').innerHTML = V.presets.map(function (p, i) {
      return '<li><b title="' + esc(summary(p.design)) + '">' + esc(p.name) + '</b><small>' + esc((p.at || '').slice(0, 10)) + '</small>'
        + '<button type="button" class="btn sm" data-load="' + i + '">불러오기</button>'
        + (V.admin ? '<button type="button" class="btn sm" data-del="' + i + '">삭제</button>' : '') + '</li>';
    }).join('') || '<li class="muted">저장한 프리셋이 없습니다.</li>';
  }

  function build() {
    $('notAdmin').hidden = V.admin;
    $('applied').textContent = summary(V.current);
    $('themes').innerHTML = Object.keys(V.themes).map(function (k) {
      return '<button type="button" class="btn" data-theme="' + esc(k) + '" aria-pressed="false">' + esc(V.themes[k]) + '</button>';
    }).join('');
    ROLES.forEach(function (r) {
      $('font-' + r).innerHTML = Object.keys(V.fonts).map(function (k) {
        return '<option value="' + esc(k) + '">' + esc(V.fonts[k].name + (V.fonts[k].ko ? '' : ' · 영문·숫자')) + '</option>';
      }).join('');
    });
    $('sizes').innerHTML = Object.keys(V.sizes).map(function (k) {
      var s = V.sizes[k];
      return '<label class="dz-slider"><span>' + esc(s.name) + '</span><input type="range" data-k="' + esc(k) + '" min="' + s.min
        + '" max="' + s.max + '" step="1" value="100"><output>100%</output></label>';
    }).join('');
    $('colors').innerHTML = Object.keys(V.colors).map(function (k) {
      return '<div class="dz-color" data-c="' + esc(k) + '"><span>' + esc(V.colors[k]) + '</span><input type="color" aria-label="'
        + esc(V.colors[k]) + '"><code></code><button type="button" class="btn sm">테마 색</button></div>';
    }).join('');
    $('noSample').hidden = boot.samples.length > 0;
    $('picker').innerHTML = boot.samples.map(function (s, i) {
      return '<button type="button" class="btn sm" data-sample="' + i + '" aria-pressed="false">' + esc(s.label) + '</button>';
    }).join('');
    $('strip').innerHTML = boot.samples.map(function (s) {
      return '<figure><figcaption>' + esc(s.label) + '</figcaption><div class="cg">' + s.html + '</div></figure>';
    }).join('');
    Array.prototype.forEach.call(document.querySelectorAll('.dz-rack input, .dz-rack select, .dz-rack button'), function (el) {
      if (!V.admin && el.id !== 'btnRevert' && !el.hasAttribute('data-load')) { el.disabled = true; }
    });
    renderPresets();
  }

  function showSample(i) {
    var s = boot.samples[i];
    $('stageCg').innerHTML = s ? s.html : '';
    Array.prototype.forEach.call(document.querySelectorAll('#picker .btn'), function (b) {
      b.setAttribute('aria-pressed', String(Number(b.getAttribute('data-sample')) === i));
    });
    fit($('stage'));
  }

  function setView(v) {
    V = norm(v);
    $('applied').textContent = summary(V.current);
    renderPresets();
    apply();
  }

  build();
  $('themes').addEventListener('click', function (e) {
    var b = e.target.closest('[data-theme]');
    if (b && V.admin) { draft.theme = b.getAttribute('data-theme'); apply(); }
  });
  ROLES.forEach(function (r) {
    $('font-' + r).addEventListener('change', function () { draft.fonts[r] = this.value; apply(); });
  });
  $('sizes').addEventListener('input', function (e) {
    var k = e.target.getAttribute('data-k');
    if (!k) { return; }
    var v = Number(e.target.value);
    if (v === 100) { delete draft.k[k]; } else { draft.k[k] = v; }
    apply();
  });
  // 색 고르기: 끄는 동안(input)과 고른 뒤(change) 모두 반영 — 브라우저마다 보내는 이벤트가 달라 둘 다 받는다
  var pickColor = function (e) {
    var row = e.target.closest('.dz-color');
    if (row && e.target.type === 'color' && V.admin) { draft.colors[row.getAttribute('data-c')] = e.target.value.toLowerCase(); apply(); }
  };
  $('colors').addEventListener('input', pickColor);
  $('colors').addEventListener('change', pickColor);
  $('colors').addEventListener('click', function (e) {
    var row = e.target.closest('.dz-color');
    if (row && e.target.tagName === 'BUTTON') { delete draft.colors[row.getAttribute('data-c')]; apply(); }
  });
  $('sizeReset').onclick = function () { draft.k = {}; apply(); };
  $('colorReset').onclick = function () { draft.colors = {}; apply(); };
  $('btnRevert').onclick = function () { draft = clone(V.current); apply(); };
  $('btnDefault').onclick = function () {
    draft = { theme: '', fonts: { title: 'default', name: 'default', num: 'default' }, k: {}, colors: {} };
    apply();
  };
  $('btnApply').onclick = function () {
    api('design_save', { design: draft }).then(function (v) {
      setView(v);
      draft = clone(V.current);
      apply();
      toast('송출 화면에 적용했습니다: ' + summary(V.current), 'ok');
    });
  };
  $('presets').addEventListener('click', function (e) {
    var load = e.target.getAttribute('data-load');
    var del = e.target.getAttribute('data-del');
    if (load !== null) {
      var p = V.presets[Number(load)];
      draft = fix(clone(p.design));
      apply();
      toast('"' + p.name + '" 프리셋을 불러왔습니다. [송출에 적용]을 눌러야 송출 화면이 바뀝니다.');
    } else if (del !== null) {
      var name = V.presets[Number(del)].name;
      if (e.target.getAttribute('data-armed') !== '1') {
        e.target.setAttribute('data-armed', '1');
        e.target.textContent = '한 번 더 누르면 삭제';
        return;
      }
      api('design_preset_remove', { name: name }).then(function (v) { setView(v); toast('"' + name + '" 프리셋을 지웠습니다.', 'ok'); });
    }
  });
  $('presetForm').addEventListener('submit', function (e) {
    e.preventDefault();
    var name = $('presetName').value.trim();
    if (!name) { toast('프리셋 이름을 입력하세요.', 'err'); $('presetName').focus(); return; }
    api('design_preset_save', { name: name, design: draft }).then(function (v) {
      $('presetName').value = '';
      setView(v);
      toast('"' + name + '" 프리셋을 저장했습니다.', 'ok');
    });
  });
  $('picker').addEventListener('click', function (e) {
    var b = e.target.closest('[data-sample]');
    if (b) { showSample(Number(b.getAttribute('data-sample'))); }
  });
  $('bgLight').onchange = function () { $('frame').classList.toggle('is-light', this.checked); };
  if (window.ResizeObserver) { new ResizeObserver(scaleStage).observe($('frame')); }
  window.addEventListener('resize', scaleStage);
  scaleStage();
  showSample(0);
  apply();
  if (document.fonts && document.fonts.ready) { document.fonts.ready.then(fitAll); }
})();
