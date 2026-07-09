<?php
// capture.php?tour=ID — режим A: съёмка 360 (кольцо + стрелки-подсказки, автоснимок),
// сшивка в браузере (CPU), автосохранение в тур через api/upload_scene.php.
// Механика съёмки перенесена из отдельного приложения «Пано 360» (понравившийся
// пользователю вариант) и интегрирована в платформу (логин + тур + CSRF).
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/actions.php';

$user = require_login();
$tour_id = (int)($_GET['tour'] ?? 0);
$tour = own_tour($tour_id, (int)$user['id']);
if (!$tour) redirect('dashboard.php');
$token = csrf_token();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>Съёмка 360° — <?= htmlspecialchars($tour['name'], ENT_QUOTES, 'UTF-8') ?></title>
<style>
  :root{
    --bg:#0B0E11; --panel:#151A20; --line:#2A323C;
    --text:#F5F7F4; --muted:#9DA8B4;
    --accent:#FFB020; --ok:#3FD68C; --danger:#FF5A5A; --radius:18px;
  }
  *{box-sizing:border-box; margin:0; padding:0; -webkit-tap-highlight-color:transparent;}
  html,body{height:100%; overflow:hidden;}
  body{background:var(--bg); color:var(--text);
    font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif; font-size:19px; line-height:1.45;}
  .screen{position:fixed; inset:0; display:none; flex-direction:column; overflow:hidden;}
  .screen.active{display:flex;}
  .scroll{overflow-y:auto; -webkit-overflow-scrolling:touch; flex:1; padding:20px;}
  h1{font-size:28px; font-weight:800; letter-spacing:-0.5px;}
  h2{font-size:21px; font-weight:700; margin-bottom:8px;}
  .sub{color:var(--muted); font-size:16px;}
  a.link{color:#7fb0ff;}
  .btn{display:flex; align-items:center; justify-content:center; gap:10px; width:100%;
    min-height:58px; padding:14px 18px; background:var(--accent); color:#14100A; border:none;
    border-radius:var(--radius); font-size:20px; font-weight:800; font-family:inherit; cursor:pointer;}
  .btn:active{transform:scale(0.98);}
  .btn.secondary{background:var(--panel); color:var(--text); border:2px solid var(--line);}
  .btn.small{min-height:48px; font-size:16px; width:auto; padding:8px 16px;}
  .btn:disabled{opacity:0.4;}
  .card{background:var(--panel); border:1px solid var(--line); border-radius:var(--radius); padding:16px; margin-bottom:14px;}
  input[type=text]{width:100%; min-height:52px; padding:10px 14px; margin-top:6px; background:#0F1318;
    color:var(--text); border:2px solid var(--line); border-radius:12px; font-size:18px; font-family:inherit;}
  label.setting{display:block; margin-top:12px; font-size:16px; color:var(--muted);}
  input[type=range]{width:100%; height:36px; accent-color:var(--accent);}
  select{width:100%; min-height:52px; padding:10px 14px; margin-top:6px; background:#0F1318; color:var(--text);
    border:2px solid var(--line); border-radius:12px; font-size:18px; font-family:inherit;}
  .warn{background:#241A08; border:1px solid #5A430F; color:#FFD98A; border-radius:12px;
    padding:12px 14px; font-size:15px; margin-bottom:14px;}

  #capture{background:#000;}
  #videoWrap{position:absolute; inset:0;}
  #video{width:100%; height:100%; object-fit:cover;}
  #hud{position:absolute; inset:0; pointer-events:none;}
  #ring{position:absolute; left:50%; top:45%; transform:translate(-50%,-50%); width:180px; height:180px;
    border-radius:50%; border:6px solid var(--accent); transition:border-color .15s;
    display:flex; align-items:center; justify-content:center;}
  #ring.locked{border-color:var(--ok); box-shadow:0 0 40px rgba(63,214,140,.55);}
  #ring .dot{width:14px; height:14px; border-radius:50%; background:var(--accent);}
  #ring.locked .dot{background:var(--ok);}
  /* шкала фиксации: кольцо-прогресс «держите» (как заполняющийся круг у Matterport) */
  #ring .fill{position:absolute; inset:4px; border-radius:50%; opacity:.6; background:transparent;
    -webkit-mask:radial-gradient(transparent 45%, #000 46%); mask:radial-gradient(transparent 45%, #000 46%);}
  #arrows{position:absolute; left:50%; top:45%; transform:translate(-50%,-50%); width:260px; height:260px;}
  .arrow{position:absolute; font-size:44px; font-weight:900; color:var(--accent);
    text-shadow:0 2px 8px rgba(0,0,0,.8); display:none;}
  .arrow.show{display:block;}
  #arrL{left:-30px; top:50%; transform:translateY(-50%);}
  #arrR{right:-30px; top:50%; transform:translateY(-50%);}
  #arrU{top:-34px; left:50%; transform:translateX(-50%);}
  #arrD{bottom:-34px; left:50%; transform:translateX(-50%);}
  #capTop{position:absolute; top:0; left:0; right:0; padding:16px 18px; display:flex;
    justify-content:space-between; align-items:center; background:linear-gradient(rgba(0,0,0,.7),transparent);}
  #capProgress{font-size:24px; font-weight:800;}
  #capHint{position:absolute; left:0; right:0; top:62%; text-align:center; font-size:19px; font-weight:700;
    text-shadow:0 2px 6px rgba(0,0,0,.9); padding:0 24px;}
  #capBottom{position:absolute; bottom:0; left:0; right:0; padding:18px; display:flex; gap:12px;
    align-items:center; background:linear-gradient(transparent,rgba(0,0,0,.75));}
  #capBottom .btn, #capTop .btn{pointer-events:auto;}
  #shutter{width:84px; height:84px; min-height:84px; border-radius:50%; background:#fff; color:#000; font-size:15px; flex:none;}
  #flash{position:absolute; inset:0; background:#fff; opacity:0; pointer-events:none; transition:opacity .18s;}
  #flash.on{opacity:.85;}
  #rotateWarn{position:absolute; inset:0; z-index:9; display:none; align-items:center; justify-content:center;
    text-align:center; background:rgba(0,0,0,.88); font-size:23px; font-weight:800; padding:30px;}
  #rotateWarn.show{display:flex;}

  #stitch .scroll{display:flex; flex-direction:column; justify-content:center; text-align:center;}
  .barWrap{width:100%; height:26px; background:var(--panel); border:1px solid var(--line);
    border-radius:13px; overflow:hidden; margin:18px 0;}
  #bar{height:100%; width:0%; background:var(--accent); transition:width .2s;}
  #stitchPct{font-size:44px; font-weight:900;}
  .err{background:#2A1112; border:1px solid #6B2226; color:#FFB3B6; border-radius:12px;
    padding:12px 14px; font-size:15px; margin-top:14px;}
