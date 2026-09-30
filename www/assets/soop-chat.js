/**
 * SOOP 라이브 채팅 수신 모듈 (브라우저용)
 *
 * SOOP 공식 API 가 아니라 SOOP 웹 플레이어가 쓰는 채팅 통신 방식을 따라 만든 것입니다.
 * SOOP 쪽 방식이 바뀌면 이 파일을 수정해야 합니다.
 * 참고: github.com/joyfuI/soop-chat (MIT) 의 프로토콜 조사 문서
 *
 * 패킷 구조: ESC TAB | 명령번호 4자리 | 본문 길이 6자리(UTF-8 바이트) | 00 | 본문(항목 구분자 0x0C)
 */
(function (global) {
  'use strict';

  var F = '\x0c';
  var ESC = 0x1b;
  var TAB = 0x09;
  var HEADER_SIZE = 14;
  var encoder = new TextEncoder();
  var decoder = new TextDecoder();

  // 사용자 배지 (서버 BADGE_* 상수와 같은 값)
  var BADGE = { BJ: 1, MANAGER: 2, TOPFAN: 4, FAN: 8, SUBSCRIBER: 16, ADMIN: 32 };

  // ── 패킷 만들기 ────────────────────────────────────────────
  function encodePacket(opcode, payload) {
    var body = encoder.encode(payload);
    var header = encoder.encode('\x1b\t' + opcode + String(body.length).padStart(6, '0') + '00');
    var out = new Uint8Array(header.length + body.length);
    out.set(header);
    out.set(body, header.length);
    return out;
  }
  function connectPacket() { return encodePacket('0001', F + F + F + '16' + F); }
  function joinPacket(chatNo) { return encodePacket('0002', F + chatNo + F + F + F + F + F); }
  function keepAlivePacket() { return encodePacket('0000', F); }

  // ── 패킷 읽기 (여러 패킷이 한 번에 오거나 나뉘어 와도 처리) ──
  function PacketParser() { this.buf = new Uint8Array(0); }
  PacketParser.prototype.reset = function () { this.buf = new Uint8Array(0); };
  PacketParser.prototype.push = function (chunk) {
    var merged = new Uint8Array(this.buf.length + chunk.length);
    merged.set(this.buf);
    merged.set(chunk, this.buf.length);
    this.buf = merged;
    var packets = [];
    while (this.buf.length >= 2) {
      if (this.buf[0] !== ESC || this.buf[1] !== TAB) {
        var next = -1;
        for (var i = 1; i < this.buf.length - 1; i++) {
          if (this.buf[i] === ESC && this.buf[i + 1] === TAB) { next = i; break; }
        }
        this.buf = next >= 0 ? this.buf.slice(next) : (this.buf[this.buf.length - 1] === ESC ? this.buf.slice(-1) : new Uint8Array(0));
        continue;
      }
      if (this.buf.length < HEADER_SIZE) break;
      var head = String.fromCharCode.apply(null, this.buf.slice(2, HEADER_SIZE));
      var opcode = head.slice(0, 4);
      var lenText = head.slice(4, 10);
      if (!/^\d{4}$/.test(opcode) || !/^\d{6}$/.test(lenText) || !/^\d{2}$/.test(head.slice(10, 12))) {
        this.buf = this.buf.slice(2);
        continue;
      }
      var total = HEADER_SIZE + Number(lenText);
      if (this.buf.length < total) break;
      var text = decoder.decode(this.buf.slice(HEADER_SIZE, total));
      this.buf = this.buf.slice(total);
      var first = text.indexOf(F);
      packets.push({ opcode: opcode, fields: (first >= 0 ? text.slice(first + 1) : text).split(F) });
    }
    return packets;
  };

  // ── 사용자 권한 표시 ───────────────────────────────────────
  // 기존 끝장전 시스템이 SOOP 채팅 화면과 대조해 확정한 규칙 (2026-09-10):
  //  팬 = flag1 의 0x20, 열혈 = flag1 의 0x8000,
  //  구독 = 구독 개월 필드가 0 이상 정수 (미구독은 -1). flag2 비트로 판정하면 틀림.
  function badgesFromFlag(flag, subMonth) {
    var f1 = parseInt(String(flag || '').split('|')[0], 10) || 0;
    var b = 0;
    if (f1 & 4) b |= BADGE.BJ;
    if ((f1 & 256) || (f1 & 64)) b |= BADGE.MANAGER;
    if (f1 & 1) b |= BADGE.ADMIN;
    if (f1 & 0x8000) b |= BADGE.TOPFAN;
    if (f1 & 0x20) b |= BADGE.FAN;
    if (/^\d+$/.test(String(subMonth == null ? '' : subMonth).trim())) b |= BADGE.SUBSCRIBER;
    return b;
  }

  /** 방송국 아이디 비교용: 소문자, 끝의 (2) 같은 접속 번호 제거 */
  function normId(v) {
    return String(v || '').trim().toLowerCase().replace(/\(\d+\)$/, '');
  }

  function int(v) { var n = parseInt(v, 10); return isFinite(n) ? n : 0; }
  function need(f, n) { return f.length >= n; }

  /** ch = 선물을 받은 방송국 아이디 (다른 방송국으로 간 선물을 거르는 데 씀, 모르면 '') */
  function donation(ch, ty, st, userId, nick, amount, extra) {
    var d = { kind: 'donation', ch: ch || '', ty: ty, st: st, u: userId || '', n: nick || '', a: amount };
    if (extra) {
      for (var k in extra) if (Object.prototype.hasOwnProperty.call(extra, k)) d[k] = extra[k];
    }
    return d;
  }

  /**
   * 패킷 → 이벤트. 수집 대상이 아니면 null.
   *  {kind:'login'} {kind:'join', chatNo} {kind:'close'}
   *  {kind:'chat', u, n, m, kd, b}
   *  {kind:'donation', ch, ty, st, u, n, a, tu?, tn?, x?}
   */
  function decodePacket(p) {
    var f = p.fields;
    switch (p.opcode) {
      case '0001': return need(f, 2) ? { kind: 'login' } : null;
      case '0002': return need(f, 7) ? { kind: 'join', chatNo: f[0] } : null;
      case '0088': return { kind: 'close' };

      // 일반 채팅
      case '0005':
        if (!need(f, 8) || !f[1]) return null;
        return { kind: 'chat', u: f[1], n: f[5], m: f[0].replace(/\r/g, ''), kd: 'chat', b: badgesFromFlag(f[6], f[7]) };
      // OGQ 이모티콘 채팅
      case '0109':
        if (!need(f, 12) || !f[5]) return null;
        return { kind: 'chat', u: f[5], n: f[6], m: f[1] ? f[1].replace(/\r/g, '') : '[이모티콘]', kd: 'emoticon', b: badgesFromFlag(f[7], f[12]) };

      // 별풍선
      case '0018': return need(f, 10) ? donation(f[0], 'balloon', 'normal', f[1], f[2], int(f[3])) : null;
      case '0033': return need(f, 11) ? donation(f[1], 'balloon', 'relay', f[3], f[4], int(f[5])) : null;
      case '0105': return need(f, 14) ? donation(f[1], 'balloon', 'video', f[2], f[3], int(f[4])) : null;
      // 도전미션·대결미션 후원 (일반 별풍선과 따로 옴)
      case '0121': {
        var j;
        try { j = JSON.parse(f[0] || ''); } catch (e) { return null; }
        if (!j || typeof j !== 'object') return null;
        var type = String(j.type || '').toUpperCase();
        if (type !== 'CHALLENGE_GIFT' && type !== 'GIFT') return null;
        return donation(String(j.bj_id || ''), 'balloon', type === 'GIFT' ? 'battle' : 'mission', String(j.user_id || ''), String(j.user_nick || ''),
          int(j.gift_count), { x: String(j.title || '').slice(0, 200) });
      }

      // 애드벌룬
      case '0087': return need(f, 18) ? donation(f[1], 'adballoon', 'normal', f[2], f[3], int(f[9])) : null;
      case '0107': return need(f, 9) ? donation(f[0], 'adballoon', 'station', f[1], f[2], int(f[3])) : null;

      // 구독
      case '0091': return need(f, 8) ? donation(f[1], 'subscription', 'new', f[2], f[3], 1, { x: tierLabel(f[7]) }) : null;
      case '0093': return need(f, 8) ? donation(f[0], 'subscription', 'renew', f[1], f[2], int(f[3]), { x: tierLabel(f[7]) }) : null;
      case '0108': return need(f, 14) ? donation(f[5], 'subscription', 'gift', f[1], f[2], 1, { tu: f[3], tn: f[4] }) : null;
      case '0142': return need(f, 6) ? donation('', 'subscription', 'gift_random', f[0], f[1], int(f[3])) : null;
    }
    return null;
  }

  function tierLabel(v) {
    var t = int(v);
    return t === 1 ? '기본' : t === 2 ? '플러스' : '';
  }

  /**
   * 채팅 연결 관리
   * options: streamerId, resolve(streamerId) → Promise<{chat_domain, chat_port, chat_no, ...}>
   *          onEvent(ev), onStatus(state, message), onLog(message), keepWaiting() → bool
   * resolve 가 실패하면 {message, retryable, reason} 형태의 오류를 던집니다.
   */
  function SoopChatClient(options) {
    this.o = options;
    this.running = false;
    this.ws = null;
    this.parser = new PacketParser();
    this.retry = 0;
    this.timers = {};
  }

  SoopChatClient.prototype.start = function () {
    if (this.running) return;
    this.running = true;
    this.retry = 0;
    this._connect();
  };

  SoopChatClient.prototype.stop = function () {
    this.running = false;
    this._clearTimers();
    this._closeSocket();
    this._status('stopped', '수집을 멈췄습니다.');
  };

  SoopChatClient.prototype._status = function (state, message) {
    if (this.o.onStatus) this.o.onStatus(state, message || '');
  };
  SoopChatClient.prototype._log = function (message) {
    if (this.o.onLog) this.o.onLog(message);
  };
  SoopChatClient.prototype._clearTimers = function () {
    for (var k in this.timers) { clearTimeout(this.timers[k]); clearInterval(this.timers[k]); }
    this.timers = {};
  };
  SoopChatClient.prototype._closeSocket = function () {
    if (this.ws) {
      var ws = this.ws;
      this.ws = null;
      ws.onopen = ws.onmessage = ws.onclose = ws.onerror = null;
      try { ws.close(1000); } catch (e) { /* 무시 */ }
    }
  };

  SoopChatClient.prototype._scheduleReconnect = function (delayMs, message) {
    if (!this.running) return;
    this._clearTimers();
    this._closeSocket();
    var self = this;
    this._status('waiting', message + ' ' + Math.round(delayMs / 1000) + '초 후 다시 연결합니다.');
    this.timers.reconnect = setTimeout(function () { self._connect(); }, delayMs);
  };

  SoopChatClient.prototype._backoff = function () {
    this.retry++;
    return Math.min(60000, 2000 * Math.pow(2, Math.min(this.retry - 1, 5)));
  };

  SoopChatClient.prototype._connect = function () {
    var self = this;
    if (!this.running) return;
    this._clearTimers();
    this._closeSocket();
    this.parser.reset();
    this._status('resolving', 'SOOP 방송 정보를 확인하는 중…');

    Promise.resolve()
      .then(function () { return self.o.resolve(self.o.streamerId); })
      .then(function (info) {
        if (!self.running) return;
        self.info = info;
        self._open(info);
      })
      .catch(function (err) {
        if (!self.running) return;
        var msg = (err && err.message) || '방송 정보를 가져오지 못했습니다.';
        if (err && err.reason === 'offline') {
          if (self.o.keepWaiting && self.o.keepWaiting()) {
            self.retry = 0;
            self._scheduleReconnect(30000, msg);
          } else {
            self.running = false;
            self._status('offline', msg);
          }
          return;
        }
        if (err && err.retryable === false) {
          self.running = false;
          self._status('error', msg);
          return;
        }
        self._scheduleReconnect(self._backoff(), msg);
      });
  };

  SoopChatClient.prototype._open = function (info) {
    var self = this;
    var url = 'wss://' + String(info.chat_domain).toLowerCase() + ':' + (Number(info.chat_port) + 1) + '/Websocket/' + encodeURIComponent(this.o.streamerId);
    this._status('connecting', '채팅 서버에 연결하는 중…');
    this._log('채팅 서버 연결: ' + info.chat_domain + ' (방송번호 ' + info.broadcast_no + ')');
    var ws;
    try {
      ws = new WebSocket(url, 'chat');
    } catch (e) {
      this._scheduleReconnect(this._backoff(), '채팅 서버 연결 실패.');
      return;
    }
    ws.binaryType = 'arraybuffer';
    this.ws = ws;
    var joined = false;
    var loggedIn = false;

    this.timers.handshake = setTimeout(function () {
      if (!joined) self._scheduleReconnect(self._backoff(), '채팅방 입장 응답이 없습니다.');
    }, 20000);

    ws.onopen = function () { ws.send(connectPacket()); };
    ws.onerror = function () { self._log('채팅 서버 통신 오류'); };
    ws.onclose = function (ev) {
      if (self.ws !== ws) return;
      self._log('채팅 서버 연결 끊김 (코드 ' + ev.code + ')');
      self._scheduleReconnect(joined ? 2000 : self._backoff(), '채팅 서버 연결이 끊겼습니다.');
    };
    ws.onmessage = function (ev) {
      if (self.ws !== ws) return;
      var bytes = typeof ev.data === 'string' ? encoder.encode(ev.data) : new Uint8Array(ev.data);
      var packets = self.parser.push(bytes);
      for (var i = 0; i < packets.length; i++) {
        var e = decodePacket(packets[i]);
        if (!e) continue;
        if (e.kind === 'login') {
          if (!loggedIn) {
            loggedIn = true;
            ws.send(joinPacket(info.chat_no));
          }
        } else if (e.kind === 'join') {
          if (!loggedIn || joined) continue;
          if (e.chatNo !== String(info.chat_no)) {
            self._scheduleReconnect(self._backoff(), '채팅방 번호가 맞지 않습니다.');
            return;
          }
          joined = true;
          self.retry = 0;
          clearTimeout(self.timers.handshake);
          self.timers.keepAlive = setInterval(function () {
            if (ws.readyState === 1) ws.send(keepAlivePacket());
          }, 60000);
          self._status('live', '채팅 수집 중');
          if (self.o.onJoined) self.o.onJoined(info);
        } else if (e.kind === 'close') {
          self._log('방송 종료 신호를 받았습니다.');
          if (self.o.keepWaiting && self.o.keepWaiting()) {
            self.retry = 0;
            self._scheduleReconnect(30000, '방송이 종료되었습니다. 재시작을 기다립니다.');
          } else {
            self.running = false;
            self._clearTimers();
            self._closeSocket();
            self._status('ended', '방송이 종료되어 수집을 마쳤습니다.');
          }
          return;
        } else if (joined && self.o.onEvent) {
          // 다른 방송국으로 간 선물은 집계하지 않습니다. (방송국 필드가 비어 있으면 통과)
          if (e.kind === 'donation' && e.ch && normId(e.ch) !== normId(self.o.streamerId)) continue;
          self.o.onEvent(e);
        }
      }
    };
  };

  var api = {
    BADGE: BADGE,
    encodePacket: encodePacket,
    connectPacket: connectPacket,
    joinPacket: joinPacket,
    keepAlivePacket: keepAlivePacket,
    PacketParser: PacketParser,
    decodePacket: decodePacket,
    badgesFromFlag: badgesFromFlag,
    normId: normId,
    SoopChatClient: SoopChatClient
  };
  if (typeof module === 'object' && module.exports) module.exports = api;
  else global.SoopChat = api;
})(typeof window !== 'undefined' ? window : globalThis);
