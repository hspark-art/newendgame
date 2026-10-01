/*
 * 조작 패널 동작.
 * - api/state.php 를 1초마다 폴링해 화면을 그린다 (변경이 없으면 짧은 응답).
 * - 조작은 api/action.php 로 보내고, 응답에 담긴 새 상태로 바로 다시 그린다.
 * - 입력 중인 칸(수정 중인 값)은 폴링으로 덮어쓰지 않는다.
 */
(function () {
  'use strict';

  var $ = function (id) { return document.getElementById(id); };
  var csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
  var S = null;            // 마지막 상태
  var rev = -1;            // 마지막 상태 번호
  var dirty = {};          // 타이틀 에디터에서 입력 중인 값 (필드 => 문자열)
  var edKey = '';          // 에디터가 그리고 있는 대상 (인스턴스 + 필드 목록)
  var pageBuf = '';        // 페이지 번호 입력
  var pageBufTimer = null;
  var takeLockUntil = 0;   // TAKE 연타 방지 (애니메이션 시간)
  var takeBusy = false;    // TAKE 요청이 끝날 때까지 다시 누르지 않게
  var failCount = 0;
  var editingPageId = null;

  var ACTIONS = {
    TAKE: '송출(TAKE)', SHOW: '표시(SHOW)', OUT: '내림(OUT)', UPDATE_LIVE: '긴급 수정', UPDATE_LIVE_REJECTED: '긴급 수정 거부',
    SET: '수정', RESET: '되돌리기', KEEP: 'KEEP 설정', PAGE_ADD: '페이지 추가', PAGE_EDIT: '페이지 수정',
    PAGE_REMOVE: '페이지 삭제', PAGE_IMPORT: '가져오기', REFRESH: '데이터 새로고침', REFRESH_FAIL: '데이터 오류',
    AUTO_CHANGED: '자동값 변경', NEW_SESSION: '새 세션', KEEP_CARRY: 'KEEP 이월', DISPLAY: '위치 변경',
    DISPLAY_LIVE: '위치 변경(송출)', MOCK_PURGE: 'MOCK 삭제', PLAYER_INFO: '닉네임'
  };

  // ------------------------------------------------------------ 공용

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function pad3(n) { return n == null ? '---' : ('00' + n).slice(-3); }
  /** 목록형 CG의 행 필드는 "2행 승"처럼 행 번호를 붙인다 */
  function fieldLabel(f) { return (f.group ? f.group + ' ' : '') + f.label; }

  function toast(msg, type) {
    var box = $('toast');
    var el = document.createElement('div');
    el.className = type || 'info';
    el.textContent = msg;
    box.appendChild(el);
    window.setTimeout(function () { el.remove(); }, type === 'err' ? 6500 : 3500);
  }

  function api(action, payload, quiet) {
    var body = Object.assign({ action: action }, payload || {});
    return fetch('api/action.php', {
      method: 'POST', credentials: 'same-origin', cache: 'no-store',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
      body: JSON.stringify(body)
    }).then(function (r) {
      return r.json().catch(function () { return { ok: false, error: '서버 응답을 읽을 수 없습니다. (' + r.status + ')' }; });
    }, function () {
      return { ok: false, error: '서버에 연결할 수 없습니다.' };
    }).then(function (j) {
      if (j.state) { render(j.state); }
      if (!j.ok) {
        if (!quiet) { toast(j.error || '처리하지 못했습니다.', 'err'); }
        var err = new Error(j.error || 'failed');
        err.data = j;
        throw err;
      }
      return j.result;
    });
  }

  function confirmBox(title, html, okLabel) {
    return new Promise(function (resolve) {
      var dlg = $('dlgConfirm');
      $('cfTitle').textContent = title;
      $('cfBody').innerHTML = html;
      $('cfOk').textContent = okLabel || '확인';
      dlg.returnValue = '';
      dlg.onclose = function () { resolve(dlg.returnValue === 'ok'); };
      dlg.showModal();
    });
  }

  function dialogOpen() { return !!document.querySelector('dialog[open]'); }

  function copyText(text) {
    var done = function () { toast('주소를 복사했습니다.', 'ok'); };
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(done, function () { toast('복사하지 못했습니다. Ctrl+C로 복사하세요.', 'err'); });
      return;
    }
    var ta = document.createElement('textarea');
    ta.value = text;
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand('copy'); done(); } catch (e) { toast('Ctrl+C로 복사하세요.', 'err'); }
    ta.remove();
  }

  // ------------------------------------------------------------ 폴링

  function poll() {
    fetch('api/state.php?since=' + rev, { cache: 'no-store', credentials: 'same-origin' })
      .then(function (r) {
        if (r.status === 401 || r.status === 403) { window.location.reload(); }
        return r.json();
      })
      .then(function (s) {
        failCount = 0;
        $('conn').hidden = true;
        if (s.same) {
          if (S) { S.server_ts = s.server_ts; S.outputs.seen = s.seen; renderSeen(); }
        } else if (s.ok) {
          if (s.csrf) { csrf = s.csrf; }
          render(s);
        }
      })
      .catch(function () {
        failCount++;
        if (failCount >= 2) { $('conn').hidden = false; }
      })
      .then(function () { window.setTimeout(poll, 1000); });
  }

  // ------------------------------------------------------------ 그리기

  function render(s) {
    var first = S === null;
    S = s;
    rev = s.rev;
    renderTop();
    renderRundown();
    renderMonitors(first);
    renderEditor();
    renderSide();
    renderButtons();
    if (first && s.data.ready && (s.source.status === 'NEVER' || !s.caches_ready)) {
      refresh(true);
    }
  }

  function renderTop() {
    $('sessName').textContent = S.session.name;
    $('mockBadge').hidden = S.source.id !== 'mock';
    var st = $('srcStatus');
    var src = S.source;
    var chk = S.data.check;
    var bad = chk && !chk.mock ? chk.mismatches + chk.anomalies + chk.unavailable.length + (chk.lint || 0) : 0;
    $('dataBadge').hidden = bad === 0;
    $('dataBadge').textContent = '확인 ' + bad;
    // 관리자 알림: 관리자에게만 버튼이 보이고, 확인하지 않은 새 알림 수를 표시
    var an = S.alerts_new;
    $('btnAlerts').hidden = an === null || an === undefined;
    $('alertBadge').hidden = !an;
    $('alertBadge').textContent = '새 ' + an;
    if (src.status === 'OK') {
      st.className = 'status ok';
      st.textContent = src.label + (chk && chk.method === 'xlsx' ? '(파일)' : '') + ' · 정상 · ' + (src.last_success_at || '').slice(5, 16);
    } else if (src.stale) {
      st.className = 'status stale';
      st.textContent = 'STALE · 마지막 정상 ' + src.last_success_at.slice(11) + ' · 갱신 실패';
      st.title = src.last_error || '';
    } else if (src.status === 'ERROR') {
      st.className = 'status error';
      st.textContent = '데이터 오류 · 정상 데이터 없음';
      st.title = src.last_error || '';
    } else {
      st.className = 'status never';
      st.textContent = S.data.ready ? '데이터 없음 · 새로고침 필요' : '데이터 없음 · Google 시트 연결 필요 ([데이터 점검·설정])';
    }
    renderSeen();
  }

  function renderSeen() {
    var seen = S.outputs.seen;
    var el = $('outSeen');
    el.className = 'outputs' + (seen.count > 0 ? ' ok' : '');
    el.innerHTML = '<i class="dot"></i> ' + (seen.count > 0 ? '출력 연결 ' + seen.count : '출력 연결 없음');
    el.title = seen.last ? '마지막 수신 ' + new Date(seen.last * 1000).toLocaleTimeString('ko-KR') : '송출 화면(OBS/vMix)이 아직 연결되지 않았습니다.';
  }

  function renderRundown() {
    var body = $('rdBody');
    var rows = S.rundown;
    $('rdEmpty').hidden = rows.length > 0;
    var html = '';
    rows.forEach(function (r) {
      var cls = (r.cued ? 'cued ' : '') + (r.in_program ? 'program' : '');
      var state = '';
      if (r.manual > 0) { state += '<span class="tag manual">MANUAL ' + r.manual + '</span>'; }
      if (r.pending_live) { state += '<span class="tag pending" title="수정한 값이 아직 송출에 반영되지 않았습니다 (TAKE 또는 UPDATE LIVE)">송출값과 다름</span>'; }
      var air = r.on_air ? '<span class="tag air">● ON AIR</span>' : (r.in_program ? '<span class="tag pgm-hidden">PGM 숨김</span>' : '');
      if (r.cued) { air = '<span class="tag pvw">PVW</span>' + air; }
      html += '<tr class="' + cls + '" data-id="' + r.id + '" data-no="' + r.page_no + '">'
        + '<td class="c-no">' + pad3(r.page_no) + '</td>'
        + '<td class="c-type">' + esc(r.short) + '</td>'
        + '<td class="ellipsis">' + esc(r.summary) + '</td>'
        + '<td class="c-memo ellipsis">' + esc(r.label) + '</td>'
        + '<td class="c-state">' + state + '</td>'
        + '<td class="c-air">' + air + '</td>'
        + '<td class="c-act">'
        + '<button type="button" class="iconbtn" data-act="up" title="위로">▲</button>'
        + '<button type="button" class="iconbtn" data-act="down" title="아래로">▼</button>'
        + '<button type="button" class="iconbtn" data-act="edit" title="수정">수정</button>'
        + '<button type="button" class="iconbtn" data-act="copy" title="복제">복제</button>'
        + '<button type="button" class="iconbtn" data-act="del" title="삭제">삭제</button>'
        + '</td></tr>';
    });
    body.innerHTML = html;
  }

  function renderMonitors(first) {
    if (first) {
      $('pvwIframe').src = S.outputs.preview_monitor;
      $('pgmIframe').src = S.outputs.program_monitor;
    }
    var pv = S.preview;
    $('pvwInfo').textContent = pv.instance_id ? pad3(pv.page_no) + ' · ' + pv.template_name + ' · ' + pv.summary : '큐된 페이지 없음';
    var pg = S.program;
    var badge = $('pgmBadge');
    if (pg.empty) {
      badge.className = 'air off'; badge.textContent = '비어 있음';
      $('pgmInfo').textContent = '';
    } else {
      badge.className = pg.visible ? 'air on' : 'air hidden';
      badge.textContent = pg.visible ? 'ON AIR' : '숨김';
      $('pgmInfo').textContent = pad3(pg.page_no) + ' · ' + pg.title + (pg.pending_live ? ' · 송출값과 다름' : '');
    }
  }

  function tickClock() {
    var now = new Date();
    $('clock').textContent = now.toLocaleTimeString('ko-KR', { hour12: false });
    if (S && !S.program.empty && S.program.taken_ts) {
      var offset = S.server_ts ? S.server_ts - Math.floor(Date.now() / 1000) : 0;
      var sec = Math.max(0, Math.floor(Date.now() / 1000) + offset - S.program.taken_ts);
      $('pgmElapsed').textContent = (S.program.visible ? '송출 ' : 'TAKE 후 ') + Math.floor(sec / 60) + ':' + ('0' + sec % 60).slice(-2);
    } else {
      $('pgmElapsed').textContent = '';
    }
  }

  function renderButtons() {
    var pv = S.preview, pg = S.program;
    $('btnTake').disabled = !pv.instance_id || pv.problems.length > 0 || takeBusy || Date.now() < takeLockUntil;
    $('btnOut').disabled = pg.empty || !pg.visible;
    $('btnShow').disabled = pg.empty || pg.visible;
    $('btnNext').disabled = S.rundown.length === 0;
    $('btnPrev').disabled = S.rundown.length === 0;
    var hasDirty = Object.keys(dirty).length > 0;
    $('btnSave').disabled = !pv.instance_id || !hasDirty;
    $('btnSave').classList.toggle('dirty', hasDirty);
    $('btnDiscard').disabled = !hasDirty;
    $('btnResetAll').disabled = !pv.instance_id || !pv.fields.some(function (f) { return f.has_manual; });
    $('btnLive').disabled = !pg.same_target || !(hasDirty || pg.live_manual);
    $('btnLive').title = pg.same_target ? '' : 'PREVIEW에 큐된 CG가 현재 PROGRAM과 같을 때만 사용할 수 있습니다.';
  }

  function renderEditor() {
    var pv = S.preview;
    var key = pv.instance_id ? pv.instance_id + ':' + pv.fields.map(function (f) { return f.key; }).join(',') : '';
    if (key !== edKey) {
      dirty = {};
      edKey = key;
      buildEditor();
    }
    var notice = $('edNotice');
    if (!pv.instance_id) {
      $('edTarget').textContent = 'PREVIEW에 큐된 페이지가 없습니다.';
      notice.hidden = true;
      return;
    }
    $('edTarget').textContent = pad3(pv.page_no) + ' · ' + pv.template_name + ' · ' + pv.summary;
    var msgs = [];
    if (pv.auto_missing) { msgs.push('자동값이 없습니다 (데이터 새로고침 필요). 수동으로 입력하면 송출할 수 있습니다.'); }
    if (pv.problems.length) { msgs.push('송출 불가: ' + pv.problems.join(' ')); }
    if (S.source.stale) { msgs.push('데이터 갱신 실패 — 마지막 정상 데이터(' + S.source.last_success_at + ') 기준입니다.'); }
    notice.hidden = msgs.length === 0;
    notice.className = 'notice' + (pv.problems.length ? ' err' : '');
    notice.textContent = msgs.join(' · ');
    pv.fields.forEach(updateEditorRow);
  }

  function buildEditor() {
    var body = $('edBody');
    if (!S.preview.instance_id) { body.innerHTML = ''; return; }
    var group = '';
    body.innerHTML = S.preview.fields.map(function (f) {
      // 목록형 CG는 행(1행, 2행 …)마다 구분 줄을 넣는다
      var head = f.group && f.group !== group ? '<tr class="grp"><th colspan="8">' + esc(f.group) + '</th></tr>' : '';
      group = f.group;
      return head + '<tr data-key="' + esc(f.key) + '">'
        + '<td class="label">' + esc(f.label) + '</td>'
        + '<td class="num auto"></td>'
        + '<td><input type="text" class="val" data-key="' + esc(f.key) + '" autocomplete="off"></td>'
        + '<td class="num final"></td>'
        + '<td class="num live"></td>'
        + '<td class="state"></td>'
        + '<td><input type="checkbox" class="keep" data-key="' + esc(f.key) + '"></td>'
        + '<td><button type="button" class="btn sm reset" data-key="' + esc(f.key) + '">되돌리기</button></td>'
        + '</tr>';
    }).join('');
  }

  function updateEditorRow(f) {
    var tr = document.querySelector('#edBody tr[data-key="' + f.key + '"]');
    if (!tr) { return; }
    var cells = tr.children;
    cells[1].innerHTML = esc(f.auto_text) + (f.auto_changed
      ? '<span class="sub warn">자동값 변경 ' + esc(f.auto_at_set_text) + ' → ' + esc(f.auto_text) + '</span>' : '');
    var input = cells[2].firstChild;
    input.placeholder = f.derived ? '자동 계산 ' + f.calc_text : f.auto_text;
    if (document.activeElement !== input && dirty[f.key] === undefined) {
      input.value = f.manual_text;
    }
    input.classList.toggle('dirty', dirty[f.key] !== undefined);
    cells[3].className = 'num final' + (f.has_manual ? ' manual' : '');
    cells[3].innerHTML = esc(f.final_text) + (f.derived
      ? '<span class="sub">' + (f.has_manual ? '직접 입력' + (f.differs ? ' · 계산값 ' + esc(f.calc_text) : '') : '자동 계산') + '</span>' : '');
    cells[4].className = 'num live' + (f.live_differs ? ' diff' : '');
    cells[4].textContent = f.live_text == null ? '—' : f.live_text;
    var st = '<span class="tag ' + (f.has_manual ? 'manual">MANUAL' : 'auto">AUTO') + '</span>';
    if (S.source.stale) { st += '<span class="tag stale">STALE</span>'; }
    else if (S.source.status !== 'OK') { st += '<span class="tag err">' + esc(S.source.status) + '</span>'; }
    cells[5].innerHTML = st;
    var keep = cells[6].firstChild;
    keep.checked = !!f.keep;
    keep.disabled = !f.has_manual;
    cells[7].firstChild.disabled = !f.has_manual && dirty[f.key] === undefined;
  }

  function renderSide() {
    var o = S.outputs;
    var list = [['PROGRAM 송출', o.program]];
    (o.lan || []).forEach(function (u) { list.push(['다른 PC에서', u]); });
    $('urlList').innerHTML = list.map(function (u) {
      return '<div class="muted">' + esc(u[0]) + '</div><code tabindex="0" data-url="' + esc(u[1]) + '">' + esc(u[1]) + '</code>';
    }).join('');
    var d = S.preview.display;
    [['dRight', d.right], ['dBottom', d.bottom], ['dScale', d.scale_pct]].forEach(function (p) {
      if (document.activeElement !== $(p[0])) { $(p[0]).value = p[1]; }
    });
    $('logList').innerHTML = S.logs.map(function (l) {
      var detail = l.detail || '';
      return '<li class="' + esc(l.type) + '"><span class="t" title="' + esc(l.date) + '">' + esc(l.time) + '</span>'
        + '<span class="a">' + esc(ACTIONS[l.action] || l.action) + '</span>'
        + '<span class="d">' + esc(detail) + ' <span class="muted">· ' + esc(l.operator) + '</span></span></li>';
    }).join('');
  }

  // ------------------------------------------------------------ 조작

  function take() {
    if ($('btnTake').disabled) { return; }
    var fx = $('fx').value;
    var dur = Math.round(parseFloat($('fxDur').value || '0.35') * 1000);
    takeLockUntil = Date.now() + (fx === 'cut' ? 300 : dur * 2 + 200);
    takeBusy = true;
    $('btnTake').disabled = true;
    window.setTimeout(renderButtons, takeLockUntil - Date.now() + 20);
    api('take', { preview_rev: S.preview.rev, effect: fx, dur_ms: dur, auto_next: $('autoNext').checked })
      .then(function () { toast('TAKE — 송출했습니다.', 'ok'); }, function () { takeLockUntil = 0; })
      .then(function () { takeBusy = false; renderButtons(); });
  }
  function out() { if (!$('btnOut').disabled) { api('out'); } }
  function show() { if (!$('btnShow').disabled) { api('show'); } }
  function step(dir) {
    if (!S || !S.rundown.length) { return; }
    api(dir > 0 ? 'next' : 'prev', {}, false).then(function (r) { if (!r) { toast(dir > 0 ? '마지막 페이지입니다.' : '첫 페이지입니다.'); } });
  }
  function cue(no) { api('cue_page', { page_no: no }); }
  function refresh(quiet) {
    return api('refresh_data', {}, !!quiet).then(function (r) {
      if (!quiet) { toast('데이터를 새로 불러왔습니다. 자동값 변경 ' + r.changed + '건', 'ok'); }
      else if (r.changed > 0) { toast('자동값이 바뀌었습니다: ' + r.changed + '건'); }
    }, function (e) { if (quiet && e.data) { toast(e.data.error, 'err'); } });
  }

  /* 요청에 보낸 값만 입력 중 목록에서 지운다 (요청 중에 다시 고친 칸은 남겨 둔다) */
  function clearSent(sent) {
    Object.keys(sent).forEach(function (k) { if (dirty[k] === sent[k]) { delete dirty[k]; } });
    renderEditor();
    renderButtons();
  }

  function save() {
    if (!S || !S.preview.instance_id || !Object.keys(dirty).length) { return; }
    var sent = Object.assign({}, dirty);
    api('save_preview', { instance_id: S.preview.instance_id, values: sent })
      .then(function () { clearSent(sent); toast('PREVIEW에 저장했습니다. 송출은 TAKE 또는 UPDATE LIVE로 반영됩니다.', 'ok'); },
        function (e) { markFieldErrors(e.data && e.data.fields); });
  }

  function markFieldErrors(fields) {
    if (!fields) { return; }
    Object.keys(fields).forEach(function (k) {
      var input = document.querySelector('#edBody input.val[data-key="' + k + '"]');
      if (input) { input.title = fields[k]; input.focus(); }
    });
  }

  function updateLive() {
    if ($('btnLive').disabled) { return; }
    // 확인창에 보여 준 상태 그대로 보낸다 (창이 열린 동안 폴링으로 바뀐 값은 보내지 않음)
    var sent = Object.assign({}, dirty);
    var req = { instance_id: S.preview.instance_id, take_id: S.program.take_id, preview_rev: S.preview.rev, values: sent };
    var rows = [], autoOnly = [];
    S.preview.fields.forEach(function (f) {
      if (sent[f.key] !== undefined) {
        rows.push('<li>' + esc(fieldLabel(f)) + ': <span class="from">' + esc(f.live_text) + '</span> → <span class="to">' + esc(sent[f.key]) + ' (입력)</span></li>');
      } else if (f.live_differs && f.has_manual) {
        rows.push('<li>' + esc(fieldLabel(f)) + ': <span class="from">' + esc(f.live_text) + '</span> → <span class="to">' + esc(f.final_text) + '</span></li>');
      } else if (f.live_differs && !f.derived) {
        autoOnly.push(esc(fieldLabel(f)));
      }
    });
    confirmBox('UPDATE LIVE — 송출 중인 CG를 바로 수정합니다',
      '<p>' + pad3(S.program.page_no) + ' · ' + esc(S.program.title) + '</p><ul class="diff">' + rows.join('') + '</ul>'
      + '<p class="hint">승률은 승·패에 맞춰 다시 계산됩니다. 수정값은 PREVIEW에도 저장됩니다.</p>'
      + (autoOnly.length ? '<p class="hint">자동값이 바뀐 항목(' + autoOnly.join(', ') + ')은 반영하지 않습니다. 반영하려면 TAKE 하세요.</p>' : ''),
      'UPDATE LIVE')
      .then(function (ok) {
        if (!ok) { return; }
        api('update_live', req)
          .then(function () { clearSent(sent); toast('송출 중인 CG를 수정했습니다.', 'ok'); },
            function (e) { markFieldErrors(e.data && e.data.fields); });
      });
  }

  // ------------------------------------------------------------ 페이지 추가·수정

  // 입력칸은 템플릿의 params 정의(S.templates[].params)로 만든다. 키에 점이 있으면 중첩: 'a.player' → {a: {player}}
  var RACES = [['P', 'P 프로토스'], ['T', 'T 테란'], ['Z', 'Z 저그']];

  function tplBySlug(slug) {
    return S.templates.filter(function (t) { return t.slug === slug; })[0];
  }
  function getPath(obj, key) {
    return key.split('.').reduce(function (o, k) { return o && typeof o === 'object' ? o[k] : undefined; }, obj);
  }
  function setPath(obj, key, v) {
    var ks = key.split('.');
    var o = obj;
    ks.slice(0, -1).forEach(function (k) { o = o[k] = o[k] || {}; });
    o[ks[ks.length - 1]] = v;
  }
  /** 저장된 값이 지금 목록에 없어도(예: 기록이 없는 연도·예측자) 그대로 보이고 저장되게 선택지를 더한다 */
  function withValue(list, value, label) {
    var has = value === undefined || value === null || value === '' || list.some(function (o) { return String(o[0]) === String(value); });
    return has ? list : list.concat([[value, label || value]]);
  }
  function options(list, value) {
    return list.map(function (o) {
      return '<option value="' + esc(o[0]) + '"' + (o[2] ? ' data-race="' + esc(o[2]) + '"' : '')
        + (String(o[0]) === String(value) ? ' selected' : '') + '>' + esc(o[1]) + '</option>';
    }).join('');
  }

  function paramControl(p, v) {
    var attr = ' data-key="' + esc(p.key) + '" data-type="' + esc(p.type) + '"';
    switch (p.type) {
      case 'player':
        return '<select' + attr + '>' + options([['', '선택']].concat(S.players.map(function (x) {
          return [x.id, x.name + ' (' + x.race + ')', x.race];
        })), v) + '</select>';
      case 'race':
        return '<select' + attr + '>' + options(RACES, v || 'P') + '</select>';
      case 'race_any':
        return '<select' + attr + '>' + options([['', '전체 종족']].concat(RACES), v === undefined ? (p.default_value || '') : v) + '</select>';
      case 'int':
        return '<input type="number"' + attr + ' min="' + p.min + '" max="' + p.max + '" value="' + esc(v === undefined ? p.default : v) + '">';
      case 'year':
        return S.years.length
          ? '<select' + attr + '>' + options(withValue(S.years.map(function (y) { return [y, y + '년']; }), v, v + '년 (기록 없음)'), v || S.years[0]) + '</select>'
          : '<input type="text"' + attr + ' maxlength="4" placeholder="예: 2026" value="' + esc(v || '') + '">';
      case 'map':
      case 'map_any':
        var maps = (p.type === 'map_any' ? [['', '고르지 않음']] : [['', '선택']]).concat((S.maps || []).map(function (m) {
          return [m.id, m.name + (m.name !== m.id ? ' (' + m.id + ')' : '') + ' · ' + m.sets + '세트'];
        }));
        return '<select' + attr + '>' + options(withValue(maps, v, v + ' (목록에 없음)'), v || '') + '</select>';
      case 'predictor_slots':
        var list = [['', '—']].concat(S.predictors.map(function (x) { return [x.id, x.name]; }));
        (v || []).forEach(function (id) { list = withValue(list, id, id + ' (목록에 없음)'); });
        var html = '<span class="slots">';
        for (var i = 0; i < p.max; i++) {
          html += '<select data-slot="' + i + '"' + attr + ' aria-label="' + (i + 1) + '번 자리">' + options(list, (v || [])[i] || '') + '</select>';
        }
        return html + '</span>';
    }
    return '';
  }

  function buildParams(slug, params) {
    var tpl = tplBySlug(slug);
    $('pParams').innerHTML = tpl.params.map(function (p) {
      return '<label class="prm"><span>' + esc(p.label) + '</span>' + paramControl(p, getPath(params || {}, p.key)) + '</label>';
    }).join('');
    var linked = tpl.params.some(function (p) { return p.auto_from; });
    $('pHint').textContent = linked ? '선수를 고르면 상대 종족이 서로의 종족으로 자동 선택됩니다. 필요하면 바꾸세요.' : '';
  }

  function openPage(row) {
    if (!S.players.length) {
      toast(S.data.ready ? '선수 목록이 없습니다. 데이터 새로고침을 먼저 하세요.'
        : '선수 목록이 없습니다. [데이터 점검·설정]에서 Google 시트를 연결하거나 xlsx 파일을 가져오세요.', 'err');
      return;
    }
    editingPageId = row ? row.id : null;
    $('pageTitle').textContent = row ? pad3(row.page_no) + ' 페이지 수정' : '페이지 추가';
    $('pTemplate').innerHTML = S.templates.map(function (t) { return '<option value="' + esc(t.slug) + '">' + esc(t.name) + '</option>'; }).join('');
    $('pTemplate').value = row ? row.template : S.templates[0].slug;
    $('pTemplate').disabled = !!row;
    buildParams($('pTemplate').value, row ? row.params : null);
    $('pNo').value = row ? row.page_no : '';
    $('pLabel').value = row ? row.label : '';
    $('dlgPage').returnValue = '';
    $('dlgPage').showModal();
  }

  /** 선수를 바꾸면 그 선수를 auto_from으로 가리키는 종족 칸을 선수의 종족으로 맞춘다 */
  function autoRace(sel) {
    var o = sel.options[sel.selectedIndex];
    var race = o ? o.getAttribute('data-race') : null;
    if (!race) { return; }
    tplBySlug($('pTemplate').value).params.forEach(function (p) {
      if (p.auto_from === sel.getAttribute('data-key')) {
        $('pParams').querySelector('[data-key="' + p.key + '"]').value = race;
      }
    });
  }

  function readParams() {
    var out = {};
    tplBySlug($('pTemplate').value).params.forEach(function (p) {
      var els = $('pParams').querySelectorAll('[data-key="' + p.key + '"]');
      if (p.type === 'predictor_slots') {
        setPath(out, p.key, Array.prototype.map.call(els, function (e) { return e.value; }).filter(Boolean));
      } else {
        setPath(out, p.key, els[0].value);
      }
    });
    return out;
  }

  function submitPage() {
    var payload = { template: $('pTemplate').value, params: readParams(), label: $('pLabel').value };
    if ($('pNo').value !== '') { payload.page_no = $('pNo').value; }
    var p = editingPageId ? api('page_update', Object.assign({ id: editingPageId }, payload)) : api('page_add', payload);
    p.then(function (r) { toast(pad3(r.page_no) + ' 페이지를 저장했습니다.', 'ok'); }, function () { $('dlgPage').showModal(); });
  }

  function rowById(id) {
    return S.rundown.filter(function (r) { return r.id === id; })[0];
  }

  // ------------------------------------------------------------ 데이터 점검·설정

  function dataTab(name) {
    Array.prototype.forEach.call(document.querySelectorAll('#dlgData .tab'), function (b) {
      b.classList.toggle('on', b.getAttribute('data-tab') === name);
    });
    Array.prototype.forEach.call(document.querySelectorAll('#dlgData .tabpane'), function (p) {
      p.hidden = p.getAttribute('data-pane') !== name;
    });
    if (name === 'check') { loadCheck(); }
    if (name === 'players') { api('player_info').then(renderPlayerInfo); }
    if (name === 'maps') { api('map_info').then(renderMapInfo); }
    if (name === 'settings') { api('data_settings').then(renderSettings); }
  }

  function openData() {
    $('dlgData').returnValue = '';
    $('dlgData').showModal();
    dataTab('check');
  }

  function loadCheck() {
    api('data_check').then(function (r) {
      var s = r.summary;
      var html = '';
      if (!s) {
        html = '<p>아직 불러온 데이터가 없습니다. [데이터 설정] 탭에서 Google 시트를 연결하거나 xlsx 파일을 가져오세요.</p>';
      } else if (s.mock) {
        html = '<p><b>MOCK 데이터</b> (검증용 가짜 수치) · ' + esc(s.at) + '</p>';
      } else {
        var c = s.counts;
        var v = function (ok, label) { return '<span class="tag ' + (ok ? 'auto">' : 'err">') + esc(label) + (ok ? ' 대조됨' : ' 대조 불가') + '</span> '; };
        html = '<p><b>Google 시트' + (s.method === 'xlsx' ? ' (xlsx 파일)' : '') + '</b> · ' + esc(s.at) + '</p>'
          + '<p>세트 ' + c.games + ' · 끝장전 ' + c.matches + ' (통계 사용 ' + c.valid_matches + ') · 선수 ' + c.players
          + (c.nicknames ? ' (닉네임 ' + c.nicknames + '명)' : '')
          + ' · 예측 ' + c.predictions + (c.maps ? ' · 맵 ' + c.maps + (c.map_names ? ' (한글 ' + c.map_names + ')' : '') : '') + ' · 기간 ' + esc(c.first_date) + ' ~ ' + esc(c.last_date) + '</p>'
          + '<p>' + v(s.verified.sets, '세트 전적') + v(s.verified.matches, '끝장전 목록') + v(s.verified.predictions, '승자 예측')
          + v(s.verified.double, '더블 찬스') + v(s.verified.mission, '미션 지수') + v(s.verified.maps, '맵 상성')
          + v(s.verified.mapsets, '선수 맵 전적') + '</p>'
          + s.unavailable.map(function (u) { return '<p class="notice err">' + esc(u) + '</p>'; }).join('');
      }
      $('dcSummary').innerHTML = html;
      $('dcMisCount').textContent = r.mismatches.length + '건';
      $('dcAnoCount').textContent = r.anomalies.length + '건';
      var kinds = { sets: '세트 전적', matches: '끝장전', predictions: '승자 예측', double: '더블 찬스', mission: '미션 지수',
        maps: '맵 상성', mapsets: '선수 맵 전적' };
      $('dcMismatch').innerHTML = r.mismatches.map(function (m) {
        return '<tr><td>' + esc(kinds[m.kind] || m.kind) + '</td><td>' + esc(m.who) + '</td><td>' + esc(m.item) + '</td><td>'
          + esc(m.sheet) + '</td><td>' + esc(m.calc) + '</td></tr>';
      }).join('') || '<tr><td colspan="5" class="muted">없음</td></tr>';
      var admin = S.operator.role === 'admin';
      $('dcAnomaly').innerHTML = r.anomalies.map(function (a) {
        var btn = admin && a.sub === 'sets'
          ? ' <button type="button" class="btn sm" data-exclude="' + esc(a.match) + '" data-on="1">끝장전 통계 제외 확정</button>' : '';
        return '<li>' + esc(a.text) + btn + '</li>';
      }).join('') || '<li class="muted">없음</li>';
      var lint = r.lint || [];
      $('dcLintCount').textContent = lint.length + '건';
      $('dcLint').innerHTML = lint.map(function (l) { return '<li>' + esc(l.text) + '</li>'; }).join('') || '<li class="muted">없음</li>';
      $('dcExcCount').textContent = r.excluded.length + '건';
      $('dcExcluded').innerHTML = r.excluded.map(function (a) {
        var btn = admin ? ' <button type="button" class="btn sm" data-exclude="' + esc(a.match) + '" data-on="0">제외 취소</button>' : '';
        return '<li>' + esc(a.text) + btn + '</li>';
      }).join('') || '<li class="muted">없음</li>';
    });
  }

  function renderPlayerInfo(r) {
    var list = r.players || r;
    $('piBody').innerHTML = list.map(function (p) {
      return '<tr data-player="' + esc(p.id) + '"><td>' + esc(p.name) + '</td><td>' + esc(p.race || '-') + '</td>'
        + '<td class="muted">' + esc(p.sheet_nick || '—') + '</td>'
        + '<td><input type="text" class="nick" maxlength="20" value="' + esc(p.nickname) + '" placeholder="'
        + esc(p.sheet_nick ? '비우면 시트 값 (' + p.sheet_nick + ')' : '') + '"></td>'
        + '<td><button type="button" class="btn sm" data-act="nick">저장</button></td></tr>';
    }).join('') || '<tr><td colspan="5" class="muted">선수 목록이 없습니다. 데이터를 먼저 불러오세요.</td></tr>';
  }

  function renderMapInfo(r) {
    var list = r.maps || r;
    $('miBody').innerHTML = list.map(function (m) {
      return '<tr data-map="' + esc(m.id) + '"><td>' + esc(m.id) + '</td><td>' + esc(m.sets) + '</td>'
        + '<td class="muted">' + esc(m.sheet_name || '—') + '</td>'
        + '<td><input type="text" class="mapko" maxlength="20" value="' + esc(m.name_ko) + '" placeholder="'
        + esc(m.sheet_name ? '비우면 시트 값 (' + m.sheet_name + ')' : '예: 녹아웃') + '"></td>'
        + '<td><button type="button" class="btn sm" data-act="mapko">저장</button></td></tr>';
    }).join('') || '<tr><td colspan="5" class="muted">맵 목록이 없습니다. 데이터를 먼저 불러오세요.</td></tr>';
  }

  var TAB_INPUTS = [['dsTabResults', 'results'], ['dsTabPlayers', 'players'], ['dsTabMatches', 'matches'],
    ['dsTabPredictions', 'predictions'], ['dsTabAdjust', 'adjust'], ['dsTabStats', 'stats'], ['dsTabNicks', 'nicks'],
    ['dsTabMapstats', 'mapstats'], ['dsTabMapplayers', 'mapplayers'], ['dsTabMapnames', 'mapnames']];

  function renderSettings(r) {
    $('dsNotAdmin').hidden = r.admin;
    Array.prototype.forEach.call(document.querySelectorAll('#dsForm input, #dsForm select, #dsForm button'), function (el) {
      el.disabled = !r.admin;
    });
    $('dsSource').innerHTML = Object.keys(r.sources).map(function (k) {
      return '<option value="' + esc(k) + '"' + (k === r.source ? ' selected' : '') + '>' + esc(r.sources[k]) + '</option>';
    }).join('');
    $('dsSheet').value = r.sheet_id ? (r.admin ? 'https://docs.google.com/spreadsheets/d/' + r.sheet_id + '/edit' : r.sheet_id) : '';
    TAB_INPUTS.forEach(function (x) { $(x[0]).value = (r.tabs || {})[x[1]] || ''; });
    $('dsKeyEmail').textContent = r.key_email || '없음';
    $('dsOpenssl').hidden = r.openssl;
    $('dsZip').hidden = r.zip;
    $('dsKeyPublic').hidden = !r.key_dir_public;
  }

  function saveSettings() {
    var tabs = {};
    TAB_INPUTS.forEach(function (x) { tabs[x[1]] = $(x[0]).value; });
    return api('data_settings_save', { source: $('dsSource').value, sheet: $('dsSheet').value, tabs: tabs })
      .then(function (r) { renderSettings(r); toast('데이터 설정을 저장했습니다. [데이터 새로고침]으로 반영하세요.', 'ok'); });
  }

  /** 파일 읽기. 파일이 없거나 너무 크거나 읽지 못하면 안내하고 끝낸다 (이어지는 동작은 실행되지 않음) */
  function readFile(input, asDataUrl, maxBytes) {
    return new Promise(function (resolve) {
      var f = input.files[0];
      input.value = '';
      if (!f) { return; }
      if (maxBytes && f.size > maxBytes) {
        toast('파일이 너무 큽니다 (최대 ' + Math.round(maxBytes / 1048576) + 'MB).', 'err');
        return;
      }
      var reader = new FileReader();
      reader.onload = function () { resolve(reader.result); };
      reader.onerror = function () { toast('파일을 읽지 못했습니다.', 'err'); };
      if (asDataUrl) { reader.readAsDataURL(f); } else { reader.readAsText(f); }
    });
  }

  // ------------------------------------------------------------ 관리자 알림

  var alTab = 'new';
  var alData = null;
  var AL_EMPTY = { new: '새 알림이 없습니다.', acked: '확인한 알림이 없습니다.', resolved: '해결된 알림이 없습니다.' };

  function openAlerts() {
    alTab = 'new';
    $('dlgAlerts').returnValue = '';
    $('dlgAlerts').showModal();
    api('alerts').then(renderAlerts);
  }

  function renderAlerts(r) {
    alData = r;
    $('alNewCount').textContent = r.new.length;
    $('alAckCount').textContent = r.acked.length;
    $('alResCount').textContent = r.resolved.length;
    Array.prototype.forEach.call(document.querySelectorAll('#dlgAlerts .tab'), function (b) {
      b.classList.toggle('on', b.getAttribute('data-atab') === alTab);
    });
    $('alAckAll').hidden = alTab !== 'new' || r.new.length === 0;
    $('alList').innerHTML = r[alTab].map(function (a) {
      var when = alTab === 'resolved' ? '해결 ' + a.resolved_at
        : (alTab === 'acked' ? '확인 ' + a.acked_at + ' · ' + a.acked_by + ' · ' : '') + '발생 ' + a.first_at
          + (a.last_at !== a.first_at ? ' · 최근 감지 ' + a.last_at : '');
      return '<li class="k-' + esc(a.kind) + '"><div><span class="tag ' + (alTab === 'resolved' ? 'auto' : 'err') + '">'
        + esc(a.kind_label) + '</span> <b>' + esc(a.title) + '</b></div>'
        + '<div class="al-detail">' + esc(a.detail) + '</div><div class="al-meta muted">' + esc(when)
        + (alTab === 'new' ? ' <button type="button" class="btn sm" data-ack="' + a.id + '">확인</button>' : '') + '</div></li>';
    }).join('') || '<li class="muted">' + AL_EMPTY[alTab] + (alTab === 'resolved' ? ' (최근 ' + r.keep_days + '일)' : '') + '</li>';
  }

  // ------------------------------------------------------------ 가져오기·내보내기

  function exportRundown() {
    api('rundown_export').then(function (data) {
      var blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
      var a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = 'endgame-cg-pages-' + new Date().toISOString().slice(0, 10) + '.json';
      document.body.appendChild(a);
      a.click();
      a.remove();
      window.setTimeout(function () { URL.revokeObjectURL(a.href); }, 1000);
    });
  }

  function importRundown(file) {
    var reader = new FileReader();
    reader.onload = function () {
      var data;
      try { data = JSON.parse(reader.result); } catch (e) { toast('JSON 파일이 아닙니다.', 'err'); return; }
      var n = (data && data.pages && data.pages.length) || 0;
      confirmBox('페이지 가져오기', '<p>' + n + '개 페이지를 현재 리스트 <b>뒤에 추가</b>합니다. 번호가 겹치면 빈 번호로 바꿉니다.</p>', '가져오기')
        .then(function (ok) {
          if (ok) { api('rundown_import', { data: data }).then(function (r) { toast(r.added + '개 페이지를 가져왔습니다.', 'ok'); }); }
        });
    };
    reader.readAsText(file);
  }

  // ------------------------------------------------------------ 이벤트

  function bind() {
    $('btnTake').onclick = take;
    $('btnOut').onclick = out;
    $('btnShow').onclick = show;
    $('btnNext').onclick = function () { step(1); };
    $('btnPrev').onclick = function () { step(-1); };
    $('btnRefresh').onclick = function () { refresh(false); };
    $('btnSave').onclick = save;
    $('btnLive').onclick = updateLive;
    $('btnDiscard').onclick = function () { dirty = {}; edKey = ''; renderEditor(); renderButtons(); };
    $('btnResetAll').onclick = function () {
      confirmBox('전체 되돌리기', '<p>이 CG의 수정값(MANUAL)을 모두 지우고 AUTO로 되돌립니다. PREVIEW만 바뀌며 송출 중인 화면은 그대로입니다.</p>', '되돌리기')
        .then(function (ok) { if (ok) { api('reset', { instance_id: S.preview.instance_id }).then(function () { dirty = {}; }); } });
    };
    $('btnAdd').onclick = function () { openPage(null); };
    $('btnData').onclick = openData;
    Array.prototype.forEach.call(document.querySelectorAll('#dlgData .tab'), function (b) {
      b.onclick = function () { dataTab(b.getAttribute('data-tab')); };
    });
    // 데이터 창의 입력칸에서 Enter: 창이 닫히지 않게 막고, 닉네임 칸이면 그 줄을 저장한다
    $('btnAlerts').onclick = openAlerts;
    Array.prototype.forEach.call(document.querySelectorAll('#dlgAlerts .tab'), function (b) {
      b.onclick = function () { alTab = b.getAttribute('data-atab'); if (alData) { renderAlerts(alData); } };
    });
    $('alList').addEventListener('click', function (e) {
      var b = e.target.closest('[data-ack]');
      if (b) { api('alert_ack', { id: Number(b.getAttribute('data-ack')) }).then(renderAlerts); }
    });
    $('alAckAll').onclick = function () { api('alert_ack', { all: true }).then(renderAlerts); };
    $('alOpenData').onclick = function () { $('dlgAlerts').close(); openData(); };
    $('dlgData').addEventListener('keydown', function (e) {
      if (e.key !== 'Enter' || e.target.tagName !== 'INPUT' || e.isComposing) { return; }
      e.preventDefault();
      if (e.target.classList.contains('nick')) { e.target.closest('tr').querySelector('[data-act="nick"]').click(); }
      if (e.target.classList.contains('mapko')) { e.target.closest('tr').querySelector('[data-act="mapko"]').click(); }
    });
    // 이상 경기 → 끝장전 통계 제외 확정 / 취소 (관리자)
    $('dlgData').addEventListener('click', function (e) {
      var id = e.target.getAttribute('data-exclude');
      if (id === null) { return; }
      var on = e.target.getAttribute('data-on') === '1';
      confirmBox(on ? '끝장전 통계에서 제외' : '제외 취소',
        '<p>' + esc(e.target.parentNode.firstChild.textContent) + '</p><p>' + (on
          ? '이 경기를 끝장전 통계(맞대결·다승·연승·풀세트·최근 전적)에서 뺍니다. 세트 전적에는 그대로 들어갑니다.'
          : '다시 "확인 필요" 경기로 돌립니다. 관련 선수의 끝장전 CG는 확인 전까지 막힙니다.') + '</p>', on ? '제외 확정' : '제외 취소')
        .then(function (ok) {
          if (ok) { api('match_exclude', { match: id, on: on }).then(function () { loadCheck(); toast('반영했습니다.', 'ok'); }); }
        });
    });
    $('piBody').addEventListener('click', function (e) {
      if (e.target.getAttribute('data-act') !== 'nick') { return; }
      var tr = e.target.closest('tr');
      api('player_info_save', { player: tr.getAttribute('data-player'), nickname: tr.querySelector('.nick').value })
        .then(function (r) { renderPlayerInfo(r); toast('닉네임을 저장했습니다.', 'ok'); });
    });
    $('miBody').addEventListener('click', function (e) {
      if (e.target.getAttribute('data-act') !== 'mapko') { return; }
      var tr = e.target.closest('tr');
      api('map_info_save', { map: tr.getAttribute('data-map'), name_ko: tr.querySelector('.mapko').value })
        .then(function (r) { renderMapInfo(r); toast('맵 이름을 저장했습니다.', 'ok'); });
    });
    $('dsSave').onclick = saveSettings;
    $('dsTest').onclick = function () {
      toast('시트에 연결하는 중…');
      api('data_test').then(function (s) {
        toast('연결 성공: 세트 ' + s.counts.games + ' · 끝장전 ' + s.counts.matches + ' · 불일치 ' + s.mismatches + ' · 이상 ' + s.anomalies, 'ok');
      });
    };
    $('dsKeyFile').onchange = function () {
      readFile(this, false, 65536).then(function (text) {
        api('data_key_save', { key: text }).then(function (r) {
          $('dsKeyEmail').textContent = r.client_email;
          toast('서비스 계정 키를 등록했습니다. 시트를 ' + r.client_email + ' 에 "뷰어"로 공유하세요.', 'ok');
        });
      });
    };
    $('dsKeyRemove').onclick = function () {
      confirmBox('서비스 계정 키 삭제', '<p>등록된 키 파일을 지웁니다. 다시 등록하기 전까지 시트에서 새로 불러올 수 없습니다 (마지막 정상 데이터는 유지).</p>', '삭제')
        .then(function (ok) { if (ok) { api('data_key_remove').then(function () { $('dsKeyEmail').textContent = '없음'; }); } });
    };
    $('dsXlsx').onchange = function () {
      readFile(this, true, 10 * 1048576).then(function (url) {
        toast('파일을 읽는 중…');
        api('data_import_xlsx', { file: String(url).replace(/^data:[^,]*,/, '') }).then(function (r) {
          toast('xlsx를 가져왔습니다. 자동값 변경 ' + r.changed + '건', 'ok');
          dataTab('check');
        });
      });
    };
    $('btnExport').onclick = exportRundown;
    $('btnImport').onclick = function () { $('fileImport').value = ''; $('fileImport').click(); };
    $('fileImport').onchange = function () { if (this.files[0]) { importRundown(this.files[0]); } };
    $('btnZoom').onclick = function () {
      var zoom = !$('pvwFrame').classList.contains('zoom');
      $('pvwFrame').classList.toggle('zoom', zoom);
      $('pgmFrame').classList.toggle('zoom', zoom);
      this.textContent = zoom ? '전체 화면 보기' : 'CG 확대 보기';
    };
    var setDisplay = function (live) {
      api('set_display', { right: $('dRight').value, bottom: $('dBottom').value, scale_pct: $('dScale').value, live: live })
        .then(function (r) {
          toast(r.live ? '위치·크기를 송출 화면에도 바로 반영했습니다.'
            : live ? '송출 중인 CG가 없어 PREVIEW에만 반영했습니다.' : '위치·크기를 적용했습니다. PROGRAM은 다음 TAKE부터 바뀝니다.', 'ok');
        });
    };
    $('btnDisplay').onclick = function () { setDisplay(false); };
    $('btnDisplayLive').onclick = function () { setDisplay(true); };
    $('btnNewSession').onclick = function () {
      $('keepCount').textContent = S.keep_count;
      $('sessNameInput').value = new Date().toISOString().slice(0, 10) + ' 방송';
      $('dlgSession').returnValue = '';
      $('dlgSession').showModal();
    };
    $('dlgSession').addEventListener('close', function () {
      if (this.returnValue === 'ok') {
        api('new_session', { name: $('sessNameInput').value }).then(function (r) {
          dirty = {}; toast('새 세션을 시작했습니다. 이월된 수정값 ' + r.carried + '개', 'ok');
        });
      }
    });
    // 창을 닫은 뒤 포커스가 창을 연 버튼에 남으면 번호+Enter 큐가 그 버튼 클릭이 되므로 풀어 둔다
    Array.prototype.forEach.call(document.querySelectorAll('dialog'), function (d) {
      d.addEventListener('close', function () {
        if (!dialogOpen() && document.activeElement && document.activeElement.blur) { document.activeElement.blur(); }
      });
    });
    $('dlgPage').addEventListener('close', function () { if (this.returnValue === 'ok') { submitPage(); } });
    $('pTemplate').onchange = function () { buildParams(this.value, null); };
    $('pParams').addEventListener('change', function (e) {
      if (e.target.getAttribute('data-type') === 'player') { autoRace(e.target); }
    });

    $('rdBody').addEventListener('click', function (e) {
      var tr = e.target.closest('tr');
      if (!tr) { return; }
      var id = parseInt(tr.getAttribute('data-id'), 10);
      var act = e.target.getAttribute('data-act');
      if (!act) { cue(parseInt(tr.getAttribute('data-no'), 10)); return; }
      var row = rowById(id);
      if (act === 'up' || act === 'down') { api('page_move', { id: id, dir: act === 'up' ? -1 : 1 }); }
      if (act === 'edit') { openPage(row); }
      if (act === 'copy') { api('page_copy', { id: id }).then(function (r) { toast(pad3(r.page_no) + ' 페이지로 복제했습니다.', 'ok'); }); }
      if (act === 'del') {
        confirmBox('페이지 삭제', '<p>' + pad3(row.page_no) + ' · ' + esc(row.summary) + '</p>'
          + (row.in_program ? '<p>이 페이지는 PROGRAM에 있습니다. 삭제해도 송출 화면은 그대로 유지됩니다.</p>' : ''), '삭제')
          .then(function (ok) { if (ok) { api('page_remove', { id: id }); } });
      }
    });
    $('rdBody').addEventListener('dblclick', function (e) {
      var tr = e.target.closest('tr');
      if (tr && !e.target.getAttribute('data-act')) { openPage(rowById(parseInt(tr.getAttribute('data-id'), 10))); }
    });

    $('edBody').addEventListener('input', function (e) {
      if (!e.target.classList.contains('val')) { return; }
      var key = e.target.getAttribute('data-key');
      var f = S.preview.fields.filter(function (x) { return x.key === key; })[0];
      if (f && e.target.value === f.manual_text) { delete dirty[key]; } else { dirty[key] = e.target.value; }
      e.target.classList.toggle('dirty', dirty[key] !== undefined);
      renderButtons();
    });
    $('edBody').addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && e.target.classList.contains('val') && !e.isComposing) { save(); }
      if (e.key === 'Escape' && e.target.classList.contains('val')) { e.target.blur(); }
    });
    $('edBody').addEventListener('click', function (e) {
      var key = e.target.getAttribute('data-key');
      if (!key) { return; }
      if (e.target.classList.contains('reset')) {
        var f = S.preview.fields.filter(function (x) { return x.key === key; })[0];
        delete dirty[key];
        if (f && f.has_manual) { api('reset', { instance_id: S.preview.instance_id, field: key }); }
        else { renderEditor(); renderButtons(); }
      }
      if (e.target.classList.contains('keep')) {
        api('set_keep', { instance_id: S.preview.instance_id, field: key, keep: e.target.checked });
      }
    });

    $('urlList').addEventListener('click', function (e) {
      var u = e.target.getAttribute('data-url');
      if (u) { copyText(u); }
    });
    $('urlList').addEventListener('keydown', function (e) {
      var u = e.target.getAttribute('data-url');
      if (u && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); copyText(u); }
    });

    // 효과 설정은 이 PC 브라우저에만 기억한다
    try {
      var saved = JSON.parse(window.localStorage.getItem('cg-fx') || '{}');
      if (saved.fx) { $('fx').value = saved.fx; }
      if (saved.dur) { $('fxDur').value = saved.dur; }
      $('autoNext').checked = !!saved.autoNext;
    } catch (e) { /* 저장소를 쓸 수 없으면 기본값 */ }
    ['fx', 'fxDur', 'autoNext'].forEach(function (id) {
      $(id).addEventListener('change', function () {
        try {
          window.localStorage.setItem('cg-fx', JSON.stringify({ fx: $('fx').value, dur: $('fxDur').value, autoNext: $('autoNext').checked }));
        } catch (e) { /* 무시 */ }
      });
    });

    // 버튼을 누른 뒤 포커스를 풀어 Space가 버튼을 다시 누르지 않게 한다
    document.addEventListener('click', function (e) {
      if (e.target.closest && e.target.closest('button') && !e.target.closest('dialog')) { e.target.closest('button').blur(); }
    });
    document.addEventListener('keydown', onKey);
  }

  function setPageBuf(v) {
    pageBuf = v;
    $('pageBuf').textContent = (pageBuf + '___').slice(0, 3);
    $('pageBuf').parentNode.classList.toggle('typing', pageBuf !== '');
    window.clearTimeout(pageBufTimer);
    if (pageBuf) { pageBufTimer = window.setTimeout(function () { setPageBuf(''); }, 6000); }
  }

  /* 토네이도식 단축키. 입력 중·한글 조합 중·키 반복·대화상자가 열려 있을 때는 동작하지 않는다. */
  function onKey(e) {
    if ((e.ctrlKey || e.metaKey) && (e.key === 's' || e.key === 'S')) {
      e.preventDefault();
      save();
      return;
    }
    var t = e.target;
    var typing = t && (t.tagName === 'INPUT' || t.tagName === 'SELECT' || t.tagName === 'TEXTAREA' || t.isContentEditable);
    if (e.key === 'F5') { e.preventDefault(); }
    if (e.defaultPrevented) { return; } // 주소 복사 등 다른 곳에서 처리한 키
    // 포커스된 버튼·주소 칸에서 Space/Enter는 그 요소의 동작만 한다 (TAKE가 같이 실행되지 않게)
    var focusable = t && t !== document.body && (t.tagName === 'BUTTON' || t.tagName === 'A' || t.hasAttribute('tabindex'));
    if (focusable && (e.key === ' ' || e.key === 'Enter')) { return; }
    if (e.isComposing || e.repeat || dialogOpen() || typing || e.ctrlKey || e.altKey || e.metaKey || !S) {
      return;
    }
    var handled = true;
    switch (e.key) {
      case 'F1': case ' ': take(); break;
      case 'F2': out(); break;
      case 'F3': show(); break;
      case 'F4': case 'ArrowDown': step(1); break;
      case 'ArrowUp': step(-1); break;
      case 'F5': refresh(false); break;
      case 'Enter': if (pageBuf) { cue(parseInt(pageBuf, 10)); setPageBuf(''); } break;
      case 'Escape': setPageBuf(''); break;
      case 'Backspace': setPageBuf(pageBuf.slice(0, -1)); break;
      default:
        if (/^[0-9]$/.test(e.key)) { setPageBuf((pageBuf.length >= 3 ? '' : pageBuf) + e.key); } else { handled = false; }
    }
    if (handled) { e.preventDefault(); }
  }

  bind();
  window.setInterval(tickClock, 500);
  // 자동 새로고침: Google 시트는 5분마다 (요청 수 절약), 테스트용 MOCK은 1분. 시트 주소·키가 없으면(파일 가져오기만 쓰는 경우) 하지 않는다
  var lastAuto = Date.now();
  window.setInterval(function () {
    if (!S || !$('autoRefresh').checked || !S.data.ready) { return; }
    if (S.data.source === 'sheet' && Date.now() - lastAuto < 290000) { return; }
    lastAuto = Date.now();
    refresh(true);
  }, 60000);
  poll();
})();
