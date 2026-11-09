<?php require __DIR__ . '/auth.php'; admin_require_login(); ?>
<!DOCTYPE html><html lang="ko"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>끝장전 상품 추첨</title><style>
*{box-sizing:border-box}body{margin:0;background:#0a0d13;color:#e8ecf3;
font-family:'Pretendard','Malgun Gothic',sans-serif;font-size:14px}
.wrap{margin:0 auto;padding:14px 18px}
h1{font-size:18px;margin:4px 0 12px;display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.grid{display:grid;gap:12px;grid-template-columns:1.1fr .9fr 1fr}
@media(max-width:1100px){.grid{grid-template-columns:1fr}}
.card{background:#141821;border:1px solid #232a38;border-radius:12px;padding:12px;min-width:0}
.ct{font-weight:800;margin-bottom:8px;display:flex;gap:8px;align-items:center}
.ct .n{color:#8a93a6;font-weight:500;font-size:11.5px}
.scroll{overflow:auto;max-height:520px}
#chat{resize:vertical;max-height:none;min-height:160px}   /* 아래 모서리 드래그로 높이 조절 */
#chatInner{width:100%}
#chatInner .pill{font-size:calc(11px*var(--cz,1))}
.chatzoom{display:flex;align-items:center;gap:8px;margin:2px 0 7px;font-size:12px;color:#8a93a6}
.chatzoom input[type=range]{flex:1;height:5px;accent-color:#4aa3ff;cursor:pointer}
.chatzoom .zval{min-width:40px;text-align:right;color:#e8ecf3;font-weight:800;font-variant-numeric:tabular-nums}
.gradebar{display:flex;gap:6px;flex-wrap:wrap;margin:0 0 7px}
.gchip{cursor:pointer;font-size:11.5px;font-weight:700;padding:3px 10px;border-radius:999px;background:#161b26;border:1px solid #232a38;color:#aeb6c4;user-select:none}
.gchip:hover{border-color:#3a4557}
.gchip.on{background:#12283f;border-color:#2f6bd8;color:#dbe9ff}
.gchip b{font-variant-numeric:tabular-nums;margin-left:1px}
table{width:100%;border-collapse:collapse;font-size:13px}
td,th{padding:5px 7px;text-align:left;border-bottom:1px solid #171c25;white-space:nowrap}
th{color:#8a93a6;font-size:11px}
.num{text-align:right;font-variant-numeric:tabular-nums}
.chatline{padding:3px 4px;border-bottom:1px solid #12161e;font-size:calc(13px*var(--cz,1));
overflow:hidden;text-overflow:ellipsis;white-space:nowrap;cursor:pointer;border-radius:5px}
.chatline:hover{background:#1a2130}
.chatline b{color:#b9c1cf;font-weight:600}.balloon{color:#ffb020;font-weight:700}
/* 등급 색은 채팅창 밖(시청자 활약표)에서도 같게 씁니다 — .chatline 안에 가두지 않습니다 */
b.nk-yeol{color:#ff6b6b;font-weight:800}   /* 열혈 = 빨강(강조) */
b.nk-sub{color:#5aa9ff}                    /* 구독 = 파랑 */
b.nk-fan{color:#4ade80}                    /* 팬 = 초록, 일반 = 회색(기본) */
.kn{font-weight:700}                       /* 활약표 닉네임 — 등급이 없으면 표 기본색 그대로 */
.wtag{color:#ffd166;font-size:calc(11px*var(--cz,1));font-weight:600;margin-left:1px}
.fb{display:inline-block;font-size:calc(10px*var(--cz,1));font-weight:800;line-height:1.4;border-radius:3px;padding:0 4px;margin-right:3px;vertical-align:middle;letter-spacing:-.3px}
.fb.yeol{background:#e02e2e;color:#fff;box-shadow:0 0 0 1px #ff9a9a inset}
.fb.fan{background:#1f9d4d;color:#fff}
.fb.sub{background:#2f7fe0;color:#fff}
.dm{cursor:pointer;margin-left:5px;opacity:.32;font-size:calc(11px*var(--cz,1));vertical-align:middle;user-select:none}
.dm:hover,.chatline:hover .dm{opacity:1}
.pztoast{position:fixed;left:50%;bottom:22px;transform:translateX(-50%) translateY(12px);background:#12283f;border:1px solid #2f6bd8;color:#dbe9ff;padding:9px 15px;border-radius:9px;font-size:13px;font-weight:700;box-shadow:0 6px 22px rgba(0,0,0,.5);opacity:0;pointer-events:none;transition:.22s;z-index:9999;max-width:90vw;text-align:center}
.pztoast.on{opacity:1;transform:translateX(-50%) translateY(0)}
.wicon{display:inline-block;font-size:calc(11px*var(--cz,1));line-height:calc(16px*var(--cz,1));width:calc(17px*var(--cz,1));height:calc(17px*var(--cz,1));text-align:center;border-radius:50%;vertical-align:middle;margin-left:2px;border:1px solid rgba(0,0,0,.35);overflow:hidden}
.chatlegend{border-top:1px solid #1d2431;margin-top:8px;padding-top:7px}
.chatlegend .lgrow{line-height:2;font-size:11.5px}
button{background:#1c8cff;border:0;color:#fff;border-radius:8px;padding:8px 13px;
font-weight:700;cursor:pointer;font-family:inherit}
button.gray{background:#232a38}button.red{background:#e0392b}
button.green{background:#1f9d55}
button:disabled{opacity:.35;cursor:default}
input,select{background:#1b202b;color:#e8ecf3;border:1px solid #232a38;
border-radius:8px;padding:7px 9px;font-family:inherit;font-size:13px}
.row{display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin:6px 0}
.warn{color:#ffb020;font-weight:700}.ok{color:#4ade80}
.pill{background:#1b202b;border:1px solid #232a38;border-radius:999px;
padding:2px 9px;font-size:11.5px;color:#8a93a6}
#kingChat td:nth-child(2),#kingBal td:nth-child(2){position:relative;z-index:0}
.subhead{font-weight:800;font-size:13px;margin:10px 0 4px;color:#e8ecf3;display:flex;gap:7px;align-items:center}
.subhead .n{color:#8a93a6;font-weight:500;font-size:11px}
.actbar{position:absolute;left:0;top:2px;bottom:2px;z-index:-1;border-radius:5px;
background:linear-gradient(90deg,rgba(28,140,255,.30),rgba(255,176,32,.14));min-width:2px}
.live{color:#ff4d5a;font-weight:900}
img.thumb{width:44px;height:44px;object-fit:cover;border-radius:8px;
vertical-align:middle;margin-right:6px;background:#0a0d13}
.hint{color:#8a93a6;font-size:11.5px;line-height:1.6;margin-top:6px}
hr{border-color:#232a38}
a.top{color:#8a93a6;font-size:12.5px;text-decoration:none}
a.top:hover{color:#e8ecf3}
.modal{position:fixed;inset:0;background:rgba(4,6,10,.66);z-index:50;display:flex;
align-items:flex-start;justify-content:center;padding:40px 16px;overflow:auto}
.dupbanner{background:#3a1520;border:1px solid #ff4d5a;border-radius:10px;padding:8px 12px;margin:8px 0;font-size:12.5px;line-height:2}
.dupbanner.ok{background:#12251a;border-color:#2c6b3f;color:#9fe0b4}
#pastdays tr{cursor:pointer}#pastdays tr:hover{background:#1a2030}
.modalbox{background:#141821;border:1px solid #2a3446;border-radius:14px;
padding:16px 18px;max-width:620px;width:100%;box-shadow:0 20px 60px rgba(0,0,0,.5)}
.chip{cursor:pointer;user-select:none;background:#1b202b}
.chip.on{border-color:#1c8cff;color:#cfe6ff;background:#12283f}
.rwrow{padding:3px 3px;border-bottom:1px solid #171c25;font-size:12.5px;
white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
body.maskacc .acc{filter:blur(6px);user-select:none;pointer-events:none}
.px{background:transparent;color:#8a93a6;border:0;padding:2px 8px;font-weight:900;font-size:15px;cursor:pointer;line-height:1}
.px:hover{color:#f87171}
@media(max-width:760px){
  .wrap{padding:10px 10px}
  body{font-size:15px}
  h1{font-size:16px}
  .card{padding:11px}
  #chat{max-height:40vh !important}
  .scroll{max-height:48vh}
  #chat{height:auto;max-height:48vh;resize:none}
  input,select{font-size:16px;padding:10px 11px}
  #pickNick,#prizeSel{flex:1 1 100% !important}
  .row>button{min-height:46px;padding:12px 14px;font-size:15px}
  #kingChat td,#kingChat th,#kingBal td,#kingBal th{padding:9px 5px;font-size:14.5px}
  /* 좁은 화면에선 SOOP계정 열을 숨겨 깔끔하게 */
  #kingChat th:nth-child(3),#kingChat td:nth-child(3),
  #kingBal th:nth-child(3),#kingBal td:nth-child(3){display:none}
  #kingChat button,#kingBal button{padding:9px 14px;font-size:14px}
  .chatline{padding:9px 5px;font-size:calc(14.5px*var(--cz,1))}
  .rwrow{padding:8px 4px;font-size:14px}
  #winners td,#winners th{padding:8px 5px}
  #settingsModal .modalbox{padding:13px}
}
</style></head><body><div class="wrap">
<h1>🎁 끝장전 상품 추첨
<button id="btnStart" class="green" onclick="startSession()">▶ 스타트</button>
<button id="btnStop" class="red" onclick="stopSession()" disabled>⏹ 종료</button>
<span id="liveflag" class="pill">상태 확인 중…</span></h1>
<div class="row" style="margin:0 0 10px">
<span class="n">채널</span>
<input id="bjInput" placeholder="talent (우리 방송)" style="width:180px">
<button class="gray" onclick="goCh(document.getElementById('bjInput').value.trim())">붙기</button>
<button class="gray" onclick="goCh('')">우리 채널(talent)</button>
<button class="gray" onclick="goCh('__demo')">연습(가짜 채팅)</button>
<a class="top" href="prize_overlay.php" target="_blank">📺 방송 장면 열기 ↗</a>
<button class="gray" style="padding:4px 10px" onclick="openSettings()">⚙ 규칙 설정</button>
<a class="top" href="cg.php">CG 제작 →</a>
<span class="n">이 창을 켜 둔 동안만 채팅이 집계됩니다</span></div>
<a href="prize_sheet.php" style="display:flex;align-items:center;gap:10px;margin:0 0 12px;
padding:12px 16px;border-radius:12px;border:1px solid #2e3a52;text-decoration:none;
background:linear-gradient(90deg,rgba(28,140,255,.16),rgba(255,198,61,.10));
color:#e8ecf3;font-weight:800">&#128210; 당첨자 시트
<span style="color:#8a93a6;font-weight:500;font-size:12.5px">날짜·상품별 정리 · SOOP계정 채우기 · 숲 쪽지 일괄 발송 →</span></a>
<script>
function goCh(v){
  if(v==='__demo'){location.href='prize.php?demo';return;}
  location.href = v ? ('prize.php?bj='+encodeURIComponent(v)) : 'prize.php';
}
</script>
<div class="row" id="panelBar" style="margin:0 0 10px;gap:5px;flex-wrap:wrap"></div>
<div class="grid">

<div class="card" data-panel="chat"><div class="ct">실시간 채팅 <span class="n" id="totline"></span>
<button class="gray" style="margin-left:auto;padding:4px 10px" onclick="clearChat()" title="화면만 비웁니다 — 저장된 로그는 그대로 남습니다">채팅 지우기</button><button class="px" onclick="togglePanel('chat')" title="이 창 닫기">✕</button></div>
<div class="chatzoom"><span>글자 크기</span><input id="chatZoom" type="range" min="50" max="200" step="10" value="100" oninput="applyChatZoom(this.value)" title="채팅 글자 크기 50%~200%"><span class="zval" id="chatZoomV">100%</span></div>
<div class="gradebar" id="gradeBar"></div>
<div class="scroll" id="chat" style="height:520px"><div id="chatInner"></div></div>
<div id="chatLegend" class="chatlegend" style="display:none"></div></div>

<div class="card"><div class="ct" data-panel="users">시청자 활약 <span class="n">채팅왕 · 후원왕</span>
<button class="gray" style="margin-left:auto;padding:4px 10px" onclick="toggleMask()" id="maskBtn">🙈 계정 가리기</button>
<button class="gray" style="padding:4px 10px" onclick="downloadActivity()">⬇ 활약 CSV</button>
<button class="gray" style="padding:4px 10px" onclick="clearStats()">집계 초기화</button><button class="px" onclick="togglePanel('users')" title="이 창 닫기">✕</button></div>
<div class="hint" style="margin:-2px 0 4px">등급은 채팅창과 같은 표시입니다 —
<span class="fb yeol">열</span>열혈 · <span class="fb fan">F</span>팬클럽 · <span class="fb sub">구</span>구독
<span class="n">(채팅에서 확인된 사람만)</span></div>
<div class="subhead">💬 채팅왕 <span class="n">채팅 많은 순</span></div>
<div class="scroll" style="max-height:300px"><table id="kingChat"><thead><tr>
<th class="num">#</th><th>닉네임</th><th>SOOP계정</th><th class="num">채팅</th><th class="num">당첨</th><th></th>
</tr></thead><tbody></tbody></table></div>
<div class="subhead">👑 후원왕 <span class="n">별풍선·애드벌룬 많은 순</span></div>
<div class="scroll" style="max-height:300px"><table id="kingBal"><thead><tr>
<th class="num">#</th><th>닉네임</th><th>SOOP계정</th><th class="num">별풍선</th><th class="num">당첨</th><th></th>
</tr></thead><tbody></tbody></table></div>
<hr><div class="ct" data-panel="pastdays">지난 방송 <span class="n">저절로 저장됩니다</span><button class="gray" style="margin-left:auto;padding:3px 9px" onclick="openCumulative()" title="여러 방송을 모은 연속 출석·기간 채팅·후원 순위">📈 누적 순위</button><button class="px" onclick="togglePanel('pastdays')" title="이 창 닫기">✕</button></div>
<div class="scroll" style="max-height:150px"><table id="pastdays"><tbody></tbody></table></div></div>

<div class="card">
<div class="ct" data-panel="predict">🔮 시청자 승부예측 <span class="n">채팅 '도전' → 포인트 베팅</span><button class="px" style="margin-left:auto" onclick="togglePanel('predict')" title="이 창 닫기">✕</button></div>
<div class="row">
<button onclick="ttOpenDay()" id="ttOpenBtn">🔮 오늘 시작</button>
<span class="pill" id="ttEntry" style="font-size:12px">-</span>
<button class="gray" onclick="ttCloseDay()" id="ttCloseBtn" disabled>🏁 하루 마감</button>
<a class="top" href="../predict.php" target="_blank">리더보드 ↗</a></div>
<div class="row"><input id="ttA" placeholder="선수 A 이름" style="flex:1;min-width:80px">
<span class="n">vs</span><input id="ttB" placeholder="선수 B 이름" style="flex:1;min-width:80px"></div>
<div class="row">
<button onclick="ttRoundStart()" id="ttStartBtn" disabled>💰 베팅 오픈</button>
<button class="gray" onclick="ttLock()" id="ttLockBtn" disabled>⏸ 마감</button>
<button class="gray" onclick="ttSettle('a')" id="ttWinA" disabled>A 승</button>
<button class="gray" onclick="ttSettle('b')" id="ttWinB" disabled>B 승</button>
<button class="gray" onclick="ttCancelRound()" id="ttCancelBtn" disabled>취소</button></div>
<div id="ttBar" style="display:none">
<div style="display:flex;justify-content:space-between;font-size:12.5px;margin:2px 0">
<b id="ttCntA" style="color:#7cb6ff"></b><b id="ttCntB" style="color:#ff8fa3"></b></div>
<div style="height:12px;background:#33202a;border-radius:6px;overflow:hidden;display:flex">
<div id="ttFillA" style="background:#1c8cff;width:50%;transition:width .4s"></div>
<div style="background:#ff4d5a;flex:1"></div></div>
</div>
<div id="ttStatus" class="hint"></div>
<div id="ttFeed" style="max-height:108px;overflow:auto;font-size:12px;line-height:1.9;color:#aab3c5"></div>
<hr>
<div class="ct" data-panel="pick">당첨 만들기<button class="px" style="margin-left:auto" onclick="togglePanel('pick')" title="이 창 닫기">✕</button></div>
<div class="row"><input id="pickNick" placeholder="닉네임 — 채팅을 눌러도 들어갑니다" style="flex:1">
<select id="prizeSel" style="flex:1"></select></div>
<div id="dupwarn" class="hint"></div>
<div class="row">
<button onclick="manualPick()">지명 → 방송 장면</button>
<button class="gray" onclick="plinko()">🎯 핀볼</button>
<button class="gray" onclick="roulette()">🎡 룰렛</button>
<button class="gray" onclick="kings()">👑 오늘의 왕</button>
<button class="gray" onclick="clearOverlay()">장면 지우기</button></div>
<div class="hint">자막은 <b>자막 창</b>(위 링크)에 나옵니다 — 방송 프로그램에서 그 창을
잡으면 됩니다. 핀볼은 채팅·별풍선에 따라 확률이 조금 올라갑니다.</div>
<hr>
<div class="ct" data-panel="prizes">상품 <span class="n">사진도 넣을 수 있습니다</span><button class="px" style="margin-left:auto" onclick="togglePanel('prizes')" title="이 창 닫기">✕</button></div>
<div id="prizes"></div>
<div class="row"><input id="pName" placeholder="상품 이름" style="flex:1">
<input type="file" id="pPhoto" accept="image/*" style="display:none">
<button class="gray" onclick="document.getElementById('pPhoto').click()">사진</button>
<button onclick="addPrize()">추가</button></div>
<span id="pPhotoName" class="hint"></span>
<hr>
<div class="ct" data-panel="winners">당첨자 시트 <span class="n" id="wcount"></span>
<a class="top" href="prize_sheet.php">전체 화면 ↗</a>
<button class="gray" style="margin-left:auto;padding:4px 10px" onclick="copyLedger()">📋 복사</button>
<button class="gray" style="padding:4px 10px" onclick="downloadLedger()">⬇ CSV</button><button class="px" onclick="togglePanel('winners')" title="이 창 닫기">✕</button></div>
<div id="dupBanner" class="dupbanner" style="display:none"></div>
<div class="scroll" style="max-height:260px"><table id="winners"><thead><tr>
<th>날짜</th><th>닉네임</th><th>SOOP계정</th><th>상품</th><th>방식</th><th></th>
</tr></thead><tbody></tbody></table></div>
<div class="hint">쪽지 ✉ 는 그 계정의 SOOP 방송국을 새 탭으로 엽니다(거기서 쪽지). 계정이
비었으면 ✏로 넣으세요 — 채팅·별풍선을 친 시청자는 자동으로 채워집니다.</div>
<div class="row"><input id="wDate" placeholder="2026-08-13" style="width:104px">
<input id="wNick" placeholder="닉네임" style="flex:1">
<input id="wPrize" placeholder="상품" style="flex:1">
<button class="gray" onclick="addWinner()">지난 기록 넣기</button></div>
<hr>
<div class="ct" data-panel="recent">최근 당첨자 <span class="n">중복 방지</span>
<span style="flex:1"></span>
<button class="pill chip" onclick="setDupM(1)">1개월</button>
<button class="pill chip" onclick="setDupM(2)">2개월</button>
<button class="pill chip" onclick="setDupM(3)">3개월</button><button class="px" onclick="togglePanel('recent')" title="이 창 닫기">✕</button></div>
<div id="dupLegend" class="hint" style="margin:2px 0"></div>
<div class="scroll" style="max-height:230px"><div id="recentWinners"></div></div>
</div>

</div></div>
<div id="pastModal" class="modal" style="display:none" onclick="if(event.target===this)closePast()">
 <div class="modalbox">
  <div class="ct"><span id="pastTitle">지난 방송</span><span style="flex:1"></span>
   <button class="gray" style="padding:4px 10px" onclick="closePast()">✕ 닫기</button></div>
  <div id="pastBody"></div>
 </div>
</div>
<div id="cumModal" class="modal" style="display:none" onclick="if(event.target===this)closeCum()">
 <div class="modalbox" style="max-width:780px">
  <div class="ct">📈 누적 순위 <span class="n" id="cumMeta"></span><span style="flex:1"></span>
   <button class="gray" style="padding:4px 10px" onclick="closeCum()">✕ 닫기</button></div>
  <div class="row hint" style="margin:2px 0 8px;align-items:center">채팅·후원 집계 기간 <span id="cumWeeks"></span></div>
  <div id="cumBody"></div>
 </div>
</div>
<div id="settingsModal" class="modal" style="display:none" onclick="if(event.target===this)closeSettings()">
 <div class="modalbox">
  <div class="ct">⚙ 규칙 설정 <span style="flex:1"></span>
   <button class="gray" style="padding:4px 10px" onclick="closeSettings()">✕ 닫기</button></div>
  <div class="row hint">채팅 <input id="sChatFull" style="width:52px"> 개에
  +<input id="sChatMax" style="width:46px"> · 별풍선
  <input id="sBalFull" style="width:60px"> 개에 +<input id="sBalMax" style="width:46px"></div>
  <div class="row hint">
  <label><input type="checkbox" id="sExcl"> 이전 당첨자 전체 제외</label>
  · 최근 <input id="sWeeks" style="width:40px"> 주 당첨자 제외(0=끄기)
  · 별풍선 <input id="sAlert" style="width:52px"> 개 이상이면 감사 배너</div>
  <div class="row hint">집계 제외 계정 <input id="sExclAcc" style="flex:1;min-width:220px"
   placeholder="매크로·스태프 아이디/닉 (스페이스나 쉼표로 여러 개)"></div>
  <div class="row hint">우리 채널 (데이터 저장) <input id="sRealCh" style="flex:1;min-width:200px"
   placeholder="talent (기본) — 다른 방송도 저장하려면 아이디 추가"></div>
  <div class="hint" style="margin:-2px 0 6px">여기 적은 채널만 채팅·활약·당첨 데이터를 저장합니다.
  나머지 채널(테스트용)은 저장하지 않고 창을 닫으면 사라집니다.</div>
  <div class="hint" style="margin:-2px 0 6px">우리 방송 계정(<b id="bjName"></b>)은 자동 제외됩니다.
  자동 매크로 채팅·매니저 계정을 여기 넣으면 시청자 활약 집계에서 빠집니다.</div>
  <div class="row hint">구글 문서 ID <input id="sGdoc" style="flex:1;min-width:180px" placeholder="당첨자 문서 주소의 /d/ 다음 부분">
  <span id="gdocInfo" class="pill"></span></div>
  <div class="row hint">슬랙 웹훅 <input id="sSlack" style="flex:1;min-width:180px" placeholder="https://hooks.slack.com/..."></div>
  <div class="row hint">쪽지 링크 <input id="sNoteUrl" style="flex:1;min-width:200px" placeholder="비우면 SOOP 방송국 페이지 · 쪽지 쓰기창을 바로 열려면 주소에 {id} 포함">
  <span class="pill">{id}=아이디 자리</span></div>
  <div class="hint" style="margin:-2px 0 6px">채팅 이름 옆 ✉ 를 누르면 그 사람 아이디가 복사되고 이 링크가 새 탭으로 열립니다.
  비워두면 SOOP 방송국 페이지가 열립니다.</div>
  <div class="row hint">테스트 계정 <input id="sTestAcc" style="width:160px" placeholder="pacas1129">
  <button class="gray" style="padding:3px 10px" onclick="sendNote((document.getElementById('sTestAcc').value.trim()||settings.testAccount||'pacas1129'))">🧪 이 계정에 쪽지</button>
  <span class="n">쪽지·각종 테스트용 (닉 onpoong) · 시청자 활약 집계에서 자동 제외</span></div>
  <div class="row"><button onclick="saveSettings()">설정 저장</button>
  <button class="gray" onclick="copyLedger()">📋 당첨 기록 복사</button>
  <button class="gray" onclick="slackReport()">📨 슬랙으로 요약</button></div>
 </div>
</div>
<script>
/* ── 채팅 집계 (이 브라우저 안에서) ─────────────────────────── */
/* 주소 뒤에 ?bj=아이디 를 붙이면 그 채널에 붙습니다 — 우리 방송이 없을 때
   다른 라이브에서 수신을 시험하는 용도입니다. 시험 채널일 때는
   방송별 눈금(stats)을 저장하지 않아 진짜 기록과 섞이지 않습니다. */
const BJ=(new URLSearchParams(location.search).get('bj')||'talent')
  .toLowerCase().replace(/[^a-z0-9_]/g,'')||'talent';
const IS_DEMO = location.search.includes('demo');
function realChSet(){
  let list=['talent'];
  try{const j=JSON.parse(localStorage.getItem('pzRealCh')||'null');if(Array.isArray(j)&&j.length)list=j;}catch(e){}
  return new Set(list.map(x=>String(x).toLowerCase()).concat('talent'));
}
/* 우리 채널(기록 저장 대상)이 아니면 휘발성(테스트)으로 봅니다.
   기본은 talent, 설정에서 다른 채널도 추가할 수 있습니다. */
let IS_TEST_CH = !realChSet().has(BJ) || IS_DEMO;
const F='\x0c', users={}, recent=[], rawUnknown=[], CHAT_MAX=3000; let chatSeq=0;
const sess={on:false,date:'',startedAt:''};   // 스타트/종료 상태
const logBuf=[];                              // 서버로 보낼 채팅 로그 대기줄
const LSKEY='pzLive_'+BJ;   // 이 브라우저에 로그·집계 임시 보관 (창을 나가도 유지)
let liveOn=false, liveTitle='', ws=null, pingT=null, ST=null;
let settings={chatFull:50,chatBonusMax:0.3,balloonFull:1000,balloonBonusMax:0.5,
  excludeWinners:false,excludeWeeks:0,balloonAlert:100,gdocId:'',slackWebhook:'',
  noteUrl:'',testAccount:'pacas1129'};
let chatGrade='';   // 채팅 등급 필터: ''=전체, sup=열혈, fan=팬, sub=구독
const gradeOf={};   // 닉 → 등급(sup/sub/fan) — 별풍선 줄 등 플래그 없는 곳 색칠용
let gdoc={names:[],ids:[]};   // 구글 문서에서 읽어 온 당첨자
let exclSet=new Set(), macroCount=0;   // 집계 제외 계정, 제외한 채팅 수
let dupMonths=+(localStorage.getItem('pzDupM')||1)||1;   // 최근 당첨자 표시 기간(개월)

function esc(s){return String(s??'').replace(/[&<>"]/g,
  c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]))}
function cleanNick(s){s=(s||'').trim();
  if(s.endsWith(')')&&s.includes('(')) s=s.slice(0,s.lastIndexOf('('));return s.trim()}
function rebuildExcl(){
  exclSet=new Set([BJ]);   // 우리 방송 계정은 항상 제외
  (settings.excludeAccounts||'').toLowerCase().split(/[\s,]+/).filter(Boolean)
    .forEach(x=>exclSet.add(x));
  if(settings.testAccount)exclSet.add(settings.testAccount.toLowerCase().trim());  // 테스트 계정도 집계 제외
}
function isExcluded(id,nick){
  const i=(id||'').toLowerCase().trim();
  if(i&&exclSet.has(i))return true;
  return exclSet.has((nick||'').replace(/\s+/g,'').toLowerCase());
}
function bump(nick,kind,n){
  if(!nick)return;
  const u=users[nick]??(users[nick]={c:0,b:0});
  if(kind==='c')u.c++; else u.b+=n;
}
// 채팅 등급 플래그 — SOOP 채팅 DOM(button[grade],[userflag]) 을 정답으로 확정
// (2026-09-10 talent 라이브 23명 대조). f[7]="flag1|flag2" 의 flag1 = DOM userflag.
//   팬클럽(fan)     = flag1 bit5 (0x20)
//   열혈팬(topfan)  = flag1 bit15(0x8000)   ← 예전 flag2 bit25 는 오답이었음(오탐·미탐)
//   구독자(gudok)   = f[8](구독 개월수) 0 이상. 미구독은 -1.
// 검증: 쌍두[승]모=topfan(bit15 유일) · IIillIllllI/누구새우♡=fan(bit15 없음) ·
//       30살모솔/낭만헌터T=gudok(subm 1·2) · 플토의정석 등=user.
function chatFlags(f){
  const raw=(f[7]||'').split('|');
  const a=parseInt(raw[0]||'0',10)||0;
  return {fan:!!(a&0x20), sup:!!(a&0x8000), sub:/^\d+$/.test((f[8]||'').trim())};
}
// 채팅 이름 앞 등급 배지 (열혈>팬, 구독은 별개로 맨 앞)
function fbadges(e){
  let h='';
  if(e.sub)h+='<span class="fb sub" title="구독자">구</span>';
  if(e.sup)h+='<span class="fb yeol" title="열혈팬클럽">열</span>';
  else if(e.fan)h+='<span class="fb fan" title="팬클럽">F</span>';
  return h;
}
function onEvent(ev){
  // 우리 방송 계정(매크로)·제외 계정은 시청자 활약에 넣지 않습니다
  if(isExcluded(ev.id,ev.nick)){macroCount++;return;}
  ev.s=++chatSeq;
  if(ev.t==='chat'){const _g=ev.sup?'sup':ev.sub?'sub':ev.fan?'fan':'';if(_g)gradeOf[ev.nick]=_g;}
  if(ev.t==='chat'){bump(ev.nick,'c');
    // 등급을 시청자 기록에도 남깁니다 — 활약표(채팅왕·후원왕)에서 채팅창과 같은 색·배지를 쓰고,
    // 새로고침이나 저장 뒤에도 유지되게 하려는 것입니다. 1=팬 · 2=열혈 · 4=구독.
    const _gm=(ev.fan?1:0)|(ev.sup?2:0)|(ev.sub?4:0);
    if(_gm&&users[ev.nick])users[ev.nick].g=_gm;
    if(ev.id)uid[ev.nick]=ev.id;recent.push(ev);ttOnChat(ev);}
  else if(ev.t==='balloon'){
    bump(ev.nick,'b',ev.count);if(ev.id)uid[ev.nick]=ev.id;recent.push(ev);
    // 큰 별풍선이면 방송 장면에 감사 배너를 자동으로 띄웁니다
    if(!IS_TEST_CH && ev.count>=(settings.balloonAlert||100))
      api('toast_add',{nick:ev.nick,count:ev.count});
  }
  if(sess.on&&!IS_TEST_CH&&(ev.t==='chat'||ev.t==='balloon'))logBuf.push(ev);
  recent.splice(0,Math.max(0,recent.length-CHAT_MAX));
}
const uid={};   // 닉네임 → SOOP 아이디 (지금 방송에서 본 것)
/* SOOP 채팅 프로토콜 — 시청자용 접속과 같은 방식입니다 */
function pkt(svc,body){
  const b=new TextEncoder().encode(body);
  const head=new TextEncoder().encode('\x1b\t'+String(svc).padStart(4,'0')
    +String(b.length).padStart(6,'0')+'00');
  const u=new Uint8Array(head.length+b.length);u.set(head);u.set(b,head.length);return u;
}
/* 별풍선 중복제거 — svc109 의 [3]은 별풍선 '종류' 해시라 여러 사람이
   공유합니다(실측: 서로 다른 3명이 같은 mid). 그래서 mid 만으로 지우면
   다른 사람·다른 개수 선물까지 버려 절반 이상을 놓쳤습니다. 이제
   (종류+보낸사람+개수)가 똑같고 8초 안에 다시 온 것만 재전송으로 보고
   건너뜁니다. 다른 사람·다른 개수·시간차 큰 같은 선물은 각각 셉니다. */
let seenBalloons=new Map();   // (보낸사람|개수) -> 마지막 시각(ms)
const BAL_WINDOW=8000;
/* 별풍선 — SOOP 플레이어 공식 코드에서 확인한 레이아웃 그대로 (2026-08-21).
     svc 18 = SVC_SENDBALLOON      [1]채널 [2]보낸이ID [3]닉 [4]개수 [8]시그니처 이미지 파일명
     svc 33 = SVC_SENDBALLOONSUB   [2]채널 [4]보낸이ID [5]닉 [6]개수 (중계방)
     svc109 = SVC_OGQ_EMOTICON     이모티콘 스티커 — 별풍선이 아니므로 세지 않음
   닉==ID 인 시청자도 진짜 별풍선입니다(공식 파서에 제외 규칙 없음).
   순위·누적은 svc 30/39(TOPFAN/TOPCLAN)로 따로 오므로 여기 안 섞입니다.
   중복제거: 같은 사람·같은 개수가 8초 안에 다시 오면 재전송으로 봄. */
function dedupBalloon(who,cnt,kind){
  const key=who+'|'+cnt+'|'+(kind||'star'), t=Date.now(), last=seenBalloons.get(key);
  seenBalloons.set(key,t);
  if(seenBalloons.size>5000){for(const [k,v] of seenBalloons)if(t-v>=BAL_WINDOW)seenBalloons.delete(k);}
  return last==null || t-last>=BAL_WINDOW;   // true = 새(중복 아닌) 것
}
function parseBalloon18(f){  // svc 18 — 공식 SVC_SENDBALLOON
  if(f.length<5)return null;
  const cnt=(f[4]||'').trim();
  if(!/^\d+$/.test(cnt)||+cnt<=0||+cnt>1000000)return null;
  const id=cleanNick(f[2]), nick=cleanNick(f[3]);
  if(!id||!nick)return null;
  if((f[1]||'').toLowerCase()!==BJ)return null;        // 이 채널로 온 선물만
  return dedupBalloon(id,cnt)?{t:'balloon',nick,count:+cnt,id}:null;
}
function parseBalloon33(f){  // svc 33 — 공식 SVC_SENDBALLOONSUB (중계방 별풍선)
  if(f.length<7)return null;
  const cnt=(f[6]||'').trim();
  if(!/^\d+$/.test(cnt)||+cnt<=0||+cnt>1000000)return null;
  const id=cleanNick(f[4]), nick=cleanNick(f[5]);
  if(!id||!nick)return null;
  if((f[2]||'').toLowerCase()!==BJ)return null;
  return dedupBalloon(id,cnt,'star')?{t:'balloon',nick,count:+cnt,id}:null;
}
function parseBalloon87(f){  // 애드벌룬 (SVC_ADCON_EFFECT) — 채널f[2] 아이디f[3] 닉f[4] 개수f[10]. 사장님 방침으로 별풍선 합산.
  if(f.length<11)return null;
  const cnt=(f[10]||'').trim();
  if(!/^\d+$/.test(cnt)||+cnt<=0||+cnt>1000000)return null;
  const id=cleanNick(f[3]), nick=cleanNick(f[4]);
  if(!id||!nick)return null;
  if((f[2]||'').toLowerCase()!==BJ)return null;
  return dedupBalloon(id,cnt,'ad')?{t:'balloon',nick,count:+cnt,id,ad:1}:null;
}
function parseBalloon107(f){ // 방송국 애드벌룬 (SVC_STATION_ADCON) — 채널f[1] 아이디f[2] 닉f[3] 개수f[4]
  if(f.length<5)return null;
  const cnt=(f[4]||'').trim();
  if(!/^\d+$/.test(cnt)||+cnt<=0||+cnt>1000000)return null;
  const id=cleanNick(f[2]), nick=cleanNick(f[3]);
  if(!id||!nick)return null;
  if((f[1]||'').toLowerCase()!==BJ)return null;
  return dedupBalloon(id,cnt,'ad')?{t:'balloon',nick,count:+cnt,id,ad:1}:null;
}
async function connectChat(){
  if(!sess.on){setStatus('⏹ 종료 상태 — ▶ 스타트를 누르면 집계를 시작합니다');sessBtns();return;}
  if(IS_DEMO)return;                        // 연습 모드는 진짜 채팅 서버에 안 붙습니다
  let info;
  try{info=await (await fetch('prize_api.php?act=live&bj='+BJ)).json();}
  catch(e){setStatus('서버 오류');return setTimeout(connectChat,20000);}
  if(String(info.RESULT)!=='1'){
    liveOn=false;setStatus('방송 대기 중 — 시작되면 자동으로 붙습니다');
    return setTimeout(connectChat,20000);
  }
  liveOn=true;liveTitle=info.TITLE||'';
  setStatus('<span class="live">● LIVE</span> '+esc(liveTitle)+(IS_TEST_CH?' <span class="warn">['+BJ+' 채널 시험 중 — 기록 저장 안 함]</span>':''));
  ttGapCheck();
  try{
    ws=new WebSocket('wss://'+info.CHDOMAIN+':'+(+info.CHPT+1)+'/Websocket/'+BJ,['chat']);
  }catch(e){setStatus('채팅 연결 실패');return setTimeout(connectChat,15000);}
  ws.binaryType='arraybuffer';
  let joined=false;
  ws.onopen=()=>{ws.send(pkt(1,F+F+F+'16'+F));
    pingT=setInterval(()=>{try{ws.send(pkt(0,F))}catch(e){}},50000);};
  ws.onmessage=(m)=>{
    const s=new TextDecoder().decode(m.data);
    if(!s.startsWith('\x1b\t'))return;
    const svc=+s.slice(2,6), f=s.slice(14).split(F);
    if(!joined){joined=true;ws.send(pkt(2,F+String(info.CHATNO)+F+F+F+F));}
    if(svc===5&&f.length>6){
      const nick=cleanNick(f[6]);
      if(nick){const fl=chatFlags(f);onEvent({t:'chat',nick,id:cleanNick(f[2]),msg:f[1],at:now(),fan:fl.fan,sup:fl.sup,sub:fl.sub});}
    }else if(svc===18){
      const ev=parseBalloon18(f);        // 별풍선 (공식 SVC_SENDBALLOON)
      if(ev){ev.at=now();onEvent(ev);}
    }else if(svc===33){
      const ev=parseBalloon33(f);        // 중계방 별풍선 (공식 SVC_SENDBALLOONSUB)
      if(ev){ev.at=now();onEvent(ev);}
    }else if(svc===87){
      const ev=parseBalloon87(f);        // 애드벌룬 — 별풍선에 합산
      if(ev){ev.at=now();onEvent(ev);}
    }else if(svc===107){
      const ev=parseBalloon107(f);       // 방송국 애드벌룬 — 별풍선에 합산
      if(ev){ev.at=now();onEvent(ev);}
    }else if(svc===109){
      // OGQ 이모티콘(공식 SVC_OGQ_EMOTICON) — 별풍선 아님, 집계 안 함

    }else if(![0,1,2,4].includes(svc)){
      rawUnknown.push({svc,f:f.slice(0,10),at:now()});
      rawUnknown.splice(0,Math.max(0,rawUnknown.length-200));
    }
  };
  ws.onclose=()=>{clearInterval(pingT);
    if(tt&&!window.ttDropAt)window.ttDropAt=Date.now();   // 예측 중 끊김 시각 기록
    setStatus(liveOn?'연결 끊김 — 다시 붙는 중…':'방송 대기 중');
    setTimeout(connectChat,8000);};
  ws.onerror=()=>{try{ws.close()}catch(e){}};
}
function now(){return new Date().toTimeString().slice(0,8)}
function setStatus(html){document.getElementById('liveflag').innerHTML=html}

/* ── 가중치와 추첨 ── */
function weight(nick){
  const u=users[nick]||{c:0,b:0}, s=settings;
  return 1+Math.min(1,u.c/Math.max(1,s.chatFull))*s.chatBonusMax
          +Math.min(1,u.b/Math.max(1,s.balloonFull))*s.balloonBonusMax;
}
function norm(s){return String(s||'').replace(/\s+/g,'').toLowerCase()}
function winCount(nick){
  if(!ST)return[0,''];
  const n=norm(nick),sid=(uid[nick]||'').toLowerCase();
  const h=ST.winners.list.filter(w=>norm(w.nick)===n||(sid&&(w.sid||'').toLowerCase()===sid));
  return[h.length,h.length?h[h.length-1].date:''];
}
function curPrize(){return (prizeOf(document.getElementById('prizeSel').value)||{}).name||'';}
function hasWonPrize(nick,prize){
  // 이 사람이 '이 상품'을 이미 받았는지 (닉 또는 SOOP계정 일치)
  if(!ST||!prize)return false;
  const n=norm(nick), sid=(uid[nick]||'').toLowerCase(), pn=norm(prize);
  return ST.winners.list.some(w=>norm(w.prize)===pn &&
    (norm(w.nick)===n || (sid&&(w.sid||'').toLowerCase()===sid)));
}
function recentWin(nick){
  // 최근 N주 안에 받은 적 있나 (0 이면 제한 없음)
  const wk=settings.excludeWeeks||0; if(!wk||!ST)return false;
  const cut=new Date(Date.now()-wk*7*864e5).toISOString().slice(0,10);
  const n=norm(nick),sid=(uid[nick]||'').toLowerCase();
  return ST.winners.list.some(w=>(norm(w.nick)===n||(sid&&(w.sid||'').toLowerCase()===sid))
    && (w.date||'')>=cut);
}
function inGdoc(nick){
  const sid=(uid[nick]||'').toLowerCase();
  if(sid&&gdoc.ids.includes(sid))return true;
  const n=norm(nick);
  return gdoc.names.some(x=>norm(x)===n)||gdoc.names.some(x=>norm(x).includes(n)&&n.length>=2);
}
function drawPool(){
  const prize=curPrize();
  let pool=Object.keys(users).filter(n=>users[n].c+users[n].b>0);
  if(prize)pool=pool.filter(n=>!hasWonPrize(n,prize));   // 같은 상품 중복 당첨 방지 (항상)
  if(settings.excludeWinners)pool=pool.filter(n=>winCount(n)[0]===0);
  if(settings.excludeWeeks)pool=pool.filter(n=>!recentWin(n));
  return pool;
}
function pickFrom(pool){
  if(!pool.length)return null;
  const ws2=pool.map(weight), tot=ws2.reduce((a,b)=>a+b,0);
  let r=Math.random()*tot;
  for(let i=0;i<pool.length;i++){r-=ws2[i];if(r<=0)return pool[i];}
  return pool[pool.length-1];
}
// 핀볼·룰렛 후보 슬롯 — 확률 높은 사람 위주로 채웁니다(당첨자 포함).
function slotsFor(win,pool,k){
  const others=pool.filter(n=>n!==win).sort((a,b)=>weight(b)-weight(a));
  const top=others.slice(0,Math.max(0,k-1));
  const slots=top.concat([win]);
  for(let i=slots.length-1;i>0;i--){const j=(Math.random()*(i+1))|0;[slots[i],slots[j]]=[slots[j],slots[i]];}
  return slots;
}

/* ── 서버 통신 ── */
async function api(act,body){
  const r=await fetch('prize_api.php',{method:'POST',
    headers:{'Content-Type':'application/json'},
    body:JSON.stringify(Object.assign({act},body||{}))});
  const j=await r.json();if(j.error)alert(j.error);return j;
}
function prizeOf(id){return (ST?.prizes.items||[]).find(x=>x.id===id)}
async function manualPick(){
  const nick=document.getElementById('pickNick').value.trim();
  if(!nick)return alert('닉네임을 넣어 주세요');
  const pz=prizeOf(document.getElementById('prizeSel').value)||{};
  if(hasWonPrize(nick,pz.name)&&!confirm(nick+' 님은 이미 "'+(pz.name||'')+'" 상품에 당첨된 적이 있습니다. 같은 상품에 또 당첨시킬까요?'))return;
  await api('pick',{nick,sid:uid[nick]||'',prize:pz.name||'',how:'지명'+(IS_TEST_CH?'(연습)':'')});
  await api('overlay_set',{overlay:{kind:'winner',nick,prize:pz.name||'',
    photo:pz.photo||'',how:'지명'}});
  refresh();
}
async function draw(kind){
  const pool=drawPool();
  const win=pickFrom(pool);
  if(!win)return alert('추첨할 시청자가 없습니다 (조건에 맞는 참가자가 없음)');
  const slots=slotsFor(win,pool,kind==='roulette'?10:9);
  const pz=prizeOf(document.getElementById('prizeSel').value)||{};
  await api('pick',{nick:win,sid:uid[win]||'',prize:pz.name||'',how:(kind==='roulette'?'룰렛':'핀볼')+(IS_TEST_CH?'(연습)':'')});
  await api('overlay_set',{overlay:{kind,winner:win,slots,prize:pz.name||'',photo:pz.photo||''}});
  refresh();
}
const plinko=()=>draw('plinko');
const roulette=()=>draw('roulette');
async function kings(){
  const arr=Object.entries(users);
  if(!arr.length)return alert('아직 참가자가 없습니다');
  const chatKing=arr.slice().sort((a,b)=>b[1].c-a[1].c)[0];
  const balKing=arr.slice().sort((a,b)=>b[1].b-a[1].b)[0];
  await api('overlay_set',{overlay:{kind:'kings',
    chatNick:chatKing[0],chatN:chatKing[1].c,
    balNick:balKing[0],balN:balKing[1].b}});
}
async function clearOverlay(){await api('overlay_set',{overlay:{kind:'none'}})}
let photoData='';
document.getElementById('pPhoto').addEventListener('change',e=>{
  const f=e.target.files[0];if(!f)return;
  const r=new FileReader();
  r.onload=()=>{photoData=r.result;
    document.getElementById('pPhotoName').innerHTML='<img src="'+r.result+'" style="width:38px;height:38px;object-fit:cover;border-radius:6px;vertical-align:middle;margin-right:6px">'+f.name;};
  r.readAsDataURL(f);
});
async function addPrize(){
  const name=document.getElementById('pName').value.trim();
  if(!name)return alert('상품 이름을 넣어 주세요');
  await api('prize_add',{name,photo:photoData});
  photoData='';document.getElementById('pName').value='';
  document.getElementById('pPhotoName').textContent='';refresh();
}
async function addWinner(){
  await api('pick',{date:document.getElementById('wDate').value.trim()||undefined,
    nick:document.getElementById('wNick').value.trim(),
    prize:document.getElementById('wPrize').value.trim(),how:'기록'});
  document.getElementById('wNick').value='';refresh();
}
async function saveSettings(){
  settings=Object.assign(settings,{
    chatFull:+document.getElementById('sChatFull').value||50,
    chatBonusMax:+document.getElementById('sChatMax').value||0.3,
    balloonFull:+document.getElementById('sBalFull').value||1000,
    balloonBonusMax:+document.getElementById('sBalMax').value||0.5,
    excludeWinners:document.getElementById('sExcl').checked,
    excludeWeeks:+document.getElementById('sWeeks').value||0,
    balloonAlert:+document.getElementById('sAlert').value||100,
    gdocId:(document.getElementById('sGdoc').value.trim().match(/[-\w]{25,}/)||[document.getElementById('sGdoc').value.trim()])[0],
    slackWebhook:document.getElementById('sSlack').value.trim(),
    excludeAccounts:document.getElementById('sExclAcc').value.trim(),
    noteUrl:document.getElementById('sNoteUrl').value.trim(),
    testAccount:document.getElementById('sTestAcc').value.trim(),
    realChannels:document.getElementById('sRealCh').value.trim()});
  await api('settings_set',{settings});loadGdoc();rebuildExcl();applyRealCh();closeSettings();
}
function openSettings(){var b=document.getElementById('bjName');if(b)b.textContent=BJ;
  document.getElementById('settingsModal').style.display='flex';}
function closeSettings(){document.getElementById('settingsModal').style.display='none';}
function applyRealCh(){
  const raw=(settings.realChannels||'').toLowerCase().split(/[\s,]+/).filter(Boolean);
  if(!raw.includes('talent'))raw.push('talent');
  try{localStorage.setItem('pzRealCh',JSON.stringify(raw));}catch(e){}
  IS_TEST_CH = !new Set(raw).has(BJ) || IS_DEMO;
}
function updateMaskBtn(){
  const b=document.getElementById('maskBtn');if(!b)return;
  b.textContent=document.body.classList.contains('maskacc')?'👁 계정 보기':'🙈 계정 가리기';
}
function toggleMask(){
  document.body.classList.toggle('maskacc');
  try{localStorage.setItem('pzMaskAcc',document.body.classList.contains('maskacc')?'1':'');}catch(e){}
  updateMaskBtn();
}
function downloadActivity(){
  const rows=Object.entries(users).map(([nick,u])=>({nick,acc:uid[nick]||'',c:u.c,b:u.b,w:winCount(nick)[0]}));
  if(!rows.length)return alert('활약 데이터가 없습니다');
  rows.sort((a,b)=>b.b-a.b||b.c-a.c);
  const NL=String.fromCharCode(10);
  const q=x=>'"'+String(x==null?'':x).replace(/"/g,'""')+'"';
  const lines=rows.map(r=>[q(r.nick),q(r.acc),r.c,r.b,r.w].join(','));
  const csv=String.fromCharCode(0xFEFF)+['닉네임,SOOP계정,채팅수,별풍선수,당첨횟수'].concat(lines).join(NL);
  const a=document.createElement('a');
  a.href='data:text/csv;charset=utf-8,'+encodeURIComponent(csv);
  a.download='끝장전-활약-'+(sess.date||todayStr())+'.csv';a.click();
}
/* ── 시청자 승부예측 — 하루 가상 포인트 베팅 (내부 코드명 toto 유지) ──
   '도전' → 10,000P 지급(계정당 1회). '이름 금액'/'이름 올인' — 첫 베팅 고정.
   배당 = 총풀/승자풀(합동배당), 승자 없으면 전원 환불. 파산 = 그날 끝.
   접수/실패는 피드로 안내(관제·방송 장면·공개 페이지). 정산·하루마감은
   서버가 단독 수행(이중 방지). 연습 채널이면 전부 이 창 안에서만. */
const TT_START=10000, TT_MIN=100;
let tt=null;            // {date,open,players,round,rounds,feed}
let ttLogBuf=[];        // 예측 중 전 채팅 원본 (서버 기록 대기)
function ttLog(o){ if(tt&&!IS_TEST_CH)ttLogBuf.push(Object.assign({at:Date.now()},o)); }
function ttLogFlush(){
  if(!ttLogBuf.length||IS_TEST_CH||!tt)return;
  api('toto_chatlog',{date:tt.date,lines:ttLogBuf.splice(0,2000)});
}
function ttGapCheck(){
  // 채팅이 끊겼다 다시 붙었을 때 — 그 사이 채팅은 못 받았으니 크게 알립니다.
  if(!window.ttDropAt)return;
  const gap=Math.round((Date.now()-window.ttDropAt)/1000);
  window.ttDropAt=null;
  if(tt&&gap>=3){
    ttFeed('info','⚠ 채팅 연결이 '+gap+'초 끊겼습니다 — 그 사이 도전·베팅은 못 받았을 수 있어요. 방송에서 안내해 주세요!');
    ttLog({ctl:'gap',sec:gap});
    ttPaint();
  }
}
let ttSyncT=null, ttLocalSeason={players:{},days:[]};
function ttNorm(s){return String(s||'').replace(/\s+/g,'').replace(/[!~.?]+$/,'').toLowerCase()}
function ttFeed(type,msg){
  if(!tt)return;
  tt.feed=tt.feed||[];
  tt.feed.unshift({type:type,msg:msg,at:new Date().toTimeString().slice(0,8)});
  tt.feed.splice(80);
}
function ttOnChat(ev){
  if(!tt)return;
  ttLog({id:ev.id||'',n:ev.nick,m:ev.msg});     // 전 채팅 원본 기록
  const key=ev.id||('nick:'+ev.nick);
  const raw=String(ev.msg||'').trim();
  if(ttNorm(raw)==='도전'){                     // ① 참여
    if(!tt.open)return;
    const p0=tt.players[key];
    if(p0){if(p0.bust)ttFeed('fail','❌ '+ev.nick+' — 오늘은 파산! 내일 다시 도전하세요');return;}
    tt.players[key]={n:ev.nick,bal:TT_START,betW:0,betL:0,bust:false};
    ttFeed('join','🙋 '+ev.nick+' 참여! ('+TT_START.toLocaleString()+'P 지급)');
    ttPaint();return;
  }
  if(!tt.round)return;                          // ② 베팅 — '이름 금액'
  const m=raw.split(/\s+/);
  if(m.length!==2)return;
  const amtRaw=m[1].replace(/,/g,'');
  const allin=(amtRaw==='올인');
  if(!allin&&!/^[0-9]+$/.test(amtRaw))return;   // 베팅 형태 아님 → 조용히 무시
  const nm=ttNorm(m[0]);
  let pick=null;
  if(nm===ttNorm(tt.round.a))pick='a'; else if(nm===ttNorm(tt.round.b))pick='b';
  if(tt.round.state!=='open'){const pl0=tt.players[key];if(pick&&pl0&&!pl0.bust)ttFeed('fail','❌ '+ev.nick+' — 베팅 마감! 다음 세트에 참여하세요');return;}
  const p=tt.players[key];
  if(!pick){if(p&&!p.bust)ttFeed('fail','❌ '+ev.nick+' — 선수 이름을 정확히! ('+tt.round.a+' / '+tt.round.b+')');return;}
  if(!p){ttFeed('fail','❌ '+ev.nick+' — 먼저 채팅에 "도전"을 쳐서 참여하세요');return;}
  if(p.bust){ttFeed('fail','❌ '+ev.nick+' — 파산! 오늘은 관전만');return;}
  if(tt.round.bets[key]){ttFeed('fail','❌ '+ev.nick+' — 이미 베팅했어요 (변경 불가)');return;}
  const amt=allin?p.bal:parseInt(amtRaw,10);
  if(!allin&&amt>p.bal){ttFeed('fail','❌ '+ev.nick+' — 잔액 부족 (보유 '+p.bal.toLocaleString()+'P)');return;}
  if(amt<TT_MIN){ttFeed('fail','❌ '+ev.nick+' — 최소 '+TT_MIN+'P부터');return;}
  p.bal-=amt;
  tt.round.bets[key]={p:pick,amt:amt,n:ev.nick};
  ttFeed('bet','✅ '+ev.nick+' '+amt.toLocaleString()+'P → '+(pick==='a'?tt.round.a:tt.round.b)+(allin?' (올인!)':''));
  ttPaint();
}
function ttPools(){
  let a=0,b=0,n=0;
  if(tt&&tt.round)for(const k in tt.round.bets){const v=tt.round.bets[k];n++;if(v.p==='a')a+=v.amt;else b+=v.amt;}
  return{a:a,b:b,n:n};
}
function ttOdds(pool,total){return pool>0?(total/pool).toFixed(2):'-';}
function ttPaint(){
  const st=document.getElementById('ttStatus');if(!st)return;
  const bar=document.getElementById('ttBar'),fd=document.getElementById('ttFeed');
  const oBtn=document.getElementById('ttOpenBtn'),cBtn=document.getElementById('ttCloseBtn');
  const sBtn=document.getElementById('ttStartBtn'),lBtn=document.getElementById('ttLockBtn');
  const aBtn=document.getElementById('ttWinA'),bBtn=document.getElementById('ttWinB'),xBtn=document.getElementById('ttCancelBtn');
  const en=document.getElementById('ttEntry');
  if(!tt){
    oBtn.disabled=false;cBtn.disabled=true;sBtn.disabled=true;lBtn.disabled=true;
    aBtn.disabled=true;bBtn.disabled=true;xBtn.disabled=true;
    en.textContent='시작 전';bar.style.display='none';
    if(!st.dataset.keep)st.innerHTML='<b>🔮 오늘 시작</b>을 누르면 시청자가 채팅에 <b>도전</b>을 쳐서 참여합니다 (1인 '+TT_START.toLocaleString()+'P).';
    fd.innerHTML='';return;
  }
  st.dataset.keep='';
  let np=0,nb=0;for(const k in tt.players){np++;if(tt.players[k].bust)nb++;}
  en.textContent='참여 '+np+'명'+(nb?' · 파산 '+nb:'');
  oBtn.disabled=true;cBtn.disabled=false;
  const r=tt.round;
  sBtn.disabled=!!r;lBtn.disabled=!r||r.state!=='open';
  aBtn.disabled=!r;bBtn.disabled=!r;xBtn.disabled=!r;
  if(r){
    aBtn.textContent=r.a+' 승';bBtn.textContent=r.b+' 승';
    document.getElementById('ttA').value=r.a;document.getElementById('ttB').value=r.b;
    const c=ttPools(),tot=c.a+c.b,pa=tot?Math.round(c.a*100/tot):50;
    bar.style.display='';
    document.getElementById('ttCntA').textContent=r.a+' '+c.a.toLocaleString()+'P (배당 '+ttOdds(c.a,tot)+')';
    document.getElementById('ttCntB').textContent='(배당 '+ttOdds(c.b,tot)+') '+c.b.toLocaleString()+'P '+r.b;
    document.getElementById('ttFillA').style.width=(tot?pa:50)+'%';
    st.innerHTML=(r.state==='open'?'🟢 <b>베팅 접수 중</b> — 채팅에 "이름 금액" 또는 "이름 올인". ':'⏸ <b>마감</b> — 결과 버튼으로 정산. ')
      +'베팅 '+c.n+'건 · 총 '+tot.toLocaleString()+'P'+(IS_TEST_CH?' <span class="warn">(연습)</span>':'');
  }else{
    aBtn.textContent='A 승';bBtn.textContent='B 승';bar.style.display='none';
    st.innerHTML='참여 접수 중 — 세트 전에 선수 두 명을 넣고 <b>베팅 오픈</b>.'+(IS_TEST_CH?' <span class="warn">(연습 — 저장 안 됨)</span>':'');
  }
  fd.innerHTML=(tt.feed||[]).slice(0,10).map(function(f){
    return '<div>'+esc(f.at+' '+f.msg)+'</div>';}).join('');
}
function ttSyncStart(){if(ttSyncT||IS_TEST_CH)return;ttSyncT=setInterval(function(){if(tt){api('toto_save',{day:tt});ttLogFlush();}},4000);}
function ttSyncStop(){if(ttSyncT){clearInterval(ttSyncT);ttSyncT=null;}}
async function ttOpenDay(){
  if(tt)return;
  tt={date:todayStr(),open:true,players:{},round:null,rounds:[],feed:[]};
  ttFeed('info','🔮 시청자 승부예측 시작! 채팅에 "도전"');
  ttLog({ctl:'open_day'});
  const st=document.getElementById('ttStatus');if(st)delete st.dataset.keep;
  ttPaint();
  if(!IS_TEST_CH){await api('toto_save',{day:tt});ttSyncStart();}
}
async function ttRoundStart(){
  if(!tt||tt.round)return;
  const a=document.getElementById('ttA').value.trim(),b=document.getElementById('ttB').value.trim();
  if(!a||!b)return alert('선수 두 명의 이름을 넣어주세요');
  if(ttNorm(a)===ttNorm(b))return alert('두 이름이 같습니다');
  tt.round={id:String(Date.now()),a:a,b:b,state:'open',bets:{}};
  ttFeed('info','💰 베팅 오픈 — '+a+' vs '+b);
  ttLog({ctl:'round_open',a:a,b:b});
  ttPaint();
  if(!IS_TEST_CH){await api('toto_save',{day:tt});await api('overlay_set',{overlay:{kind:'toto'}});}
}
async function ttLock(){
  if(!tt||!tt.round||tt.round.state!=='open')return;
  tt.round.state='locked';
  ttFeed('info','⏸ 베팅 마감 ('+ttPools().n+'건)');
  ttLog({ctl:'lock'});
  ttPaint();
  if(!IS_TEST_CH)await api('toto_save',{day:tt});
}
async function ttCancelRound(){
  if(!tt||!tt.round)return;
  if(!confirm('이 베팅 라운드를 취소할까요? 건 포인트는 전부 돌려줍니다.'))return;
  for(const k in tt.round.bets){const v=tt.round.bets[k];const p=tt.players[k];if(p){p.bal+=v.amt;p.bust=false;}}
  ttFeed('info','↩ 라운드 취소 — 전원 환불');
  ttLog({ctl:'cancel'});
  tt.round=null;ttPaint();
  if(!IS_TEST_CH){await api('toto_save',{day:tt});await api('overlay_set',{overlay:{kind:'none'}});}
}
function ttSettleLocal(day,winner){
  const r=day.round,bets=r.bets;let pa=0,pb=0;
  for(const k in bets){if(bets[k].p==='a')pa+=bets[k].amt;else pb+=bets[k].amt;}
  const tot=pa+pb,pw=winner==='a'?pa:pb,refund=pw<=0;
  const odds=refund?0:+(tot/pw).toFixed(2);let hit=0;const top=[];
  for(const k in bets){const v=bets[k],p=day.players[k];
    if(refund)p.bal+=v.amt;
    else if(v.p===winner){const gain=Math.floor(v.amt*tot/pw);p.bal+=gain;p.betW++;hit++;top.push({n:v.n,gain:gain-v.amt});}
    else p.betL++;
    if(p.bal<=0){p.bal=0;p.bust=true;}}
  top.sort(function(x,y){return y.gain-x.gain});
  const wname=winner==='a'?r.a:r.b;
  day.rounds.unshift({a:r.a,b:r.b,winner:winner,poolA:pa,poolB:pb,odds:odds,hit:hit,bets:Object.keys(bets).length,refund:refund,at:new Date().toTimeString().slice(0,5)});
  day.round=null;
  return{ok:true,refund:refund,odds:odds,hit:hit,poolA:pa,poolB:pb,wname:wname,top:top.slice(0,5)};
}
async function ttSettle(w){
  if(!tt||!tt.round)return;
  const wname=(w==='a'?tt.round.a:tt.round.b);
  if(!confirm(wname+' 승으로 정산할까요?'))return;
  ttLog({ctl:'settle',winner:w,wname:wname});ttLogFlush();
  let res;
  if(IS_TEST_CH){res=ttSettleLocal(tt,w);ttFeed('settle',res.refund?'⚖ 적중자 없음 — 전원 환불':'🏆 '+wname+' 승 · 배당 '+res.odds.toFixed(2)+'배 · 적중 '+res.hit+'명');}
  else{
    res=await api('toto_settle',{winner:w,day:tt});
    if(!res||!res.ok)return alert('정산 실패: '+((res&&res.error)||'서버 오류'));
    const g=await api('toto_get',{});if(g&&g.day)tt=g.day;   // 서버 정산 결과로 동기화
  }
  ttPaint();
  if(!IS_TEST_CH)await api('overlay_set',{overlay:{kind:'toto_result',
    wname:res.wname||wname,odds:res.odds,hit:res.hit,refund:res.refund,poolA:res.poolA,poolB:res.poolB,top:res.top||[]}});
}
async function ttCloseDay(){
  if(!tt)return;
  if(tt.round)return alert('진행 중인 베팅 라운드를 먼저 정산하거나 취소해 주세요');
  if(!confirm('오늘 승부예측을 마감하고 순위를 확정할까요?'))return;
  ttLog({ctl:'close_day'});ttLogFlush();
  let res;
  if(IS_TEST_CH){
    const rows=Object.keys(tt.players).map(function(k){const p=tt.players[k];return{n:p.n,bal:p.bal};});
    rows.sort(function(x,y){return y.bal-x.bal});
    res={ok:true,entries:rows.length,rank:rows.slice(0,10)};
  }else{
    res=await api('toto_closeday',{day:tt});
    if(!res||!res.ok)return alert('마감 실패: '+((res&&res.error)||'서버 오류'));
  }
  const champ=res.rank&&res.rank[0];
  tt=null;ttSyncStop();
  const st=document.getElementById('ttStatus');
  st.dataset.keep='1';
  st.innerHTML='🏁 오늘 마감 — 참여 '+res.entries+'명'+(champ?' · 🥇 <b>'+esc(champ.n)+'</b> '+champ.bal.toLocaleString()+'P':'')+(IS_TEST_CH?' <span class="warn">(연습)</span>':'');
  ttPaint();
  if(!IS_TEST_CH&&champ)await api('overlay_set',{overlay:{kind:'toto_champ',n:champ.n,bal:champ.bal,entries:res.entries,rank:res.rank||[]}});
}
async function ttRestore(){
  if(IS_TEST_CH)return;
  try{const g=await api('toto_get',{});
    if(g&&g.day&&g.day.players){tt=g.day;ttSyncStart();ttPaint();}}catch(e){}
}
const PANELS=[['chat','실시간 채팅'],['users','시청자 활약'],['pastdays','지난 방송'],
  ['predict','승부예측'],['pick','당첨 만들기'],['prizes','상품'],['winners','당첨자 시트'],['recent','최근 당첨자']];
const panelState={};   // key -> 숨김 여부(true=닫힘)
function panelNodes(key){
  // data-panel 이 카드면 카드 통째로, 섹션이면 헤더~다음 헤더/구분선 앞까지(앞 <hr> 포함)
  const el=document.querySelector('[data-panel="'+key+'"]');
  if(!el)return [];
  if(el.classList.contains('card'))return [el];
  const nodes=[]; const prev=el.previousElementSibling;
  if(prev&&prev.tagName==='HR')nodes.push(prev);
  nodes.push(el);
  let n=el.nextElementSibling;
  while(n&&!(n.classList&&n.classList.contains('ct'))&&n.tagName!=='HR'){nodes.push(n);n=n.nextElementSibling;}
  return nodes;
}
function applyPanel(key){
  const hide=!!panelState[key];
  panelNodes(key).forEach(n=>{n.style.display=hide?'none':'';});
}
function renderPanelBar(){
  const bar=document.getElementById('panelBar');if(!bar)return;
  bar.innerHTML='<span class="n" style="margin-right:2px">창 켜기·끄기:</span>'+PANELS.map(function(pr){
    return '<button class="pill chip'+(panelState[pr[0]]?'':' on')+'" data-pk="'+pr[0]+'">'+
      (panelState[pr[0]]?'＋ ':'✓ ')+esc(pr[1])+'</button>';}).join('');
  bar.querySelectorAll('[data-pk]').forEach(b=>b.onclick=()=>togglePanel(b.dataset.pk));
}
/* 카드(열) 전체가 닫히면 숨기고, 남은 카드 수에 맞춰 그리드를 좁혀 채팅을 넓힘 */
function reflowGrid(){
  const grid=document.querySelector('.grid'); if(!grid)return;
  const cards=[...grid.children].filter(c=>c.classList&&c.classList.contains('card'));
  cards.forEach(function(card){
    const own=card.getAttribute('data-panel');
    let empty;
    if(own){empty=!!panelState[own];}   // 채팅 카드(카드 자체가 한 패널)
    else{const pk=[...card.querySelectorAll('[data-panel]')].map(e=>e.getAttribute('data-panel'));
      empty=pk.length>0 && pk.every(k=>panelState[k]);}   // 나머지 카드는 안쪽 패널이 모두 닫혔을 때
    card.style.display=empty?'none':'';
  });
  const vis=cards.filter(c=>c.style.display!=='none').length;
  // 넓은 화면에서만 열 재배치 (좁은 화면은 CSS 미디어쿼리로 1열)
  grid.style.gridTemplateColumns=(window.innerWidth>1100)
    ? (vis<=1?'1fr':vis===2?'1.5fr 1fr':'1.1fr .9fr 1fr') : '';
}
function togglePanel(key){
  panelState[key]=!panelState[key];
  try{localStorage.setItem('pzPanels',JSON.stringify(panelState));}catch(e){}
  applyPanel(key);reflowGrid();renderPanelBar();
}
function initPanels(){
  try{Object.assign(panelState,JSON.parse(localStorage.getItem('pzPanels')||'{}'));}catch(e){}
  PANELS.forEach(pr=>applyPanel(pr[0]));
  reflowGrid();renderPanelBar();
}
/* 채팅창 높이 — 드래그한 값을 이 브라우저에 기억 */
function applyChatZoom(p){
  p=Math.max(50,Math.min(200,Math.round((+p||100)/10)*10));
  // ⚠ 예전에는 이 값을 #chatInner 에 걸었는데, '채팅 지우기'가 #chat 을 통째로 비우면서
  //   #chatInner 까지 날아가 크기 조절이 그 세션 내내 먹히지 않았습니다 (2026-09-18 수정).
  //   바깥 상자 #chat 은 지워지지 않으므로 여기에 걸고, 안쪽은 상속으로 따라옵니다.
  const box0=document.getElementById('chat');
  if(box0)box0.style.setProperty('--cz',(p/100).toFixed(3));
  const v=document.getElementById('chatZoomV'); if(v)v.textContent=p+'%';
  const s=document.getElementById('chatZoom'); if(s&&+s.value!==p)s.value=p;
  const box=document.getElementById('chat'); if(box)box.scrollTop=box.scrollHeight;
  try{localStorage.setItem('pzChatZoom',p);}catch(e){}
}
/* 채팅 줄이 들어가는 안쪽 상자. 없으면 다시 만들어 줍니다 —
   이게 사라지면 글자 크기(--cz)와 #chatInner 전용 CSS 가 통째로 먹통이 됩니다. */
function chatBody(){
  const box=document.getElementById('chat'); if(!box)return null;
  let inner=document.getElementById('chatInner');
  if(!inner){inner=document.createElement('div');inner.id='chatInner';box.appendChild(inner);}
  return inner;
}
/* 채팅 '내용만' 비웁니다. box.innerHTML='' 로 지우면 안쪽 상자까지 날아갑니다. */
function clearChatBody(){const inner=chatBody(); if(inner)inner.innerHTML='';}

function initChatResize(){
  const el=document.getElementById('chat'); if(!el)return;
  try{const z=localStorage.getItem('pzChatZoom'); applyChatZoom(z||100);}catch(e){applyChatZoom(100);}
  try{const h=localStorage.getItem('pzChatH'); if(h&&+h>=160)el.style.height=h+'px';}catch(e){}
  if(window.ResizeObserver){
    let t; new ResizeObserver(function(){clearTimeout(t);t=setTimeout(function(){
      try{localStorage.setItem('pzChatH',Math.round(el.offsetHeight));}catch(e){}
    },400);}).observe(el);
  }
  window.addEventListener('resize',function(){clearTimeout(window._rgT);window._rgT=setTimeout(reflowGrid,200);});
}
function setDupM(m){dupMonths=m;try{localStorage.setItem('pzDupM',m);}catch(e){}renderRecentWinners();}
const PCATS=[
  {k:'마우스패드',re:/마우스\s*패드|패드|gigantus|mousepad/i,c:'#a78bfa',i:'🟪'},
  {k:'마우스 화이트',re:/viper.*white|razer.*white|화이트/i,c:'#ffffff',i:'🖱️'},
  {k:'마우스 CS2',re:/viper.*cs2|razer.*cs2/i,c:'#ffd166',i:'🖱️'},
  {k:'마우스 Faker',re:/faker|페이커/i,c:'#ff4d5a',i:'🖱️'},
  {k:'마우스',re:/마우스|viper|razer|mouse/i,c:'#4aa3ff',i:'🖱️'},
  {k:'유니폼',re:/유니폼|uniform|jamie/i,c:'#f87171',i:'👕'},
  {k:'안경',re:/안경|wearwhere|glass/i,c:'#4ade80',i:'👓'},
  {k:'쿠폰·코드',re:/쿠폰|포인트|코드|coupon|point|code/i,c:'#ffb020',i:'🎟️'}];
function prizeCat(p){for(const x of PCATS)if(x.re.test(p||''))return x;return{k:'기타',c:'#8a93a6',i:'🎁'};}
let _rwSig='';
async function showPastDay(date){
  document.getElementById('pastTitle').textContent=date+' — 최종 시청자 활약';
  document.getElementById('pastBody').innerHTML='<div class="hint">불러오는 중…</div>';
  document.getElementById('pastModal').style.display='flex';
  let j;
  try{j=await (await fetch('prize_api.php?act=stats_get&date='+encodeURIComponent(date))).json();}
  catch(e){document.getElementById('pastBody').innerHTML='<div class="warn">불러오기 실패</div>';return;}
  const u=j.users||{}, ud=j.uid||{};
  const arr=Object.entries(u).map(([nick,x])=>({nick:nick,c:(x&&x.c)||0,b:(x&&x.b)||0,g:(x&&x.g)||0}));
  const medal=['🥇','🥈','🥉'];
  function tbl(valKey,label,cls){
    const rows=arr.filter(x=>x[valKey]>0).sort((a,b)=>b[valKey]-a[valKey]).slice(0,50);
    return '<div class="subhead">'+label+'</div><div class="scroll" style="max-height:230px"><table><thead><tr>'+
      '<th class="num">#</th><th>닉네임</th><th>SOOP계정</th><th class="num">'+(valKey==='c'?'채팅':'별풍선')+'</th></tr></thead><tbody>'+
      (rows.length?rows.map((x,i)=>'<tr><td class="num" style="color:#8a93a6">'+(medal[i]||(i+1))+
        '</td><td>'+kingNick(gradeBits(x.g),x.nick)+'</td><td class="pill acc" style="font-size:11px">'+esc(ud[x.nick]||'-')+
        '</td><td class="num '+cls+'">'+x[valKey]+'</td></tr>').join(''):'<tr><td colspan="4" style="color:#8a93a6;padding:12px">없음</td></tr>')+
      '</tbody></table></div>';
  }
  document.getElementById('pastBody').innerHTML=
    '<div class="hint">저장 시각 '+esc(j.savedAt||'-')+' · 시청자 '+arr.length+'명'+(j.title?' · '+esc(j.title):'')+'</div>'+
    tbl('c','💬 채팅왕','')+tbl('b','👑 후원왕','balloon');
}
function closePast(){document.getElementById('pastModal').style.display='none';}

/* 누적 순위 — 여러 방송(stats)을 모아 연속 출석·기간 채팅·기간 후원 */
let cumWeeks=8;
function closeCum(){document.getElementById('cumModal').style.display='none';}
function renderCumWeeks(){
  const el=document.getElementById('cumWeeks'); if(!el)return;
  el.innerHTML=[2,4,8,12,26].map(w=>'<span class="gchip'+(cumWeeks===w?' on':'')+'" data-w="'+w+'">'+w+'주</span>').join(' ');
  el.querySelectorAll('[data-w]').forEach(c=>c.onclick=()=>openCumulative(+c.dataset.w));
}
async function openCumulative(w){
  if(w)cumWeeks=w;
  document.getElementById('cumModal').style.display='flex';
  renderCumWeeks();
  document.getElementById('cumBody').innerHTML='<div class="hint">불러오는 중…</div>';
  let j;
  try{j=await (await fetch('prize_api.php?act=cumulative&weeks='+cumWeeks)).json();}
  catch(e){document.getElementById('cumBody').innerHTML='<div class="warn">불러오기 실패</div>';return;}
  document.getElementById('cumMeta').textContent='방송 '+(j.broadcasts||0)+'회'+(j.lastDate?' · 최근 '+j.lastDate:'');
  const medal=['🥇','🥈','🥉'];
  function tbl(title,list,valLabel,valKey,cls,unit){
    const rows=(list||[]).slice(0,40);
    return '<div class="subhead">'+title+'</div><div class="scroll" style="max-height:220px"><table><thead><tr>'+
      '<th class="num">#</th><th>닉네임</th><th>SOOP계정</th><th class="num">'+valLabel+'</th></tr></thead><tbody>'+
      (rows.length?rows.map((x,i)=>'<tr><td class="num" style="color:#8a93a6">'+(medal[i]||(i+1))+
        '</td><td>'+kingNick(gradeBits(x.g),x.nick)+'</td><td class="pill acc" style="font-size:11px">'+esc(x.sid||'-')+
        '</td><td class="num '+cls+'">'+x[valKey]+unit+'</td></tr>').join(''):'<tr><td colspan="4" style="color:#8a93a6;padding:12px">아직 없습니다</td></tr>')+
      '</tbody></table></div>';
  }
  document.getElementById('cumBody').innerHTML=
    tbl('🔥 연속 출석 <span class="n">최근 방송부터 연속으로 온 횟수</span>',j.streak,'연속','streak','','회')+
    tbl('💬 기간 채팅왕 <span class="n">최근 '+cumWeeks+'주 채팅 합계</span>',j.chat,'채팅','v','','')+
    tbl('👑 기간 후원왕 <span class="n">최근 '+cumWeeks+'주 별풍선 합계</span>',j.balloon,'별풍선','v','balloon','');
}

/* 중복 당첨 — 같은 사람(닉/계정)이 같은 상품을 2번 이상 받은 것 */
function findDups(){
  if(!ST)return [];
  const map={};
  (ST.winners.list||[]).forEach(w=>{
    if(/연습/.test(w.how||'')||!w.prize)return;
    const person=(w.sid||'').toLowerCase()||norm(w.nick);
    const key=person+'|'+norm(w.prize);
    const m=map[key]||(map[key]={nick:w.nick,sid:w.sid||'',prize:w.prize,n:0,dates:[]});
    m.n++; m.nick=w.nick; if(w.sid)m.sid=w.sid; if(w.date)m.dates.push(w.date);
  });
  return Object.values(map).filter(x=>x.n>=2).sort((a,b)=>b.n-a.n);
}
function dupPersonKeys(){
  const set=new Set();
  findDups().forEach(d=>set.add((d.sid||'').toLowerCase()||norm(d.nick)));
  return set;
}
let dupDismissed='';
function dupSig(dups){return dups.map(d=>d.nick+'|'+d.prize+'|'+d.n).join(',');}
function dismissDup(){dupDismissed=dupSig(findDups())||'_none_';const el=document.getElementById('dupBanner');if(el)el.style.display='none';}
function renderDupBanner(){
  const el=document.getElementById('dupBanner'); if(!el)return;
  // 당첨자 시트 패널을 X로 닫았으면 배너도 숨김
  if(typeof panelState!=='undefined'&&panelState['winners']){el.style.display='none';return;}
  const dups=findDups();
  const sig=dupSig(dups)||'_none_';
  if(sig===dupDismissed){el.style.display='none';return;}   // 사용자가 배너 X로 끈 상태 (내용 바뀌면 다시 뜸)
  const X='<button class="px" style="float:right;margin:-2px -4px 0 0;color:#ff8fa3" onclick="dismissDup()" title="배너 닫기">✕</button>';
  if(!dups.length){el.className='dupbanner ok';el.style.display='';el.innerHTML=X+'✓ 같은 상품 중복 당첨 없음';return;}
  el.className='dupbanner';el.style.display='';
  el.innerHTML=X+'🚨 <b>같은 상품 중복 당첨 '+dups.length+'건</b> — 확인 필요<br>'+
    dups.slice(0,30).map(d=>'<span class="pill" style="border-color:#ff4d5a;color:#ff8fa3;margin:2px 0;display:inline-block">'+
      esc(d.nick)+(d.sid?' <span style="color:#8a93a6">('+esc(d.sid)+')</span>':'')+' × '+esc(d.prize)+' <b>'+d.n+'회</b></span>').join(' ');
}
function renderChatLegend(){
  const el=document.getElementById('chatLegend'); if(!el||!ST)return;
  const byCat={};
  const add=(name)=>{if(!name)return;const c=prizeCat(name);const b=byCat[c.k]||(byCat[c.k]={i:c.i,c:c.c,names:[]});if(!b.names.includes(name))b.names.push(name);};
  (ST.prizes.items||[]).forEach(x=>add(x.name));
  (ST.winners.list||[]).forEach(w=>add(w.prize));
  const keys=Object.keys(byCat);
  if(!keys.length){el.style.display='none';return;}
  el.style.display='';
  el.innerHTML='<div class="hint" style="margin:0 0 3px">🎁 당첨 상품 아이콘 — 채팅 이름 옆에 뜹니다</div>'+
    keys.map(k=>{const b=byCat[k];
      return '<div class="lgrow"><span class="wicon" style="background:'+b.c+'">'+b.i+'</span> <b style="color:'+b.c+'">'+esc(k)+'</b> '+
        '<span class="n" style="font-size:11px">'+esc(b.names.join(', '))+'</span></div>';}).join('');
}
function renderRecentWinners(){
  const host=document.getElementById('recentWinners');if(!host||!ST)return;
  const list=ST.winners.list||[];
  const sig=dupMonths+'|'+list.length+'|'+list.map(w=>w.date+w.prize).join(',');
  if(sig===_rwSig)return; _rwSig=sig;
  const cut=new Date(Date.now()-dupMonths*31*864e5).toISOString().slice(0,10);
  const byp={};
  list.forEach(w=>{
    if(!w.date||w.date<cut)return;
    if(/연습/.test(w.how||''))return;
    const key=(w.sid||'').toLowerCase()||norm(w.nick);
    const pp=byp[key]||(byp[key]={nick:w.nick,sid:w.sid||'',date:w.date,cats:{},prizes:[]});
    if(w.date>=pp.date){pp.date=w.date;pp.nick=w.nick;}
    if(w.sid&&!pp.sid)pp.sid=w.sid;
    const c=prizeCat(w.prize);pp.cats[c.k]=c.c;pp.prizes.push({d:w.date,prize:w.prize,c:c.c});
  });
  const arr=Object.values(byp).sort((a,b)=>b.date<a.date?-1:(b.date>a.date?1:0));
  document.querySelectorAll('[data-dupm],[onclick^=\"setDupM\"]').forEach(()=>{});
  document.querySelectorAll('.chip').forEach(b=>{
    const m=(b.getAttribute('onclick')||'').match(/setDupM\((\d)/);
    if(m)b.classList.toggle('on',+m[1]===dupMonths);});
  const lg=document.getElementById('dupLegend');
  if(lg)lg.innerHTML='상품 색 — '+PCATS.map(x=>'<span style=\"color:'+x.c+';font-weight:700\">●</span>'+x.k).join(' &nbsp;');
  if(!arr.length){host.innerHTML='<div class=\"hint\">최근 '+dupMonths+'개월 당첨자가 없습니다.</div>';return;}
  const dk=dupPersonKeys();
  host.innerHTML='<div class=\"hint\" style=\"margin:0 0 4px\">최근 '+dupMonths+'개월 '+arr.length+'명 — 다시 뽑지 않는 게 좋습니다</div>'+
    arr.map(pp=>{
      const isDup=dk.has((pp.sid||'').toLowerCase()||norm(pp.nick));
      const primary=isDup?'#ff4d5a':pp.prizes[pp.prizes.length-1].c;
      const badges=Object.entries(pp.cats).map(([k,c])=>'<span title=\"'+esc(k)+'\" style=\"display:inline-block;width:10px;height:10px;border-radius:3px;background:'+c+';margin-right:2px;vertical-align:middle\"></span>').join('');
      const names=[...new Set(pp.prizes.map(x=>x.prize))].join(', ');
      return '<div class=\"rwrow\" title=\"'+esc(names)+(isDup?' — 같은 상품 중복 당첨!':'')+'\">'+(isDup?'🚨':badges)+' <b style=\"color:'+primary+'\">'+esc(pp.nick)+'</b>'+
        (pp.sid?' <span class=\"pill acc\" style=\"font-size:10px\">'+esc(pp.sid)+'</span>':'')+
        ' <span class=\"n\" style=\"font-size:10.5px\">'+esc(pp.date)+'</span></div>';
    }).join('');
}
async function loadGdoc(){
  try{const g=await (await fetch('prize_api.php?act=gdoc')).json();
    gdoc={names:g.names||[],ids:g.ids||[]};
    document.getElementById('gdocInfo').textContent=g.note||'';}catch(e){}
}
function clearChat(){recent.length=0;clearChatBody();}
function clearStats(){
  if(!confirm('집계·계정·채팅 로그를 모두 초기화할까요? 당첨자 시트는 그대로 둡니다.'))return;
  for(const k in users)delete users[k];
  for(const k in uid)delete uid[k];
  for(const k in gradeOf)delete gradeOf[k];
  recent.length=0;logBuf.length=0;macroCount=0;
  try{localStorage.removeItem(LSKEY);}catch(e){}
  if(!IS_TEST_CH&&sess.date){
    api('stats_save',{date:sess.date,title:liveTitle,users:{},rawUnknown:[],uid:{}});
    api('chat_clear',{date:sess.date});
  }
  sess.date=sess.on?todayStr():'';
  if(!IS_TEST_CH)api('session_set',{session:sess});
  clearChatBody();paint();}
function downloadLedger(){
  if(!ST||!ST.winners.list.length)return alert('당첨 기록이 없습니다');
  const NL=String.fromCharCode(10);
  const rows=ST.winners.list.map(w=>['"'+(w.date||'')+'"','"'+(w.nick||'').replace(/"/g,'""')+'"',
    '"'+(w.sid||'')+'"','"'+(w.prize||'').replace(/"/g,'""')+'"','"'+(w.how||'')+'"'].join(','));
  const csv=String.fromCharCode(0xFEFF)+['날짜,닉네임,SOOP계정,상품,방식'].concat(rows).join(NL);
  const a=document.createElement('a');
  a.href='data:text/csv;charset=utf-8,'+encodeURIComponent(csv);
  a.download='끝장전-당첨자-'+new Date().toISOString().slice(0,10)+'.csv';
  a.click();}
function copyLedger(){
  if(!ST||!ST.winners.list.length)return alert('당첨 기록이 없습니다');
  const TAB=String.fromCharCode(9),NL=String.fromCharCode(10);
  const txt=ST.winners.list.map(w=>[w.date,w.nick,w.sid||'',w.prize,w.how].join(TAB)).join(NL);
  navigator.clipboard.writeText(['날짜','닉네임','아이디','상품','방식'].join(TAB)+NL+txt)
    .then(()=>alert('당첨 기록 '+ST.winners.list.length+'건을 복사했습니다 (엑셀·문서에 붙여넣기)'));
}
async function slackReport(){
  const tot=Object.values(users).reduce((a,u)=>({c:a.c+u.c,b:a.b+u.b}),{c:0,b:0});
  const todays=(ST?ST.winners.list:[]).filter(w=>w.date===new Date().toISOString().slice(0,10));
  const NL=String.fromCharCode(10);
  const text='🎁 끝장전 상품 추첨 요약'+NL+'시청자 '+Object.keys(users).length+'명 · 채팅 '+tot.c+' · 별풍선 '+tot.b+NL+'오늘 당첨 '+todays.length+'명'+(todays.length?': '+todays.map(w=>w.nick+'('+w.prize+')').join(', '):'');
  const r=await api('slack_report',{text});
  if(r.ok)alert('슬랙으로 보냈습니다');else alert('슬랙 전송 실패 — 웹훅 주소를 확인하세요');
}
function pickThis(n){
  const el=document.getElementById('pickNick');
  el.value=n;dupCheck();
  el.style.background='#12283f';setTimeout(function(){el.style.background='';},450);
  if(window.innerWidth<=760)el.scrollIntoView({block:'center',behavior:'smooth'});
}
/* 채팅 이름 옆 ✉ — SOOP 아이디를 클립보드에 복사하고 쪽지 창을 새 탭으로 엽니다.
   쪽지 링크는 설정에서 지정(‘{id}’ 자리에 아이디). 비어 있으면 SOOP 방송국 페이지. */
function sendNote(id){
  if(!id)return;
  try{if(navigator.clipboard&&navigator.clipboard.writeText)navigator.clipboard.writeText(id);}catch(e){}
  const tpl=(settings.noteUrl||'').trim();
  const url=tpl?tpl.replace(/\{id\}/g,encodeURIComponent(id))
               :'https://www.sooplive.com/station/'+encodeURIComponent(id);
  window.open(url,'_blank','noopener');
  pzToast('✉ '+id+' 아이디 복사됨 · 쪽지 받는 사람에 붙여넣기(Ctrl+V)');
}
function dmBtn(e){return e.id?'<span class="dm" data-dm="'+esc(e.id)+'" title="쪽지: 아이디 복사 + SOOP 열기">✉</span>':'';}
let _pzToastT;
function pzToast(msg){
  let el=document.getElementById('pzToast');
  if(!el){el=document.createElement('div');el.id='pzToast';el.className='pztoast';document.body.appendChild(el);}
  el.textContent=msg;el.classList.add('on');
  clearTimeout(_pzToastT);_pzToastT=setTimeout(function(){el.classList.remove('on');},2800);
}
/* 채팅 위 등급 필터 바 — 열혈/팬/구독 인원수 표시 + 클릭하면 그 등급만 보기 */
function renderGradeBar(nSup,nFan,nSub){
  const el=document.getElementById('gradeBar'); if(!el)return;
  function chip(g,label,n){return '<span class="gchip'+(chatGrade===g?' on':'')+'" data-grade="'+g+'">'+label+(g?' <b>'+n+'</b>':'')+'</span>';}
  el.innerHTML=chip('','전체',0)+chip('sup','🔴 열혈',nSup)+chip('fan','🟢 팬',nFan)+chip('sub','🔵 구독',nSub);
  el.querySelectorAll('[data-grade]').forEach(c=>c.onclick=()=>setChatGrade(c.dataset.grade));
}
function setChatGrade(g){chatGrade=(chatGrade===g?'':g);paint();}
/* 닉네임 색 등급 클래스 — 열혈>구독>팬 (별풍선 줄 등은 gradeOf 로 보완) */
function nkClass(e){
  const g=e.sup?'sup':e.sub?'sub':e.fan?'fan':(gradeOf[e.nick]||'');
  return g==='sup'?'nk-yeol':g==='sub'?'nk-sub':g==='fan'?'nk-fan':'';
}
/* 등급 비트(1=팬 · 2=열혈 · 4=구독)를 채팅 줄과 같은 모양으로 폅니다.
   nick 을 비워 두는 것은 일부러입니다 — nkClass 가 gradeOf(오늘 채팅 캐시)로 보완하지
   못하게 해서, 지난 방송·누적 표에 '오늘 등급'이 섞이지 않게 합니다. */
function gradeBits(g){
  g=g|0;
  return {nick:'', fan:!!(g&1), sup:!!(g&2), sub:!!(g&4)};
}
/* 지금 방송 활약표용 — 등급 출처가 둘이라 반드시 합쳐서 봅니다.
     users[nick].g  저장되는 기록. 팬·열혈·구독을 다 담고 새로고침 뒤에도 남습니다.
     gradeOf[nick]  오늘 채팅에서 본 것. 한 가지만 담고 저장되지 않습니다.
   ⚠ 처음 넣을 때(2026-09-18) users[].g 만 봤더니, 색은 nkClass 가 gradeOf 로 보완하는데
     배지는 보완이 안 돼 '색은 있는데 마크가 없는' 사람이 생겼습니다. 그래서 합칩니다.
     예전에 저장된 기록에는 g 가 아예 없어서 이 보완이 꼭 필요합니다. */
function gradeOfUser(nick){
  const u=users[nick], e=gradeBits((u&&u.g)||0), s=gradeOf[nick]||'';
  if(s==='fan')e.fan=true; else if(s==='sup')e.sup=true; else if(s==='sub')e.sub=true;
  return e;
}
/* 활약표 닉네임 한 칸 — 배지 + 등급 색. 지금·지난 방송·누적 세 곳이 같은 모양을 쓰게 합니다. */
function kingNick(e,nick){return fbadges(e)+'<b class="kn '+nkClass(e)+'">'+esc(nick)+'</b>';}
function dupCheck(){
  const n=document.getElementById('pickNick').value.trim();
  if(!n){document.getElementById('dupwarn').innerHTML='';return;}
  const [cnt,last]=winCount(n);
  const pz=curPrize();
  const msgs=[];
  if(pz&&hasWonPrize(n,pz))msgs.push('<span class="warn" style="font-weight:800">🚫 이미 이 상품('+esc(pz)+')에 당첨 — 중복!</span>');
  if(cnt)msgs.push('<span class="warn">⚠ 다른 상품 포함 '+cnt+'회 당첨 (마지막 '+esc(last)+')</span>');
  if(settings.excludeWeeks&&recentWin(n))msgs.push('<span class="warn">⚠ 최근 '+settings.excludeWeeks+'주 내 당첨</span>');
  if(inGdoc(n))msgs.push('<span class="warn">⚠ 구글 문서 당첨자 명단에 있음</span>');
  document.getElementById('dupwarn').innerHTML=msgs.length?msgs.join(' '):'<span class="ok">✓ 당첨 기록 없음</span>';
}
document.getElementById('pickNick').addEventListener('input',dupCheck);
document.getElementById('prizeSel').addEventListener('change',dupCheck);
document.getElementById('pickNick').addEventListener('keydown',function(e){if(e.key==='Enter')manualPick();});
document.getElementById('chat').addEventListener('click',function(ev){
  const dm=ev.target.closest('[data-dm]');
  if(dm){sendNote(dm.dataset.dm);return;}   // ✉ 클릭은 쪽지 (지명 안 함)
  const line=ev.target.closest('.chatline[data-nick]');
  if(line&&line.dataset.nick)pickThis(line.dataset.nick);
});

/* ── 화면 그리기 + 서버 상태 ── */
async function refresh(){
  try{ST=await (await fetch('prize_api.php?act=state')).json();}catch(e){return}
  if(ST.settings&&ST.settings.chatFull)settings=Object.assign(settings,ST.settings);
  rebuildExcl();
  const sel=document.getElementById('prizeSel'),cur=sel.value;
  sel.innerHTML='<option value="">상품 없이</option>'+ST.prizes.items.map(x=>
    '<option value="'+x.id+'">'+esc(x.name)+'</option>').join('');
  if([...sel.options].some(o=>o.value===cur))sel.value=cur;
  const givenCount={};
  (ST.winners.list||[]).forEach(w=>{if(w.prize)givenCount[w.prize]=(givenCount[w.prize]||0)+1;});
  document.getElementById('prizes').innerHTML=ST.prizes.items.map((x,i)=>{
    const g=givenCount[x.name]||0;
    return '<div class="row">'+(x.photo?'<img class="thumb" src="'+x.photo+'">':'')+
    '<span style="flex:1">'+esc(x.name)+(g?' <span class="pill" style="color:#4ade80;border-color:#2c6b3f">✓ '+g+'회 지급</span>':'')+'</span>'+
    '<button class="gray" style="padding:3px 7px" data-mv="up" data-id="'+x.id+'"'+(i===0?' disabled':'')+'>▲</button>'+
    '<button class="gray" style="padding:3px 7px" data-mv="down" data-id="'+x.id+'"'+(i===ST.prizes.items.length-1?' disabled':'')+'>▼</button>'+
    '<button class="gray" style="padding:3px 9px" data-show="'+x.id+'">📺 보여주기</button>'+
    '<button class="red" style="padding:3px 9px" data-del="'+x.id+'">지우기</button></div>';})
    .join('')||'<div class="hint">아직 상품이 없습니다.</div>';
  document.querySelectorAll('[data-del]').forEach(b=>b.onclick=async()=>{
    await api('prize_del',{id:b.dataset.del});refresh();});
  document.querySelectorAll('[data-mv]').forEach(b=>b.onclick=async()=>{
    await api('prize_move',{id:b.dataset.id,dir:b.dataset.mv});refresh();});
  document.querySelectorAll('[data-show]').forEach(b=>b.onclick=async()=>{
    const pz=prizeOf(b.dataset.show)||{};
    await api('overlay_set',{overlay:{kind:'prize',prize:pz.name||'',photo:pz.photo||''}});});
  const wl=ST.winners.list;
  document.getElementById('wcount').textContent=wl.length+'건';
  renderDupBanner();
  renderChatLegend();
  document.querySelector('#winners tbody').innerHTML=wl.slice().reverse().map(w=>{
    const acc=w.sid||'';
    return '<tr><td>'+esc(w.date)+'</td><td><b>'+esc(w.nick)+'</b></td>'+
    '<td>'+(acc?'<span class="pill acc" style="font-size:11px">'+esc(acc)+'</span>'
      :'<span class="warn" style="font-size:11px">없음</span>')+'</td>'+
    '<td>'+esc(w.prize)+'</td><td class="pill">'+esc(w.how||'')+'</td>'+
    '<td style="white-space:nowrap">'+
    (acc?'<button class="gray" style="padding:1px 6px" data-note="'+esc(acc)+'" title="쪽지">✉</button>':'')+
    '<button class="gray" style="padding:1px 6px" data-wedit="'+w.id+'" title="편집">✏</button>'+
    '<button class="red" style="padding:1px 6px" data-wdel="'+w.id+'" title="삭제">×</button></td></tr>';
  }).join('');
  document.querySelectorAll('[data-wdel]').forEach(b=>b.onclick=async()=>{
    if(!confirm('이 당첨 기록을 지울까요?'))return;
    await api('winner_del',{id:b.dataset.wdel});refresh();});
  document.querySelectorAll('[data-note]').forEach(b=>b.onclick=()=>sendNote(b.dataset.note));
  document.querySelectorAll('[data-wedit]').forEach(b=>b.onclick=async()=>{
    const w=wl.find(x=>x.id===b.dataset.wedit); if(!w)return;
    const nick=prompt('닉네임',w.nick); if(nick===null)return;
    const sid=prompt('SOOP 계정 아이디 (쪽지 보낼 본계정)',w.sid||''); if(sid===null)return;
    const prize=prompt('상품',w.prize||''); if(prize===null)return;
    await api('winner_update',{id:w.id,nick:nick.trim(),sid:sid.trim(),prize:prize.trim()});refresh();});
  for(const [id,v] of [['sChatFull',settings.chatFull],['sChatMax',settings.chatBonusMax],
    ['sBalFull',settings.balloonFull],['sBalMax',settings.balloonBonusMax],
    ['sWeeks',settings.excludeWeeks||0],['sAlert',settings.balloonAlert||100],
    ['sGdoc',settings.gdocId||''],['sSlack',settings.slackWebhook||''],
    ['sExclAcc',settings.excludeAccounts||''],
    ['sNoteUrl',settings.noteUrl||''],
    ['sTestAcc',settings.testAccount||''],
    ['sRealCh',settings.realChannels||'']]){
    const el=document.getElementById(id);
    if(el&&document.activeElement!==el)el.value=v;
  }
  const ex=document.getElementById('sExcl');if(ex)ex.checked=!!settings.excludeWinners;
  rebuildExcl();applyRealCh();
  try{
    const pl=await (await fetch('prize_api.php?act=stats_list')).json();
    document.getElementById('pastdays').innerHTML='<tbody>'+pl.list.map(r=>
      '<tr data-date="'+esc(r.date)+'" title="클릭하면 그날 최종 활약을 봅니다"><td>'+esc(r.date)+' ↗</td><td class="num">'+r.users+'명</td>'+
      '<td class="num">'+r.chats+'</td><td class="num balloon">'+r.balloons+
      '</td></tr>').join('')+'</tbody>';
    document.querySelectorAll('#pastdays [data-date]').forEach(tr=>
      tr.onclick=()=>showPastDay(tr.dataset.date));
  }catch(e){}
}
function paint(){
  document.getElementById('totline').textContent='시청자 '
    +Object.keys(users).length+' · 채팅 '
    +Object.values(users).reduce((a,u)=>a+u.c,0)+' · 별풍선 '
    +Object.values(users).reduce((a,u)=>a+u.b,0)+(macroCount?' · 방송채팅 '+macroCount+' 제외':'');
  const chatEl=document.getElementById('chat');
  const chatInner=chatBody()||chatEl;
  const _wc={};   // 이 렌더 동안 닉별 당첨 정보 캐시 {n, icons}
  function winInfo(nick){
    if(nick in _wc)return _wc[nick];
    const nn=norm(nick), sid=(uid[nick]||'').toLowerCase();
    const wins=(ST?ST.winners.list:[]).filter(w=>norm(w.nick)===nn||(sid&&(w.sid||'').toLowerCase()===sid));
    // 당첨 건마다 아이콘 하나씩 (같은 종류끼리 모아 정렬) — 8회면 8개 다 보이게
    const wsorted=wins.slice().sort((a,b)=>prizeCat(a.prize).k.localeCompare(prizeCat(b.prize).k));
    const icons=wsorted.map(w=>{const c=prizeCat(w.prize);return '<span class="wicon" style="background:'+c.c+'" title="'+esc(w.prize)+'">'+c.i+'</span>';}).join('');
    return (_wc[nick]={n:wins.length, icons:icons});
  }
  function wtitle(nick){
    const w=winInfo(nick);
    return (w.n?'🏆 당첨 '+w.n+'회':'당첨 없음')+' · 눌러서 당첨 만들기에 넣기';
  }
  function wtag(nick){
    const w=winInfo(nick);
    return w.n?' <span class="wtag">(당첨 '+w.n+'회)</span>'+w.icons:'';
  }
  // 등급 카운트(고유 닉) + 등급 필터
  const _view=recent.slice(-CHAT_MAX);
  const _gs={sup:new Set(),fan:new Set(),sub:new Set()};
  for(const e of _view){if(e.sup)_gs.sup.add(e.nick);if(e.fan)_gs.fan.add(e.nick);if(e.sub)_gs.sub.add(e.nick);}
  renderGradeBar(_gs.sup.size,_gs.fan.size,_gs.sub.size);
  const _shown=chatGrade?_view.filter(e=>chatGrade==='sup'?e.sup:chatGrade==='fan'?e.fan:chatGrade==='sub'?e.sub:true):_view;
  // 새 메시지·당첨·필터 변화가 있을 때만 다시 그림 — 위로 올려 읽던 스크롤을 흔들지 않음
  const _csig=chatGrade+'|'+_shown.length+'|'+(_view.length?(_view[_view.length-1].s||0):0)+'|'+(ST?ST.winners.list.length:0);
  if(_csig!==window._chatSig){
    window._chatSig=_csig;
    const atBottom=chatEl.scrollHeight-chatEl.scrollTop-chatEl.clientHeight<40;
    const prevTop=chatEl.scrollTop;
    chatInner.innerHTML=_shown.map(e=>
      e.t==='balloon'
      ?'<div class="chatline" data-nick="'+esc(e.nick)+'" title="'+esc(wtitle(e.nick))+'">🎈 '+fbadges(e)+'<b class="'+nkClass(e)+'">'+esc(e.nick)+'</b>'+wtag(e.nick)+dmBtn(e)+' <span class="balloon">별풍선 '
        +e.count+'개</span> <span class="pill">'+e.at+'</span></div>'
      :'<div class="chatline" data-nick="'+esc(e.nick)+'" title="'+esc(wtitle(e.nick))+'"><span class="pill" style="margin-right:5px">'+e.at
        +'</span>'+fbadges(e)+'<b class="'+nkClass(e)+'">'+esc(e.nick)+'</b>'+wtag(e.nick)+dmBtn(e)+' '+esc(e.msg)+'</div>').join('');
    chatEl.scrollTop=atBottom?chatEl.scrollHeight:prevTop;
  }
  const arr=Object.entries(users).map(([nick,u])=>({nick,c:u.c,b:u.b,wins:winCount(nick)[0]}));
  const medal=['🥇','🥈','🥉'];
  function kingRows(list,valKey,cls,maxv){
    if(!list.length)return '<tr><td colspan="6" style="color:#8a93a6;padding:14px 4px">아직 없습니다</td></tr>';
    return list.slice(0,100).map((u,i)=>{
      const val=u[valKey], pct=Math.round(val/maxv*100), g=gradeOfUser(u.nick);
      return '<tr><td class="num" style="color:#8a93a6">'+(medal[i]||(i+1))+'</td>'+
      '<td><div class="actbar" style="width:'+pct+'%"></div>'+kingNick(g,u.nick)+'</td>'+
      '<td class="pill acc" style="font-size:11px">'+esc(uid[u.nick]||'-')+'</td>'+
      '<td class="num '+cls+'">'+val+'</td>'+
      '<td class="num">'+(u.wins?'<span class="warn">'+u.wins+'회</span>':'')+'</td>'+
      '<td><button class="gray" style="padding:2px 8px" data-pick="'+esc(u.nick)+'">지명</button></td></tr>';
    }).join('');
  }
  const byChat=arr.filter(u=>u.c>0).sort((a,b)=>b.c-a.c||b.b-a.b);
  const byBal=arr.filter(u=>u.b>0).sort((a,b)=>b.b-a.b||b.c-a.c);
  document.querySelector('#kingChat tbody').innerHTML=kingRows(byChat,'c','',Math.max(1,...byChat.map(u=>u.c)));
  document.querySelector('#kingBal tbody').innerHTML=kingRows(byBal,'b','balloon',Math.max(1,...byBal.map(u=>u.b)));
  document.querySelectorAll('[data-pick]').forEach(b=>
    b.onclick=()=>pickThis(b.dataset.pick));
  renderRecentWinners();
}
/* 45초마다 오늘 집계를 서버에 남깁니다 — 지난 방송 기록이 됩니다 */
async function snapshot(){
  if(IS_TEST_CH||!sess.on)return;          // 시험 채널·종료 상태에선 저장 안 함
  if(Object.keys(users).length===0)return;
  await api('stats_save',{date:sess.date,title:liveTitle,users,rawUnknown,uid});
  api('session_set',{session:sess});
}
function todayStr(){return new Date().toISOString().slice(0,10)}
function sessBtns(){
  const s=document.getElementById('btnStart'),e=document.getElementById('btnStop');
  if(s)s.disabled=sess.on;
  if(e)e.disabled=!sess.on;
}
/* 채팅 로그를 서버에 이어 붙입니다 — 초기화 전까지 보존 */
async function flushLog(){
  if(IS_TEST_CH||!logBuf.length)return;
  const lines=logBuf.splice(0);
  const r=await api('chat_log',{date:sess.date,lines});
  if(!r||!r.ok)logBuf.unshift(...lines.slice(-500));
}
setInterval(flushLog,20000);
window.addEventListener('beforeunload',()=>{
  saveLive();   // 이 브라우저에 즉시 저장 (창을 나가도 안 사라지게 — 가장 확실)
  if(IS_TEST_CH||!navigator.sendBeacon)return;
  if(logBuf.length)navigator.sendBeacon('prize_api.php',new Blob([JSON.stringify(
    {act:'chat_log',date:sess.date,lines:logBuf.splice(0,2000)})],{type:'application/json'}));
  if(sess.on&&Object.keys(users).length)navigator.sendBeacon('prize_api.php',new Blob([JSON.stringify(
    {act:'stats_save',date:sess.date,title:liveTitle,users,rawUnknown:[],uid})],{type:'application/json'}));
});
async function startSession(){
  if(sess.on)return;
  sess.on=true;
  if(!sess.date)sess.date=todayStr();       // 이어서 할 땐 기존 날짜 유지
  if(sess.date!==todayStr()&&!confirm('저장돼 있는 '+sess.date+' 집계에 이어서 셉니다. 계속할까요?\n(새로 시작하려면 취소 후 집계 초기화를 먼저 누르세요)')){
    sess.on=false;return;
  }
  sess.startedAt=now().slice(0,5);
  if(!IS_TEST_CH)await api('session_set',{session:sess});
  sessBtns();
  if(IS_DEMO){setStatus('<span class="live">● 연습 모드</span> 가짜 채팅이 흐릅니다');return;}
  connectChat();
}
async function stopSession(){
  if(!sess.on)return;
  if(!confirm('집계를 종료할까요? 지금까지의 채팅·집계·계정은 그대로 저장돼 있습니다.'))return;
  sess.on=false;
  await flushLog();
  if(!IS_TEST_CH){
    if(Object.keys(users).length)
      await api('stats_save',{date:sess.date,title:liveTitle,users,rawUnknown,uid});
    await api('session_set',{session:sess});
  }
  try{if(ws)ws.close();}catch(e){}
  sessBtns();
  setStatus('⏹ 종료 상태 — 데이터는 저장돼 있습니다. ▶ 스타트로 다시 시작');
}
/* 창을 껐다 켜면 지난 상태(집계·계정·채팅창)를 통째로 이어받습니다 */
/* 창을 나가도(뒤로가기·닫기) 채팅창·집계가 사라지지 않게 이 브라우저에 저장합니다.
   집계 초기화 전까지 유지하고, 이틀 지난 것은 버립니다
   (서버의 날짜별 stats/chatlog 파일이 원본 보관 = 파일화). */
function saveLive(){
  if(IS_TEST_CH)return;
  try{localStorage.setItem(LSKEY,JSON.stringify({
    v:1,sess:sess,users:users,uid:uid,macroCount:macroCount,
    recent:recent.slice(-CHAT_MAX),savedEpoch:Date.now()}));}catch(e){}
}
function loadLive(){
  if(IS_TEST_CH)return false;
  try{
    const d=JSON.parse(localStorage.getItem(LSKEY)||'null');
    if(!d)return false;
    if(Date.now()-(d.savedEpoch||0)>2*24*60*60*1000){localStorage.removeItem(LSKEY);return false;}
    if(d.sess){sess.on=!!d.sess.on;sess.date=d.sess.date||'';sess.startedAt=d.sess.startedAt||'';}
    const u=d.users||{};for(const k in u)users[k]=u[k];
    const iu=d.uid||{};for(const k in iu)uid[k]=iu[k];
    if(typeof d.macroCount==='number')macroCount=d.macroCount;
    if(Array.isArray(d.recent)&&recent.length===0)recent.push(...d.recent);
    return true;
  }catch(e){return false;}
}
setInterval(saveLive,3000);
addEventListener('pagehide',saveLive);
async function restoreSession(){
  if(IS_TEST_CH)return;
  const hadLocal=loadLive();   // 이 브라우저에 남은 채팅·집계를 즉시 복원
  sessBtns();paint();
  try{
    const st=await (await fetch('prize_api.php?act=state')).json();
    const sv=(st&&st.session)||{};
    if(!hadLocal){sess.on=!!sv.on;sess.date=sv.date||'';sess.startedAt=sv.startedAt||'';}
    else if(!sess.date&&sv.date){sess.date=sv.date;sess.on=!!sv.on;sess.startedAt=sv.startedAt||'';}
  }catch(e){}
  sessBtns();
  if(!sess.date)return;
  try{
    const j=await (await fetch('prize_api.php?act=stats_get&date='+sess.date)).json();
    const u=j.users||{};
    for(const k in u)if(!users[k])users[k]={c:u[k].c||0,b:u[k].b||0};
    const iu=j.uid||{};
    for(const k in iu)if(!uid[k])uid[k]=iu[k];
  }catch(e){}
  try{
    const t=await (await fetch('prize_api.php?act=chat_tail&date='+sess.date)).json();
    const lines=(t&&t.lines)||[];
    if(lines.length&&recent.length===0)recent.push(...lines.slice(-CHAT_MAX));
  }catch(e){}
  paint();
}
setInterval(paint,1500);
setInterval(snapshot,45000);
refresh();setInterval(refresh,6000);
loadGdoc();setInterval(loadGdoc,300000);
applyRealCh();
if(localStorage.getItem('pzMaskAcc'))document.body.classList.add('maskacc');
updateMaskBtn();
initPanels();
initChatResize();
ttPaint();ttRestore();
restoreSession().finally(connectChat);
/* 연습: 주소 뒤에 ?demo 를 붙이면 가짜 채팅이 흐릅니다 */
if(location.search.includes('demo')){
  const NICKS=['별사탕요정','테란만세','저글링1000','프로브혁명','캐리어가요',
    'GG치지마','빌드깎는노인','더블넥좋아','뮤탈짤짤이','벙커링장인'];
  // 등급 섞기 — 열혈·구독·팬·일반이 골고루 나오게 (아이디 색·배지 확인용)
  const GRADES=[{sup:true,fan:true},{fan:true},{fan:true},{sub:true},{fan:true,sub:true},
    {},{sub:true},{},{fan:true},{}];
  const MSGS=['ㅋㅋㅋㅋ','이걸 막네','오늘 폼 미쳤다','9세트 가자','GG','역전각','지리네요'];
  liveOn=true;liveTitle='(연습)';
  setStatus('<span class="live">● 연습 모드</span> 가짜 채팅 — 🔴열혈·🔵구독·🟢팬·⚪일반 섞임 (기록 저장 안 함)');
  sess.on=true;sess.date=todayStr();sessBtns();
  const DIDS=['byeolst4r','terranzzang','zergrun1000','probe1017','carrier4u',
    'ggnooo','buildgm88','doublenex','mutalking','bunkerman'];
  setInterval(()=>{
    if(!sess.on)return;
    const i=Math.floor(Math.random()*NICKS.length), n=NICKS[i];
    if(Math.random()<0.12)onEvent({t:'balloon',nick:n,id:DIDS[i],at:now(),
      count:[1,5,10,50,100,500][Math.floor(Math.random()*6)]});   // 별풍선 줄은 gradeOf 로 색 유지
    else onEvent(Object.assign({t:'chat',nick:n,id:DIDS[i],at:now(),
      msg:MSGS[Math.floor(Math.random()*MSGS.length)]},GRADES[i]));
  },500);
}
</script></body></html>
