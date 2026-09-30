/**
 * 실시간 수집 화면
 *  - SOOP 채팅 서버에서 채팅·후원을 받아
 *  - 서버(api/ingest.php)에 2초마다 모아서 저장하고
 *  - 같은 내용을 브라우저 저장소(IndexedDB)에도 백업합니다.
 */
(function () {
  'use strict';

  var root = document.getElementById('collector');
  if (!root || !window.SoopChat) return;

  var BID = Number(root.dataset.broadcastId);
  var STREAMER = root.dataset.streamerId;
  var CSRF = document.querySelector('meta[name="csrf-token"]').content;
  var COLLECTOR_ID = 'c' + Date.now().toString(36) + Math.random().toString(36).slice(2, 8);
  var BATCH = 300;

  var $ = function (id) { return document.getElementById(id); };
  var seq = 0;
  var clockOffset = 0;      // 서버 시각 - 이 PC 시각 (밀리초)
  var clockSamples = 0;
  var pending = [];         // 서버에 아직 저장되지 않은 이벤트
  var client = null;
  var state = { status: 'idle', message: '', bno: '' };
  var stats = { chat: 0, balloon: 0, ad: 0, sub: 0, saved: 0, dup: 0, last: null, backup: 0 };
  var chatFeed = [];
  var donFeed = [];
  var dirty = true;
  var wakeLock = null;

  // ── 공통 ─────────────────────────────────────────────────
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function pad(n) { return (n < 10 ? '0' : '') + n; }
  // PC 시간대와 관계없이 한국 시간으로 표시 (서버 기록과 같은 기준)
  function hms(ms) { var d = new Date(ms + 9 * 3600000); return pad(d.getUTCHours()) + ':' + pad(d.getUTCMinutes()) + ':' + pad(d.getUTCSeconds()); }
  function num(n) { return Number(n || 0).toLocaleString('ko-KR'); }
  function now() { return Math.round(Date.now() + clockOffset); }

  function syncClock(serverMs, t0, t1) {
    if (!serverMs || t1 - t0 > 3000) return;
    var estimate = serverMs - (t0 + t1) / 2;
    clockOffset = clockSamples === 0 ? estimate : clockOffset * 0.8 + estimate * 0.2;
    clockSamples++;
  }

  function log(message) {
    var li = document.createElement('li');
    li.textContent = hms(Date.now()) + '  ' + message;
    var ul = $('c-log');
    ul.insertBefore(li, ul.firstChild);
    while (ul.children.length > 80) ul.removeChild(ul.lastChild);
  }

  var DON_LABEL = {
    'balloon/normal': '별풍선', 'balloon/relay': '별풍선(중계방)', 'balloon/video': '영상풍선',
    'balloon/mission': '도전미션 별풍선', 'balloon/battle': '대결미션 별풍선',
    'adballoon/normal': '애드벌룬', 'adballoon/station': '방송국 애드벌룬',
    'subscription/new': '구독', 'subscription/renew': '연속 구독', 'subscription/gift': '구독 선물',
    'subscription/gift_random': '랜덤 구독 선물'
  };
  function donAmount(e) {
    if (e.ty === 'balloon' || e.ty === 'adballoon') return num(e.a) + '개';
    if (e.st === 'renew') return num(e.a) + '개월';
    if (e.st === 'gift_random') return num(e.a) + '명';
    return '';
  }

  // ── 브라우저 백업 (IndexedDB) ─────────────────────────────
  var idb = { db: null, queue: [], timer: null };

  function idbOpen() {
    return new Promise(function (resolve) {
      if (!('indexedDB' in window)) return resolve(null);
      var req;
      try { req = indexedDB.open('endgame-collector', 1); } catch (e) { return resolve(null); }
      req.onupgradeneeded = function () {
        var st = req.result.createObjectStore('events', { keyPath: 'uid' });
        st.createIndex('bid', 'bid');
        st.createIndex('bid_sent', ['bid', 'sent']);
      };
      req.onsuccess = function () { resolve(req.result); };
      req.onerror = function () { resolve(null); };
      req.onblocked = function () { resolve(null); };
    });
  }

  // 저장 요청을 모아서 0.3초마다 한 번에 기록 (순서 유지)
  function idbEnqueue(ev, sent) {
    if (!idb.db) return;
    var rec = Object.assign({}, ev, { bid: BID, sent: sent });
    idb.queue.push(rec);
    if (!idb.timer) idb.timer = setTimeout(idbFlush, 300);
  }
  function idbFlush() {
    idb.timer = null;
    if (!idb.db || !idb.queue.length) return;
    var items = idb.queue;
    idb.queue = [];
    try {
      var tx = idb.db.transaction('events', 'readwrite');
      var st = tx.objectStore('events');
      for (var i = 0; i < items.length; i++) st.put(items[i]);
      tx.oncomplete = function () { refreshBackupCount(); };
      tx.onerror = function () { log('브라우저 백업 저장 실패: ' + (tx.error && tx.error.message)); };
    } catch (e) {
      log('브라우저 백업 저장 실패: ' + e.message);
    }
  }
  function idbCount(range, indexName) {
    return new Promise(function (resolve) {
      if (!idb.db) return resolve(0);
      var req = idb.db.transaction('events').objectStore('events').index(indexName).count(range);
      req.onsuccess = function () { resolve(req.result); };
      req.onerror = function () { resolve(0); };
    });
  }
  function refreshBackupCount() {
    idbCount(IDBKeyRange.only(BID), 'bid').then(function (n) { stats.backup = n; dirty = true; });
  }
  function idbEach(indexName, range, fn, done) {
    var req = idb.db.transaction('events', 'readonly').objectStore('events').index(indexName).openCursor(range);
    req.onsuccess = function () {
      var cur = req.result;
      if (cur) { fn(cur.value); cur.continue(); } else done();
    };
    req.onerror = function () { done(req.error); };
  }

  // 이전 수집에서 서버에 못 보낸 것을 다시 보낼 목록에 넣습니다.
  function restoreUnsent() {
    if (!idb.db) return;
    var restored = [];
    idbEach('bid_sent', IDBKeyRange.only([BID, 0]), function (rec) {
      var ev = Object.assign({}, rec);
      delete ev.bid; delete ev.sent;
      restored.push(ev);
    }, function () {
      if (restored.length) {
        restored.sort(function (a, b) { return a.t - b.t; });
        // 전송 중인 묶음이 앞쪽에 있을 수 있으므로 뒤에 붙입니다.
        for (var i = 0; i < restored.length; i++) pending.push(restored[i]);
        log('이전 수집에서 서버에 저장되지 않은 ' + num(restored.length) + '건을 다시 보냅니다.');
        dirty = true;
      }
    });
  }

  function downloadBackup() {
    if (!idb.db) { alert('이 브라우저에는 백업이 없습니다.'); return; }
    idbFlush();
    var parts = [];
    var count = 0;
    idbEach('bid', IDBKeyRange.only(BID), function (rec) {
      var ev = Object.assign({}, rec);
      delete ev.bid; delete ev.sent;
      parts.push(JSON.stringify(ev) + '\n');
      count++;
    }, function () {
      if (!count) { alert('내려받을 백업이 없습니다.'); return; }
      var d = new Date();
      var stamp = d.getFullYear() + pad(d.getMonth() + 1) + pad(d.getDate()) + '-' + pad(d.getHours()) + pad(d.getMinutes());
      parts.unshift(JSON.stringify({ format: 'endgame-backup', version: 1, broadcast_id: BID, streamer_id: STREAMER, exported_at: d.toISOString(), count: count }) + '\n');
      var blob = new Blob(parts, { type: 'application/x-ndjson' });
      var name = 'endgame-backup-' + BID + '-' + stamp;
      if (window.CompressionStream) {
        new Response(blob.stream().pipeThrough(new CompressionStream('gzip'))).blob()
          .then(function (gz) { saveFile(gz, name + '.jsonl.gz'); })
          .catch(function () { saveFile(blob, name + '.jsonl'); });
      } else {
        saveFile(blob, name + '.jsonl');
      }
      log('백업 파일 ' + num(count) + '건을 내려받았습니다.');
    });
  }
  function saveFile(blob, name) {
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = name;
    document.body.appendChild(a);
    a.click();
    setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
  }

  function clearSentBackup() {
    if (!idb.db) return;
    if (!confirm('서버 저장이 끝난 백업을 이 브라우저에서 지웁니다. (미전송분은 남겨둡니다) 계속할까요?')) return;
    var tx = idb.db.transaction('events', 'readwrite');
    var req = tx.objectStore('events').index('bid_sent').openCursor(IDBKeyRange.only([BID, 1]));
    var n = 0;
    req.onsuccess = function () {
      var cur = req.result;
      if (cur) { cur.delete(); n++; cur.continue(); }
    };
    tx.oncomplete = function () { log('브라우저 백업 ' + num(n) + '건을 비웠습니다.'); refreshBackupCount(); };
  }

  // ── 서버 저장 ─────────────────────────────────────────────
  var sending = false;
  var failCount = 0;
  var nextSendAt = 0;
  var lastSentAt = 0;
  var lastError = '';
  var statusDirty = false;  // 상태가 바뀌어 서버에 알려야 함

  function setAuthLost(lost) { $('c-auth').classList.toggle('hidden', !lost); }

  function postJson(url, body) {
    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
      body: JSON.stringify(body)
    }).then(function (r) {
      return r.json().catch(function () { return null; }).then(function (j) { return { status: r.status, json: j }; });
    });
  }

  function flush() {
    if (sending || Date.now() < nextSendAt) return;
    var running = client && client.running;
    var batch = pending.slice(0, BATCH);
    if (!batch.length && !statusDirty && !(running && Date.now() - lastSentAt > 15000)) return;

    sending = true;
    statusDirty = false;
    var t0 = Date.now();
    postJson('api/ingest.php', {
      broadcast_id: BID,
      collector_id: COLLECTOR_ID,
      label: $('c-label').value.trim(),
      status: state.status,
      status_message: state.message,
      soop_broadcast_no: state.bno,
      events: batch
    }).then(function (res) {
      if (res.status === 401) { setAuthLost(true); throw new Error('로그인이 만료되었습니다.'); }
      if (!res.json || !res.json.ok) throw new Error((res.json && res.json.error) || ('서버 응답 오류 (HTTP ' + res.status + ')'));
      setAuthLost(false);
      syncClock(res.json.server_ms, t0, Date.now());
      pending.splice(0, batch.length);
      for (var i = 0; i < batch.length; i++) idbEnqueue(batch[i], 1);
      var s = res.json.saved;
      stats.saved += s.chats + s.donations;
      stats.dup += s.duplicates;
      if (batch.length) stats.last = Date.now();
      lastSentAt = Date.now();
      if (failCount > 0) log('서버 저장이 다시 정상입니다.');
      failCount = 0;
      lastError = '';
    }).catch(function (err) {
      failCount++;
      nextSendAt = Date.now() + Math.min(30000, 2000 * Math.pow(2, Math.min(failCount - 1, 4)));
      statusDirty = true;
      var msg = (err && err.message) || '네트워크 오류';
      if (msg !== lastError) log('서버 저장 실패: ' + msg + ' (자동으로 다시 시도합니다)');
      lastError = msg;
    }).then(function () {
      sending = false;
      dirty = true;
      if (pending.length >= BATCH && failCount === 0) setTimeout(flush, 100);
    });
  }

  // ── SOOP 채팅 연결 ────────────────────────────────────────
  function resolveChannel(streamerId) {
    var t0 = Date.now();
    return postJson('api/channel.php', { streamer_id: streamerId }).then(function (res) {
      var err;
      if (res.status === 401) {
        setAuthLost(true);
        err = new Error('로그인이 만료되었습니다. 새 탭에서 다시 로그인해 주세요.');
        err.retryable = true;
        throw err;
      }
      var j = res.json;
      if (!j) { err = new Error('서버 응답 오류 (HTTP ' + res.status + ')'); err.retryable = true; throw err; }
      syncClock(j.server_ms, t0, Date.now());
      if (!j.ok) { err = new Error(j.error); err.reason = j.reason; err.retryable = j.retryable; throw err; }
      state.bno = j.channel.broadcast_no;
      $('c-title').textContent = 'SOOP 방송: ' + (j.channel.title || '(제목 없음)') + (j.channel.streamer_nick ? ' · ' + j.channel.streamer_nick : '') + ' · 방송번호 ' + j.channel.broadcast_no;
      return j.channel;
    });
  }

  var STATE_LABEL = {
    idle: '대기', resolving: '확인 중', connecting: '연결 중', live: '수집 중', waiting: '재연결 대기',
    offline: '방송 없음', ended: '방송 종료', error: '오류', stopped: '중지됨'
  };

  function onStatus(status, message) {
    var changed = status !== state.status || message !== state.message;
    state.status = status;
    state.message = message;
    $('c-state').textContent = STATE_LABEL[status] || status;
    $('c-message').textContent = message;
    $('c-dot').className = 'dot ' + (status === 'live' ? 'live' : (status === 'error' ? 'error' : (['resolving', 'connecting', 'waiting'].indexOf(status) >= 0 ? 'wait' : '')));
    var running = client && client.running;
    $('c-start').disabled = !!running;
    $('c-stop').disabled = !running;
    if (changed) {
      log(STATE_LABEL[status] + (message ? ' - ' + message : ''));
      statusDirty = true; // 상태가 바뀌면 서버에 바로 알립니다.
    }
    if (!running) releaseWakeLock();
  }

  function onEvent(e) {
    var ev = { uid: COLLECTOR_ID + ':' + (++seq), t: now() };
    if (e.kind === 'chat') {
      ev.k = 'c'; ev.u = e.u; ev.n = e.n; ev.m = e.m; ev.kd = e.kd; ev.b = e.b;
      stats.chat++;
      chatFeed.push(ev);
      if (chatFeed.length > 150) chatFeed.shift();
    } else {
      ev.k = 'd'; ev.ty = e.ty; ev.st = e.st; ev.u = e.u; ev.n = e.n; ev.a = e.a;
      if (e.tu) ev.tu = e.tu;
      if (e.tn) ev.tn = e.tn;
      if (e.x) ev.x = e.x;
      if (e.ty === 'balloon') stats.balloon += e.a;
      else if (e.ty === 'adballoon') stats.ad += e.a;
      else if (e.st !== 'gift_random') stats.sub++;
      donFeed.push(ev);
      if (donFeed.length > 100) donFeed.shift();
    }
    pending.push(ev);
    idbEnqueue(ev, 0);
    dirty = true;
  }

  function start() {
    try { localStorage.setItem('endgame.collectorLabel', $('c-label').value.trim()); } catch (e) { /* 저장 불가 환경 */ }
    if (!client) {
      client = new SoopChat.SoopChatClient({
        streamerId: STREAMER,
        resolve: resolveChannel,
        onEvent: onEvent,
        onStatus: onStatus,
        onLog: log,
        keepWaiting: function () { return $('c-keepwait').checked; }
      });
    }
    client.start();
    requestWakeLock();
  }

  function stop() {
    if (!client) return;
    if (!confirm('수집을 멈출까요? 멈춘 동안의 채팅은 저장되지 않습니다.')) return;
    client.stop();
    flush();
  }

  // ── 화면 꺼짐 방지 ────────────────────────────────────────
  function requestWakeLock() {
    if (!('wakeLock' in navigator) || wakeLock) return;
    navigator.wakeLock.request('screen').then(function (lock) {
      wakeLock = lock;
      lock.addEventListener('release', function () { wakeLock = null; });
    }).catch(function () { /* 지원하지 않거나 거부됨 */ });
  }
  function releaseWakeLock() {
    if (wakeLock) { wakeLock.release().catch(function () {}); wakeLock = null; }
  }
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible' && client && client.running) requestWakeLock();
  });

  // ── 화면 그리기 ───────────────────────────────────────────
  function render() {
    if (!dirty) return;
    dirty = false;
    $('s-chat').textContent = num(stats.chat);
    $('s-balloon').textContent = num(stats.balloon);
    $('s-ad').textContent = num(stats.ad);
    $('s-sub').textContent = num(stats.sub);
    $('s-saved').textContent = num(stats.saved);
    $('s-pending').textContent = num(pending.length);
    $('s-dup').textContent = num(stats.dup);
    $('s-last').textContent = stats.last ? hms(stats.last) : '-';
    $('s-backup').textContent = num(stats.backup);

    var html = '';
    for (var i = chatFeed.length - 1; i >= 0; i--) {
      var c = chatFeed[i];
      html += '<li><span class="t">' + hms(c.t) + '</span>' + badgeHtml(c.b) + '<span class="nick">' + esc(c.n) + '</span>' +
        '<span class="uid">' + esc(c.u) + '</span><span class="msg">' + esc(c.m) + '</span></li>';
    }
    $('feed-chat').innerHTML = html;

    html = '';
    for (var j = donFeed.length - 1; j >= 0; j--) {
      var d = donFeed[j];
      html += '<li class="don don-' + d.ty + '"><span class="t">' + hms(d.t) + '</span><span class="dtype">' + esc(DON_LABEL[d.ty + '/' + d.st] || d.ty) +
        ' ' + esc(donAmount(d)) + '</span><span class="nick">' + esc(d.n || '(알 수 없음)') + '</span><span class="uid">' + esc(d.u) + '</span>' +
        (d.tn ? '<span class="msg">→ ' + esc(d.tn) + '</span>' : '') + (d.x && d.ty === 'balloon' ? '<span class="msg">' + esc(d.x) + '</span>' : '') + '</li>';
    }
    $('feed-don').innerHTML = html;
  }
  var BADGE_HTML = [[1, 'bj', '방송인'], [2, 'manager', '매니저'], [32, 'admin', '운영자'], [4, 'topfan', '열혈'], [16, 'sub', '구독'], [8, 'fan', '팬']];
  function badgeHtml(b) {
    var out = '';
    for (var i = 0; i < BADGE_HTML.length; i++) {
      if (b & BADGE_HTML[i][0]) out += '<span class="badge badge-' + BADGE_HTML[i][1] + '">' + BADGE_HTML[i][2] + '</span>';
    }
    return out;
  }

  // ── 시작 ─────────────────────────────────────────────────
  try { $('c-label').value = localStorage.getItem('endgame.collectorLabel') || ''; } catch (e) { /* 저장 불가 환경 */ }
  $('c-start').addEventListener('click', start);
  $('c-stop').addEventListener('click', stop);
  $('b-download').addEventListener('click', downloadBackup);
  $('b-clear').addEventListener('click', clearSentBackup);

  window.addEventListener('beforeunload', function (e) {
    if ((client && client.running) || pending.length) {
      e.preventDefault();
      e.returnValue = '';
    }
  });

  setInterval(flush, 2000);
  setInterval(render, 500);

  idbOpen().then(function (db) {
    idb.db = db;
    if (!db) {
      $('c-noidb').classList.remove('hidden');
      return;
    }
    refreshBackupCount();
    restoreUnsent();
  });
})();
