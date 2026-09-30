/**
 * 상품 지급 화면
 *  - 표에서 바로 고치기 (칸을 벗어나면 저장, 초록 테두리 = 저장됨)
 *  - 표 복사 (엑셀·구글시트에 붙여넣기, 수령자 개인정보 제외)
 *  - 쪽지 바: 문안 템플릿 → 받는사람·내용 복사 / 쪽지 쓰기창 / 보냄 처리 / (관리자) 서버로 바로 보내기
 */
(function () {
  'use strict';

  var table = document.getElementById('prize-table');
  if (!table) return;
  var CSRF = document.querySelector('meta[name="csrf-token"]').content;
  var $ = function (id) { return document.getElementById(id); };

  function post(url, body) {
    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
      body: JSON.stringify(body)
    }).then(function (r) {
      return r.json().catch(function () { return { ok: false, error: '서버 응답 오류 (HTTP ' + r.status + ')' }; });
    });
  }
  function copyText(text, done) {
    var ok = function () { if (done) done(); };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(ok, function () { fallbackCopy(text); ok(); });
    } else {
      fallbackCopy(text);
      ok();
    }
  }
  function fallbackCopy(text) {
    var ta = document.createElement('textarea');
    ta.value = text;
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand('copy'); } catch (e) { /* 무시 */ }
    ta.remove();
  }

  // ── 표에서 바로 고치기 ────────────────────────────────────
  table.addEventListener('focusin', function (e) {
    if (e.target.classList.contains('cell')) e.target.dataset.orig = e.target.value;
  });
  // 칸에서 Enter = 저장 (표 전체가 [선택 항목 상태 변경] 양식 안이라 그대로 두면 양식이 제출됨)
  table.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && e.target.tagName === 'INPUT' && e.target.classList.contains('cell')) {
      e.preventDefault();
      e.target.blur();
    }
  });
  table.addEventListener('change', function (e) {
    var cell = e.target;
    if (!cell.classList.contains('cell')) return;
    var row = cell.closest('tr');
    cell.classList.remove('saved', 'failed');
    cell.classList.add('saving');
    post('api/prize_field.php', { id: Number(row.dataset.id), field: cell.dataset.field, value: cell.value })
      .then(function (j) {
        cell.classList.remove('saving');
        if (j.ok) {
          cell.classList.add('saved');
          if (cell.dataset.field === 'user_id') { cell.value = j.value; row.dataset.sid = j.value; }
          if (cell.dataset.field === 'nickname') row.dataset.nick = cell.value;
          cell.dataset.orig = cell.value;
        } else {
          cell.classList.add('failed');
          alert(j.error || '저장하지 못했습니다.');
          if (cell.dataset.orig !== undefined) cell.value = cell.dataset.orig;
        }
      });
  });

  // ── 표 복사 (보이는 줄, 개인정보 제외) ────────────────────
  $('copy-table').addEventListener('click', function () {
    var lines = [['등록일', '회차', 'SOOP 아이디', '닉네임', '선정 사유', '상품', '상태', '쪽지', '메모'].join('\t')];
    table.querySelectorAll('tbody tr[data-id]').forEach(function (tr) {
      var val = function (f) { var el = tr.querySelector('[data-field="' + f + '"]'); return el ? (el.tagName === 'SELECT' ? el.options[el.selectedIndex].text : el.value) : ''; };
      lines.push([tr.dataset.date, tr.dataset.bc, val('user_id'), val('nickname'), val('reason'), tr.dataset.prize, val('status'),
        tr.querySelector('.note-cell').textContent.trim(), val('memo')].map(function (v) { return String(v || '').replace(/[\t\r\n]+/g, ' '); }).join('\t'));
    });
    copyText(lines.join('\n'), function () { alert((lines.length - 1) + '줄을 복사했습니다. 엑셀이나 구글 시트에 붙여넣기(Ctrl+V) 하세요.'); });
  });

  // ── 쪽지 바 ───────────────────────────────────────────────
  var bar = $('note-bar');
  var TPL = JSON.parse(bar.dataset.templates || '{}');
  var touched = false;

  function selectedRows() {
    return Array.prototype.slice.call(table.querySelectorAll('tbody input[name="ids[]"]:checked')).map(function (cb) { return cb.closest('tr'); });
  }
  function recipients(rows) {
    var seen = {};
    return rows.map(function (r) { return r.dataset.sid; }).filter(function (id) {
      if (!id || seen[id]) return false;
      seen[id] = true;
      return true;
    });
  }
  function autoType(rows) {
    if (!rows.length) return 'tax';
    var types = rows.map(function (r) { return r.dataset.noteType || 'tax'; });
    return types.every(function (t) { return t === types[0]; }) ? types[0] : 'tax';
  }
  function fill(text, row) {
    if (!row) return text;
    return text.replace(/\{nick\}/g, row.dataset.nick || '').replace(/\{id\}/g, row.dataset.sid || '')
      .replace(/\{prize\}/g, row.dataset.prize || '').replace(/\{date\}/g, row.dataset.date || '');
  }
  function refresh() {
    var rows = selectedRows();
    bar.classList.toggle('hidden', rows.length === 0);
    if (!rows.length) return;
    $('nb-count').textContent = rows.length;
    var ids = recipients(rows);
    $('nb-ids').textContent = ids.join(', ');
    if (!touched) {
      var sel = $('nb-tpl').value;
      $('nb-text').value = TPL[sel === 'auto' ? autoType(rows) : sel] || '';
    }
  }
  table.addEventListener('change', function (e) {
    if (e.target.name === 'ids[]' || e.target.hasAttribute('data-check-all')) setTimeout(refresh, 0);
  });
  $('nb-tpl').addEventListener('change', function () { touched = false; refresh(); });
  $('nb-text').addEventListener('input', function () { touched = true; });
  $('nb-close').addEventListener('click', function () {
    table.querySelectorAll('input[name="ids[]"]:checked, input[data-check-all]').forEach(function (cb) { cb.checked = false; });
    touched = false;
    refresh();
  });
  $('nb-copy-ids').addEventListener('click', function () {
    var ids = recipients(selectedRows());
    copyText(ids.join(','), function () { status('받는사람 ' + ids.length + '명 복사됨', 'ok'); });
  });
  $('nb-copy-text').addEventListener('click', function () {
    var rows = selectedRows();
    copyText(fill($('nb-text').value, rows[0]), function () { status('내용 복사됨' + (rows.length > 1 ? ' (첫 번째 사람 기준으로 {nick} 등을 채움)' : ''), 'ok'); });
  });
  $('nb-open').addEventListener('click', function () {
    window.open(bar.dataset.writeUrl, '_blank', 'noopener');
  });
  $('nb-mark').addEventListener('click', function () {
    var rows = selectedRows();
    if (!confirm(rows.length + '명을 쪽지 보냄으로 표시할까요?')) return;
    post('api/prize_field.php', { ids: rows.map(function (r) { return Number(r.dataset.id); }), field: 'note_sent', value: '1' }).then(function (j) {
      if (!j.ok) { status(j.error || '처리하지 못했습니다.', 'bad'); return; }
      rows.forEach(function (r) { setNoteCell(r, true, '보냄 처리(직접)'); });
      status(rows.length + '명 보냄 처리 완료', 'ok');
    });
  });

  function setNoteCell(row, ok, reason) {
    var cell = row.querySelector('.note-cell');
    cell.textContent = '';
    var span = document.createElement('span');
    span.className = ok ? 'ok' : 'bad';
    span.textContent = ok ? '✓ 방금' : '실패';
    span.title = reason || '';
    cell.appendChild(span);
  }
  function status(text, cls) {
    $('nb-status').textContent = text;
    $('nb-status').className = 'small ' + (cls || '');
  }

  // 서버로 바로 보내기: 한 명씩 0.9초 간격 (기존 시스템과 같은 간격)
  var sendBtn = $('nb-send');
  if (sendBtn) {
    sendBtn.addEventListener('click', function () {
      var rows = selectedRows().filter(function (r) { return r.dataset.sid; });
      var content = $('nb-text').value;
      if (!rows.length || !content.trim()) { alert('보낼 사람과 쪽지 내용을 확인해 주세요.'); return; }
      if (!confirm(rows.length + '명에게 SOOP 쪽지를 보냅니다. 계속할까요?')) return;
      sendBtn.disabled = true;
      var okCount = 0;
      var fail = [];
      var i = 0;
      var next = function () {
        if (i >= rows.length) {
          sendBtn.disabled = false;
          status('완료: 성공 ' + okCount + '명' + (fail.length ? ', 실패 ' + fail.length + '명 (' + fail.join(', ') + ')' : ''), fail.length ? 'bad' : 'ok');
          return;
        }
        var row = rows[i++];
        status('보내는 중… ' + i + ' / ' + rows.length + ' (' + row.dataset.sid + ')', '');
        post('api/note.php', { act: 'send', prize_id: Number(row.dataset.id), content: content }).then(function (j) {
          if (j.ok) {
            okCount++;
            setNoteCell(row, true, '서버 발송 성공');
          } else {
            fail.push(row.dataset.sid + ': ' + (j.reason || j.error || '실패'));
            setNoteCell(row, false, j.reason || j.error);
            if (j.expired) {
              // 세션이 만료되면 나머지도 실패하므로 멈춥니다.
              sendBtn.disabled = false;
              status('로그인 세션이 만료되어 멈췄습니다. [설정]에서 세션을 다시 등록해 주세요. (성공 ' + okCount + '명)', 'bad');
              return;
            }
          }
          setTimeout(next, 900);
        });
      };
      next();
    });
  }
})();