</style>
</head>
<body>

<!-- ========== СТАРТ / НАСТРОЙКИ ========== -->
<div class="screen active" id="start">
  <div class="scroll">
    <h1>Съёмка 360°</h1>
    <p class="sub" style="margin-top:6px">Тур: «<?= htmlspecialchars($tour['name'], ENT_QUOTES, 'UTF-8') ?>». Панорама сохранится в него автоматически.</p>

    <div class="warn" id="httpsWarn" style="display:none">
      Камера и гироскоп работают только по HTTPS. Открой эту страницу по адресу с https://
    </div>
    <div class="warn" id="gyroWarn" style="display:none">
      На этом устройстве нет гироскопа — автоматическая съёмка 360 недоступна. Открой со смартфона.
    </div>

    <div class="card" style="margin-top:16px">
      <h2>Как снимать</h2>
      <p class="sub">1. Встань в центр комнаты и не сходи с места.<br>
      2. Держи телефон вертикально, поворачивайся к цели.<br>
      3. Наведи кольцо и <b>замри</b> — кольцо начнёт заполняться, снимок сделается сам.<br>
      4. Важно: снимай стоя на месте, без движения — так кадры резче.<br>
      5. Всего 22 кадра: круг прямо, вверх и вниз.</p>
    </div>

    <div class="card">
      <h2>Настройки</h2>
      <label class="setting">Название сцены (необязательно)</label>
      <input type="text" id="sceneName" placeholder="Гостиная">
      <label class="setting">Угол обзора камеры по ширине: <b id="fovVal" style="color:var(--text)">50°</b></label>
      <input type="range" id="fovRange" min="40" max="70" value="50" step="1">
      <p class="sub">Разрывы между кадрами — увеличь. Двоение — уменьши.</p>
      <label class="setting">Размер панорамы</label>
      <select id="qualitySel">
        <option value="2048">2048 × 1024 — быстро, для слабых телефонов</option>
        <option value="3072" selected>3072 × 1536 — баланс</option>
        <option value="4096">4096 × 2048 — максимум качества</option>
      </select>
    </div>

    <button class="btn" id="btnNewRoom">📷&nbsp;Начать съёмку</button>
    <div style="height:12px"></div>
    <a class="link" href="tour_edit.php?id=<?= (int)$tour_id ?>">← вернуться в тур</a>
  </div>
