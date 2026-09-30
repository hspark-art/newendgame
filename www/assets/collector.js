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
  var gradeOf = {};         // 아이디 → 최근 채팅의 배지 (후원 줄 색칠용)
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
      gradeOf[SoopChat.normId(e.u)] = e.b;
      chatFeed.push(chatLine(ev));
    } else {
      ev.k = 'd'; ev.ty = e.ty; ev.st = e.st; ev.u = e.u; ev.n = e.n; ev.a = e.a;
      if (e.tu) ev.tu = e.tu;
      if (e.tn) ev.tn = e.tn;
      if (e.x) ev.x = e.x;
      if (e.ty === 'balloon') stats.balloon += e.a;
      else if (e.ty === 'adballoon') stats.ad += e.a;
      else if (e.st !== 'gift_random') stats.sub++;
      // SOOP 채팅창처럼 후원도 채팅 흐름 안에 표시하고, 오른쪽 후원 목록에도 쌓습니다.
      chatFeed.push(donationLine(ev, true));
      donFeed.push(donationLine(ev, false));
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

  // ── 채팅창 (SOOP 방식: 오래된 것 위, 새 것 아래) ─────────
  /**
   * 새 줄을 모아 두었다가 한 번에 아래에 붙입니다.
   * 맨 아래를 보고 있을 때만 따라 내려가고, 위로 올려 읽는 중이면 자리를 유지한 채 "새 채팅 N개" 버튼을 띄웁니다.
   */
  function Feed(el, button, max) {
    this.el = el;
    this.button = button;
    this.max = max;
    this.queue = [];
    this.unseen = 0;
    var self = this;
    el.addEventListener('scroll', function () {
      if (self.atBottom()) self.setUnseen(0);
    });
    button.addEventListener('click', function () {
      el.scrollTop = el.scrollHeight;
      self.setUnseen(0);
    });
  }
  Feed.prototype.atBottom = function () {
    return this.el.scrollHeight - this.el.scrollTop - this.el.clientHeight < 40;
  };
  Feed.prototype.push = function (node) { this.queue.push(node); };
  Feed.prototype.setUnseen = function (n) {
    this.unseen = n;
    this.button.classList.toggle('hidden', n === 0);
    this.button.querySelector('span').textContent = num(n);
  };
  Feed.prototype.flush = function () {
    if (!this.queue.length) return;
    var el = this.el;
    var stick = this.atBottom();
    var frag = document.createDocumentFragment();
    var added = this.queue.length;
    for (var i = 0; i < this.queue.length; i++) frag.appendChild(this.queue[i]);
    this.queue = [];
    el.appendChild(frag);
    var extra = el.children.length - this.max;
    if (extra > 0) {
      var before = el.scrollHeight;
      while (extra-- > 0) el.removeChild(el.firstChild);
      if (!stick) el.scrollTop -= before - el.scrollHeight; // 읽던 위치 유지
    }
    if (stick) {
      el.scrollTop = el.scrollHeight;
    } else {
      this.setUnseen(this.unseen + added);
    }
  };
  Feed.prototype.clear = function () {
    this.queue = [];
    this.el.textContent = '';
    this.setUnseen(0);
  };

  var chatFeed = new Feed($('feed-chat'), $('chat-new'), 2000);
  var donFeed = new Feed($('feed-don'), $('don-new'), 300);

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text != null) n.textContent = text;
    return n;
  }

  // 배지: [BJ][매] [구][열|F] — 기존 끝장전 관제 화면과 같은 모양·색
  function badgeNodes(parent, b) {
    if (b & 1) parent.appendChild(el('span', 'fb bj', 'BJ'));
    if (b & 2) parent.appendChild(el('span', 'fb mgr', '매'));
    if (b & 16) parent.appendChild(el('span', 'fb sub', '구'));
    if (b & 4) parent.appendChild(el('span', 'fb yeol', '열'));
    else if (b & 8) parent.appendChild(el('span', 'fb fan', 'F'));
  }
  // 닉네임 색: 열혈 > 구독 > 팬 > 일반
  function nickClass(b) {
    return 'nk' + (b & 4 ? ' nk-yeol' : b & 16 ? ' nk-sub' : b & 8 ? ' nk-fan' : '');
  }

  function chatLine(ev) {
    var line = el('div', 'cl');
    line.appendChild(el('span', 'pill t', hms(ev.t)));
    badgeNodes(line, ev.b);
    var nick = el('b', nickClass(ev.b), ev.n);
    nick.title = ev.u;
    line.appendChild(nick);
    line.appendChild(el('span', 'sep', ' : '));
    line.appendChild(el('span', 'msg', ev.m));
    line.dataset.u = ev.u;
    return line;
  }

  function donationLine(ev, inChat) {
    var b = gradeOf[SoopChat.normId(ev.u)] || 0;
    var line = el('div', 'cl don don-' + ev.ty);
    if (!inChat) line.appendChild(el('span', 'pill t', hms(ev.t)));
    line.appendChild(el('span', 'ico', ev.ty === 'balloon' ? '🎈' : ev.ty === 'adballoon' ? '📢' : '⭐'));
    badgeNodes(line, b);
    var nick = el('b', nickClass(b), ev.n || '(알 수 없음)');
    nick.title = ev.u;
    line.appendChild(nick);
    var amount = donAmount(ev);
    line.appendChild(el('span', 'amt', ' ' + (DON_LABEL[ev.ty + '/' + ev.st] || ev.ty) + (amount ? ' ' + amount : '')));
    if (ev.tn) line.appendChild(el('span', 'msg', ' → ' + ev.tn));
    if (ev.x && ev.ty === 'balloon') line.appendChild(el('span', 'msg muted', ' ' + ev.x));
    if (inChat) line.appendChild(el('span', 'pill t', hms(ev.t)));
    line.dataset.u = ev.u;
    return line;
  }

  // ── 채팅창 설정 (글자 크기·시각 표시·높이) ─────────────────
  function store(key, value) { try { localStorage.setItem(key, value); } catch (e) { /* 저장 불가 */ } }
  function load(key) { try { return localStorage.getItem(key); } catch (e) { return null; } }

  function applyZoom(v) {
    v = Math.max(50, Math.min(200, Number(v) || 100));
    root.style.setProperty('--cz', String(v / 100));
    $('opt-zoom').value = v;
    $('opt-zoom-v').textContent = v + '%';
  }
  applyZoom(load('endgame.chatZoom') || 100);
  $('opt-zoom').addEventListener('input', function () { applyZoom(this.value); store('endgame.chatZoom', this.value); });

  function applyTime(on) { root.classList.toggle('hide-time', !on); $('opt-time').checked = on; }
  applyTime(load('endgame.chatTime') !== '0');
  $('opt-time').addEventListener('change', function () { applyTime(this.checked); store('endgame.chatTime', this.checked ? '1' : '0'); });

  ['feed-chat', 'feed-don'].forEach(function (id) {
    var box = $(id);
    var saved = Number(load('endgame.h.' + id));
    if (saved >= 150) box.style.height = saved + 'px';
    if (window.ResizeObserver) {
      var t = null;
      new ResizeObserver(function () {
        clearTimeout(t);
        t = setTimeout(function () { if (box.offsetHeight >= 150) store('endgame.h.' + id, String(box.offsetHeight)); }, 400);
      }).observe(box);
    }
  });
  $('chat-clear').addEventListener('click', function () { chatFeed.clear(); });

  // ── 화면 갱신 ─────────────────────────────────────────────
  function render() {
    chatFeed.flush();
    donFeed.flush();
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
  setInterval(render, 250);
  // [메인 창]: 메인 창이 열려 있으면 그 창으로 이동만 하고, 없으면 새로 엽니다.
  $('c-main').addEventListener('click', function (e) {
    e.preventDefault();
    try {
      if (window.opener && !window.opener.closed) { window.opener.focus(); return; }
    } catch (err) { /* 무시 */ }
    if (!(window.EndgamePopup && window.EndgamePopup('index.php', 'endgame-main'))) location.href = 'index.php';
  });
  try { if (!window.name) window.name = 'collector-' + BID; } catch (e) { /* 무시 */ }

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