</div>

<!-- ========== ЭКРАН СЪЁМКИ ========== -->
<div class="screen" id="capture">
  <div id="videoWrap"><video id="video" autoplay muted playsinline></video></div>
  <div id="hud">
    <div id="capTop">
      <div id="capProgress">0 / 22</div>
      <button class="btn small secondary" id="btnCancelCap">Отмена</button>
    </div>
    <div id="arrows">
      <span class="arrow" id="arrL">←</span><span class="arrow" id="arrR">→</span>
      <span class="arrow" id="arrU">↑</span><span class="arrow" id="arrD">↓</span>
    </div>
    <div id="ring"><div class="fill" id="ringFill"></div><div class="dot"></div></div>
    <div id="capHint">Поворачивайтесь к цели</div>
    <div id="capBottom">
      <button class="btn secondary" id="btnFinishCap" disabled>Завершить</button>
      <button class="btn" id="shutter">Снять</button>
    </div>
    <div id="flash"></div>
    <div id="rotateWarn">Поверните телефон вертикально 📱</div>
  </div>
</div>

<!-- ========== ЭКРАН СШИВКИ / СОХРАНЕНИЯ ========== -->
<div class="screen" id="stitch">
  <div class="scroll">
    <h1 id="stitchTitle">Сшиваю панораму…</h1>
    <p class="sub" style="margin-top:8px">Не закрывайте страницу.</p>
    <div class="barWrap"><div id="bar"></div></div>
    <div id="stitchPct">0%</div>
    <div class="err" id="stitchErr" style="display:none"></div>
  </div>
</div>

<script>
"use strict";
(function () {
  const $ = id => document.getElementById(id);
  const D2R = Math.PI/180, R2D = 180/Math.PI;
  const TOUR_ID = <?= (int)$tour_id ?>;
  const CSRF = <?= json_encode($token) ?>;

  const state = {
    currentRoom: null, hfovDeg: 50, panoW: 3072,
    orient: null, stream: null, capturing: false, lockStart: 0, wakeLock: null, gyroSeen: false,
  };

  function show(id){
    document.querySelectorAll('.screen').forEach(s=>s.classList.remove('active'));
    $(id).classList.add('active');
  }

  if (location.protocol !== 'https:' && location.hostname !== 'localhost' && location.hostname !== '127.0.0.1') {
    $('httpsWarn').style.display = 'block';
  }

  // ---- настройки (с памятью) ----
  $('fovRange').addEventListener('input', e=>{
    state.hfovDeg = +e.target.value; $('fovVal').textContent = state.hfovDeg + '°';
    try{ localStorage.setItem('pano_fov', state.hfovDeg); }catch(e){}
  });
  $('qualitySel').addEventListener('change', e=>{
    state.panoW = +e.target.value;
    try{ localStorage.setItem('pano_w', state.panoW); }catch(e){}
  });
  try{
    const f = +localStorage.getItem('pano_fov');
    if (f >= 40 && f <= 70){ state.hfovDeg=f; $('fovRange').value=f; $('fovVal').textContent=f+'°'; }
    const w = +localStorage.getItem('pano_w');
    if ([2048,3072,4096].includes(w)){ state.panoW=w; $('qualitySel').value=w; }
  }catch(e){}

  // ---- математика ориентации ----
  function rotMat(alphaDeg, betaDeg, gammaDeg){
    const a=alphaDeg*D2R, b=betaDeg*D2R, g=gammaDeg*D2R;
    const ca=Math.cos(a), sa=Math.sin(a), cb=Math.cos(b), sb=Math.sin(b), cg=Math.cos(g), sg=Math.sin(g);
    return [ ca*cg - sa*sb*sg, -sa*cb, ca*sg + sa*sb*cg,
             sa*cg + ca*sb*sg,  ca*cb, sa*sg - ca*sb*cg,
             -cb*sg,            sb,    cb*cg ];
  }
  function camDir(R){ return [-R[2], -R[5], -R[8]]; }
  function dirToYawPitch(f){ return { yaw: Math.atan2(f[0], f[1]),
    pitch: Math.asin(Math.max(-1, Math.min(1, f[2]))) }; }
  function angDiff(a,b){ let d=a-b; while(d>Math.PI)d-=2*Math.PI; while(d<-Math.PI)d+=2*Math.PI; return d; }

  window.addEventListener('deviceorientation', e=>{
    if (e.alpha===null || e.beta===null || e.gamma===null) return;
    state.gyroSeen = true;
    state.orient = { alpha:e.alpha, beta:e.beta, gamma:e.gamma };
  });
  setTimeout(()=>{ if (!state.gyroSeen && !('ontouchstart' in window)) $('gyroWarn').style.display='block'; }, 3000);

  // ---- цели: 3 кольца (горизонт 10, +55° 6, -55° 6) ----
  function makeTargets(yaw0){
    const t = [];
    [{p:0,n:10},{p:55,n:6},{p:-55,n:6}].forEach(r=>{
      for(let i=0;i<r.n;i++){
        let y = yaw0 + i*(2*Math.PI/r.n);
        while(y>Math.PI)y-=2*Math.PI; while(y<-Math.PI)y+=2*Math.PI;
        t.push({yaw:y, pitch:r.p*D2R, done:false});
      }
    });
    return t;
  }

  // ---- съёмка ----
  $('btnNewRoom').addEventListener('click', startCapture);
  $('btnCancelCap').addEventListener('click', ()=>stopCapture(false));
  $('btnFinishCap').addEventListener('click', ()=>stopCapture(true));
  $('shutter').addEventListener('click', ()=>snap(true));

  async function startCapture(){
    try{
      if (typeof DeviceOrientationEvent !== 'undefined' && typeof DeviceOrientationEvent.requestPermission === 'function'){
        const p = await DeviceOrientationEvent.requestPermission();
        if (p !== 'granted'){ alert('Без доступа к датчикам движения съёмка 360 невозможна.'); return; }
      }
    }catch(e){}
    try{
      state.stream = await navigator.mediaDevices.getUserMedia({
        video:{ facingMode:'environment', width:{ideal:1920}, height:{ideal:1080} }, audio:false });
    }catch(e){
      alert('Нет доступа к камере: ' + e.message + '\nОткройте страницу по HTTPS и разрешите камеру.');
      return;
    }
    const v = $('video'); v.srcObject = state.stream;
    try{ await v.play(); }catch(e){}
    try{ if (navigator.wakeLock) state.wakeLock = await navigator.wakeLock.request('screen'); }catch(e){}

    state.currentRoom = { shots: [], targets: null };
    state.capturing = true;
    state.prevCur = null; state.prevT = 0; state.angSpeed = 999; state.holdMs = 0; state.lastLoop = 0;
    $('capProgress').textContent = '0 / 22'; $('btnFinishCap').disabled = true;
    $('ringFill').style.background = 'transparent';
    show('capture'); requestAnimationFrame(captureLoop);
  }

  function stopCapture(finish){
    if (!state.capturing) return;
    state.capturing = false;
    if (state.stream){ state.stream.getTracks().forEach(t=>t.stop()); state.stream=null; }
    if (state.wakeLock){ state.wakeLock.release().catch(()=>{}); state.wakeLock=null; }
    const room = state.currentRoom;
    if (finish && room && room.shots.length >= 8){ runStitch(room); }
    else {
      if (finish) alert('Слишком мало кадров для панорамы (нужно хотя бы 8).');
      state.currentRoom = null; show('start');
    }
  }

  function isLandscape(){
    if (screen.orientation && screen.orientation.type) return screen.orientation.type.startsWith('landscape');
    return window.innerWidth > window.innerHeight;
  }

  // Пороги «замри и держи»
  const STILL_DPS = 6;    // телефон считается неподвижным ниже этой угловой скорости (°/сек)
  const ALIGN_YAW = 6;    // допуск наведения по горизонтали, °
  const ALIGN_PITCH = 5;  // по вертикали, °
  const HOLD_MS = 600;    // сколько держать (неподвижно + наведено) до снимка

  function captureLoop(){
    if (!state.capturing) return;
    requestAnimationFrame(captureLoop);
    $('rotateWarn').classList.toggle('show', isLandscape());
    if (isLandscape()) return;
    if (!state.orient){ $('capHint').textContent='Ожидаю данные гироскопа…'; return; }

    const now = performance.now();
    const frameDt = state.lastLoop ? (now - state.lastLoop) : 16;
    state.lastLoop = now;

    const R = rotMat(state.orient.alpha, state.orient.beta, state.orient.gamma);
    const cur = dirToYawPitch(camDir(R));

    // Угловая скорость: насколько быстро крутится телефон (для проверки неподвижности)
    if (state.prevCur){
      const dt = Math.max(1, now - state.prevT);
      const vy = angDiff(cur.yaw, state.prevCur.yaw) * R2D;
      const vp = (cur.pitch - state.prevCur.pitch) * R2D;
      const inst = Math.hypot(vy, vp) / (dt / 1000);
      state.angSpeed = state.angSpeed * 0.6 + inst * 0.4;   // сглаживание
    }
    state.prevCur = cur; state.prevT = now;

    const room = state.currentRoom;
    if (!room.targets) room.targets = makeTargets(cur.yaw);

    const pending = room.targets.filter(t=>!t.done);
    const done = room.targets.length - pending.length;
    $('capProgress').textContent = done + ' / ' + room.targets.length;
    $('btnFinishCap').disabled = done < Math.ceil(room.targets.length*0.6);
    if (!pending.length){ stopCapture(true); return; }

    let best=null, bestD=Infinity;
    pending.forEach(t=>{
      const dy=angDiff(t.yaw,cur.yaw), dp=t.pitch-cur.pitch;
      const d=Math.hypot(dy*Math.cos(cur.pitch), dp);
      if(d<bestD){bestD=d; best=t;}
    });
    const dy = angDiff(best.yaw, cur.yaw)*R2D, dp = (best.pitch - cur.pitch)*R2D;
    $('arrL').classList.toggle('show', dy < -ALIGN_YAW);
    $('arrR').classList.toggle('show', dy >  ALIGN_YAW);
    $('arrU').classList.toggle('show', dp >  ALIGN_PITCH);
    $('arrD').classList.toggle('show', dp < -ALIGN_PITCH);

    const aligned = Math.abs(dy) <= ALIGN_YAW && Math.abs(dp) <= ALIGN_PITCH;
    const still = state.angSpeed < STILL_DPS;
    $('ring').classList.toggle('locked', aligned && still);

    if (aligned && still){
      // копим время удержания и рисуем заполняющееся кольцо
      state.holdMs += frameDt;
      const pct = Math.min(1, state.holdMs / HOLD_MS);
      $('ringFill').style.background = 'conic-gradient(var(--ok) ' + (pct*360).toFixed(0) + 'deg, transparent 0deg)';
      $('capHint').textContent = 'Держите… ' + Math.round(pct*100) + '%';
      if (state.holdMs >= HOLD_MS){
        snap(false, best, R);
        state.holdMs = 0;
        $('ringFill').style.background = 'transparent';
      }
    } else {
      state.holdMs = 0;
      $('ringFill').style.background = 'transparent';
      if (aligned && !still){
        $('capHint').textContent = 'Замрите — снимок сделается, когда телефон остановится';
      } else {
        const parts=[];
        if (Math.abs(dy) > ALIGN_YAW) parts.push((dy>0?'вправо ':'влево ') + Math.round(Math.abs(dy)) + '°');
        if (Math.abs(dp) > ALIGN_PITCH) parts.push((dp>0?'выше ':'ниже ') + Math.round(Math.abs(dp)) + '°');
        $('capHint').textContent = parts.join(', ') || 'Наведите кольцо на цель';
      }
    }
  }

  function snap(manual, target, Rnow){
    const room = state.currentRoom;
    if (!room || !state.orient || !state.capturing) return;
    const R = Rnow || rotMat(state.orient.alpha, state.orient.beta, state.orient.gamma);
    if (!target && room.targets){
      const cur = dirToYawPitch(camDir(R));
      let best=null, bestD=Infinity;
      room.targets.filter(t=>!t.done).forEach(t=>{
        const d=Math.hypot(angDiff(t.yaw,cur.yaw)*Math.cos(cur.pitch), t.pitch-cur.pitch);
        if(d<bestD){bestD=d; best=t;}
      });
      target = best;
    }
    if (target) target.done = true;
    const v = $('video');
    if (!v.videoWidth) return;
    // Кадру достаточно ~(доля FOV в панораме)×запас; не тянем полный сенсор ради памяти.
    const need = state.panoW * (state.hfovDeg / 360) * 2.4;
    const cap = Math.max(1000, Math.min(1600, need));
    const scale = Math.min(1, cap / Math.max(v.videoWidth, v.videoHeight));
    const c = document.createElement('canvas');
    c.width  = Math.round(v.videoWidth * scale);
    c.height = Math.round(v.videoHeight * scale);
    c.getContext('2d').drawImage(v, 0, 0, c.width, c.height);
    room.shots.push({ canvas:c, R });
    $('flash').classList.add('on'); setTimeout(()=>$('flash').classList.remove('on'), 180);
    if (navigator.vibrate) navigator.vibrate(40);
  }

  // ---- сшивка (CPU, проекция каждого кадра на equirect) ----
  function setProgress(f){
    const p = Math.max(0, Math.min(100, Math.round(f*100)));
    $('bar').style.width = p + '%'; $('stitchPct').textContent = p + '%';
  }

  async function runStitch(room){
    show('stitch'); $('stitchErr').style.display='none';
    $('stitchTitle').textContent = 'Сшиваю панораму…'; setProgress(0);
    const W = state.panoW, H = W/2, hfov = state.hfovDeg * D2R;

    let acc, wgt;
    try{ acc = new Float32Array(W*H*3); wgt = new Float32Array(W*H); }
    catch(e){ fail('Недостаточно памяти для этого размера. Выберите размер меньше в настройках.'); return; }

    for (let s=0; s<room.shots.length; s++){
      await projectShot(room.shots[s], acc, wgt, W, H, hfov, frac => setProgress((s + frac) / room.shots.length * 0.85));
    }

    const out = document.createElement('canvas');
    out.width = W; out.height = H;
    const ctx = out.getContext('2d');
    const img = ctx.createImageData(W, H); const px = img.data;
    for (let i=0, n=W*H; i<n; i++){
      const w = wgt[i], o = i*4;
      if (w > 0){ px[o]=acc[i*3]/w; px[o+1]=acc[i*3+1]/w; px[o+2]=acc[i*3+2]/w; }
      else { px[o]=30; px[o+1]=33; px[o+2]=38; }
      px[o+3] = 255;
    }
    ctx.putImageData(img, 0, 0);
    room.shots = []; acc = wgt = null; setProgress(0.88);

    $('stitchTitle').textContent = 'Сохраняю в тур…';
    const blob = await new Promise(res => out.toBlob(res, 'image/jpeg', 0.87));
    if (!blob){ fail('Не удалось подготовить изображение.'); return; }

    while (true){
      try{
        await uploadToTour(blob, f => setProgress(0.88 + f*0.12));
        setProgress(1);
        window.location.href = 'tour_edit.php?id=' + TOUR_ID;
        return;
      }catch(e){
        if (!confirm('Не удалось сохранить на сервер:\n' + e.message + '\n\nПовторить попытку?')){
          fail('Сохранение отменено. Можно переснять.');
          return;
        }
      }
    }
  }

  function fail(msg){
    $('stitchTitle').textContent = 'Не удалось';
    const el = $('stitchErr'); el.textContent = msg; el.style.display = 'block';
    setTimeout(()=>show('start'), 2500);
  }

  function uploadToTour(blob, onProgress){
    return new Promise((resolve, reject)=>{
      const fd = new FormData();
      fd.append('csrf', CSRF);
      fd.append('tour_id', String(TOUR_ID));
      fd.append('title', ($('sceneName').value || '').slice(0,60));
      fd.append('image', blob, 'pano.jpg');
      const xhr = new XMLHttpRequest();
      xhr.open('POST', 'api/upload_scene.php');
      xhr.timeout = 120000;
      xhr.upload.onprogress = e => { if (e.lengthComputable && onProgress) onProgress(e.loaded/e.total); };
      xhr.onload = ()=>{
        try{ const j = JSON.parse(xhr.responseText);
          if (j.ok) resolve(j); else reject(new Error(j.error || ('Код ответа ' + xhr.status)));
        }catch(e){ reject(new Error('Некорректный ответ сервера (код ' + xhr.status + ')')); }
      };
      xhr.onerror   = ()=>reject(new Error('Нет связи с сервером'));
      xhr.ontimeout = ()=>reject(new Error('Сервер не ответил вовремя'));
      xhr.send(fd);
    });
  }

  function projectShot(shot, acc, wgt, W, H, hfov, onProgress){
    return new Promise(resolve=>{
      const img = shot.canvas, iw = img.width, ih = img.height;
      const data = img.getContext('2d').getImageData(0,0,iw,ih).data;
      const R = shot.R;
      const f = (iw/2) / Math.tan(hfov/2);
      const vfovHalf = Math.atan((ih/2)/f);
      const cw = iw/2, ch = ih/2;
      const { yaw:yaw0, pitch:pitch0 } = dirToYawPitch(camDir(R));
      const pMax = Math.min( Math.PI/2, pitch0 + vfovHalf*1.25);
      const pMin = Math.max(-Math.PI/2, pitch0 - vfovHalf*1.25);
      const yStart = Math.max(0,   Math.floor((Math.PI/2 - pMax)/Math.PI * H));
      const yEnd   = Math.min(H-1, Math.ceil ((Math.PI/2 - pMin)/Math.PI * H));
      const colC = (yaw0 + Math.PI)/(2*Math.PI) * W;
      const hfovHalf = hfov/2;
      const r0=R[0], r1=R[1], r2=R[2], r3=R[3], r4=R[4], r5=R[5], r6=R[6], r7=R[7], r8=R[8];

      let y = yStart; const CHUNK = 32;
      function chunk(){
        const yStop = Math.min(yEnd, y + CHUNK);
        for (; y<=yStop; y++){
          const phi = Math.PI/2 - (y+0.5)/H * Math.PI;
          const cph = Math.cos(phi), sph = Math.sin(phi);
          const halfSpan = Math.min(Math.PI, hfovHalf*1.35 / Math.max(0.12, cph));
          const halfPix = Math.floor(Math.min(W/2, halfSpan/(2*Math.PI) * W));
          const rowOff = y*W;
          for (let k=-halfPix; k<=halfPix; k++){
            const x = ((Math.round(colC + k) % W) + W) % W;
            const lam = (x+0.5)/W * 2*Math.PI - Math.PI;
            const dx = cph*Math.sin(lam), dyw = cph*Math.cos(lam), dz = sph;
            const ddx = r0*dx + r3*dyw + r6*dz;
            const ddy = r1*dx + r4*dyw + r7*dz;
            const ddz = r2*dx + r5*dyw + r8*dz;
            if (ddz > -0.05) continue;
            const t = -1/ddz;
            const ix = cw + f*ddx*t;
            const iy = ch - f*ddy*t;
            if (ix<0 || iy<0 || ix>=iw-1 || iy>=ih-1) continue;
            const wx = 1 - Math.abs(ix-cw)/cw;
            const wy = 1 - Math.abs(iy-ch)/ch;
            const w = wx*wy*wx*wy + 1e-4;
            const x0=ix|0, y0=iy|0, fx=ix-x0, fy=iy-y0;
            const i00=(y0*iw+x0)*4, i01=i00+4, i10=i00+iw*4, i11=i10+4;
            const w00=(1-fx)*(1-fy), w01=fx*(1-fy), w10=(1-fx)*fy, w11=fx*fy;
            const pi = rowOff + x;
            acc[pi*3]   += w*(data[i00]*w00 + data[i01]*w01 + data[i10]*w10 + data[i11]*w11);
            acc[pi*3+1] += w*(data[i00+1]*w00 + data[i01+1]*w01 + data[i10+1]*w10 + data[i11+1]*w11);
            acc[pi*3+2] += w*(data[i00+2]*w00 + data[i01+2]*w01 + data[i10+2]*w10 + data[i11+2]*w11);
            wgt[pi] += w;
          }
        }
        onProgress((y - yStart) / Math.max(1, yEnd - yStart));
        if (y <= yEnd) setTimeout(chunk, 0); else resolve();
      }
      chunk();
    });
  }
})();
</script>
</body>
</html>
