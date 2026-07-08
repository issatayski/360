<?php
// capture.php?tour=ID — режим A: съёмка (камера+гироскоп) + WebGL-склейка в equirect,
// затем отправка собранной панорамы в тур через api/upload_scene.php.
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/actions.php';

$user = require_login();
$tour_id = (int)($_GET['tour'] ?? 0);
$tour = own_tour($tour_id, (int)$user['id']);
if (!$tour) redirect('dashboard.php');
$token = csrf_token();
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<title>Съёмка 360° — <?= h($tour['name']) ?></title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; -webkit-tap-highlight-color: transparent; }
  html, body { height: 100%; overflow: hidden; background: #0b0d12; color: #fff;
    font-family: -apple-system, Segoe UI, Roboto, sans-serif; touch-action: none; }
  .screen { position: absolute; inset: 0; display: none; }
  .screen.on { display: block; }
  button { border: none; border-radius: 12px; padding: 13px 18px; font-size: 15px; font-weight: 600;
    background: rgba(255,255,255,.16); color: #fff; cursor: pointer; }
  button.primary { background: #0a84ff; } button.good { background: #34c759; }
  button:disabled { opacity: .4; cursor: default; }
  a.link { color: #7fb0ff; font-size: 14px; }

  #start { display: flex; flex-direction: column; align-items: center; justify-content: center;
    text-align: center; padding: 28px; gap: 16px; }
  #start h1 { font-size: 24px; } #start p { opacity: .7; max-width: 440px; line-height: 1.5; font-size: 15px; }
  #start .mode { font-size: 12px; opacity: .45; margin-top: 8px; }

  #video, #sim { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; }
  #sim { display: none; }
  .reticle { position: absolute; left: 50%; top: 50%; width: 64px; height: 64px;
    transform: translate(-50%, -50%); border: 2px solid rgba(255,255,255,.8); border-radius: 50%; }
  .reticle::before, .reticle::after { content: ''; position: absolute; background: rgba(255,255,255,.8); }
  .reticle::before { left: 50%; top: 20%; width: 2px; height: 60%; transform: translateX(-50%); }
  .reticle::after { top: 50%; left: 20%; height: 2px; width: 60%; transform: translateY(-50%); }
  #marker { position: absolute; width: 84px; height: 84px; transform: translate(-50%, -50%);
    border: 3px solid #4cd964; border-radius: 50%; box-shadow: 0 0 16px rgba(76,217,100,.6); display: none; }
  #marker.near { border-color: #ffd60a; box-shadow: 0 0 22px rgba(255,214,10,.8); }
  #hud { position: absolute; top: 0; left: 0; right: 0; padding: 14px 16px;
    background: linear-gradient(rgba(0,0,0,.5), transparent); display: flex; gap: 12px; align-items: center; }
  #bar { flex: 1; height: 8px; border-radius: 4px; background: rgba(255,255,255,.25); overflow: hidden; }
  #barFill { height: 100%; width: 0; background: #4cd964; transition: width .2s; }
  #count { font-variant-numeric: tabular-nums; font-size: 15px; min-width: 54px; text-align: right; }
  #hint { position: absolute; left: 50%; bottom: 128px; transform: translateX(-50%);
    background: rgba(0,0,0,.55); padding: 8px 16px; border-radius: 999px; font-size: 14px; white-space: nowrap; }
  #capControls { position: absolute; left: 0; right: 0; bottom: 0;
    padding: 20px 16px calc(20px + env(safe-area-inset-bottom));
    background: linear-gradient(transparent, rgba(0,0,0,.55)); display: flex; gap: 10px; justify-content: center; flex-wrap: wrap; }
  .flash { position: absolute; inset: 0; background: #fff; opacity: 0; pointer-events: none; }
  .flash.on { animation: fl .25s; } @keyframes fl { from { opacity: .8; } to { opacity: 0; } }

  #build { display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 28px; gap: 18px; text-align: center; }
  #build h2 { font-size: 20px; } #buildStatus { opacity: .75; font-size: 14px; min-height: 20px; }
  #pbar { width: 100%; max-width: 360px; height: 10px; border-radius: 5px; background: rgba(255,255,255,.2); overflow: hidden; }
  #pfill { height: 100%; width: 0; background: #0a84ff; transition: width .15s; }
  #preview { max-width: 90%; max-height: 42vh; border-radius: 10px; display: none; box-shadow: 0 8px 30px rgba(0,0,0,.5); }
  #buildBtns { display: flex; gap: 10px; flex-wrap: wrap; justify-content: center; }
  .err { color: #ff6b6b; font-size: 13px; max-width: 90%; }
</style>
</head>
<body>

<!-- START -->
<div class="screen on" id="start">
  <h1>Съёмка 360°</h1>
  <p>Встань в центр комнаты, держи телефон вертикально и вращайся вокруг себя, наводя
     перекрестие в зелёные круги — кадр снимется сам. Пройди все кольца.</p>
  <button class="primary" id="goShoot">📷 Начать съёмку</button>
  <a class="link" href="tour_edit.php?id=<?= (int)$tour_id ?>">← вернуться в тур</a>
  <div class="mode" id="modeLine"></div>
</div>

<!-- CAPTURE -->
<div class="screen" id="capture">
  <video id="video" playsinline muted autoplay></video>
  <canvas id="sim"></canvas>
  <canvas id="grab" style="display:none"></canvas>
  <div class="flash" id="flash"></div>
  <div class="reticle"></div>
  <div id="marker"></div>
  <div id="hud"><div id="bar"><div id="barFill"></div></div><div id="count">0 / 0</div></div>
  <div id="hint">Наведи перекрестие в зелёный круг</div>
  <div id="capControls">
    <button id="calib">Задать «вперёд»</button>
    <button id="shoot" class="primary">Снять кадр</button>
    <button id="build0" class="good" disabled>Собрать 360°</button>
  </div>
</div>

<!-- BUILD + UPLOAD -->
<div class="screen" id="build">
  <h2>Собираю панораму…</h2>
  <div id="pbar"><div id="pfill"></div></div>
  <div id="buildStatus">Готовлю кадры…</div>
  <img id="preview" alt="equirect preview">
  <div id="buildBtns" style="display:none">
    <button class="good" id="sendBtn">✔ Добавить в тур</button>
    <button id="againBtn">Снять заново</button>
  </div>
  <div class="err" id="buildErr"></div>
</div>

<script>
"use strict";
(function () {
  const $ = (id) => document.getElementById(id);
  const DEG = Math.PI / 180;
  const TOUR_ID = <?= (int)$tour_id ?>;
  const CSRF = <?= json_encode($token) ?>;

  const screens = ["start", "capture", "build"];
  function show(name) { for (const s of screens) $(s).classList.toggle("on", s === name); }

  // ---------- СЪЁМКА (камера + гироскоп) ----------
  const HFOV = 65, TOL = 6;
  const RINGS = [
    { pitch: 0, count: 8 }, { pitch: 40, count: 8 }, { pitch: -40, count: 8 },
    { pitch: 72, count: 4 }, { pitch: -72, count: 4 }, { pitch: 90, count: 1 }, { pitch: -90, count: 1 },
  ];
  let TARGETS = [];
  function resetTargets() {
    TARGETS = [];
    for (const r of RINGS) for (let i = 0; i < r.count; i++)
      TARGETS.push({ yaw: (i * 360 / r.count), pitch: r.pitch, done: false });
  }

  const video = $("video"), sim = $("sim"), grab = $("grab");
  const marker = $("marker"), barFill = $("barFill"), count = $("count"), hint = $("hint"), flash = $("flash");
  const view = { yaw: 0, pitch: 0, roll: 0 };
  let yawOffset = 0, orientationOK = false, usingCamera = false, running = false;
  let frames = [];

  const norm180 = (d) => { d = ((d + 180) % 360 + 360) % 360 - 180; return d; };
  const angDist = (t) => Math.hypot(norm180(t.yaw - view.yaw), t.pitch - view.pitch);

  function onOrient(e) {
    if (e.alpha == null) return;
    orientationOK = true;
    view.yaw = norm180(-e.alpha - yawOffset);
    view.pitch = Math.max(-90, Math.min(90, e.beta - 90));
    view.roll = e.gamma || 0;
  }
  let simWired = false;
  function enableDragSim() {
    if (orientationOK || simWired) return; simWired = true;
    hint.textContent = "Мышь/палец: тяни, чтобы осмотреться (имитация гироскопа)";
    let px = 0, py = 0, drag = false;
    const dn = (x, y) => { drag = true; px = x; py = y; };
    const mv = (x, y) => { if (!drag) return; view.yaw = norm180(view.yaw + (x - px) * 0.3);
      view.pitch = Math.max(-90, Math.min(90, view.pitch - (y - py) * 0.3)); px = x; py = y; };
    const cap = $("capture");
    cap.addEventListener("mousedown", (e) => dn(e.clientX, e.clientY));
    window.addEventListener("mousemove", (e) => mv(e.clientX, e.clientY));
    window.addEventListener("mouseup", () => drag = false);
    cap.addEventListener("touchstart", (e) => dn(e.touches[0].clientX, e.touches[0].clientY));
    cap.addEventListener("touchmove", (e) => { mv(e.touches[0].clientX, e.touches[0].clientY); e.preventDefault(); });
    cap.addEventListener("touchend", () => drag = false);
  }

  async function initCamera() {
    try {
      const stream = await navigator.mediaDevices.getUserMedia({
        video: { facingMode: { ideal: "environment" }, width: { ideal: 1280 }, height: { ideal: 960 } }, audio: false });
      video.srcObject = stream; usingCamera = true; video.style.display = "block"; sim.style.display = "none";
    } catch (err) { usingCamera = false; video.style.display = "none"; sim.style.display = "block"; }
  }
  function drawSim() {
    const w = sim.width = sim.clientWidth, h = sim.height = sim.clientHeight;
    const ctx = sim.getContext("2d");
    const hue = ((view.yaw + 180) % 360);
    const g = ctx.createLinearGradient(0, 0, 0, h);
    g.addColorStop(0, `hsl(${hue}, 45%, ${28 + view.pitch / 4}%)`);
    g.addColorStop(1, `hsl(${hue}, 35%, 12%)`);
    ctx.fillStyle = g; ctx.fillRect(0, 0, w, h);
    ctx.fillStyle = "rgba(255,255,255,.85)"; ctx.font = "bold 30px sans-serif"; ctx.textAlign = "center";
    ctx.fillText(`yaw ${view.yaw.toFixed(0)}°  pitch ${view.pitch.toFixed(0)}°`, w / 2, h / 2 - 60);
  }
  function loop() {
    if (!running) return;
    if (!usingCamera) drawSim();
    let best = null, bestD = 1e9;
    for (const t of TARGETS) { if (t.done) continue; const d = angDist(t); if (d < bestD) { bestD = d; best = t; } }
    const capEl = $("capture");
    if (best) {
      const vfov = HFOV * (capEl.clientHeight / capEl.clientWidth);
      const dx = norm180(best.yaw - view.yaw), dy = best.pitch - view.pitch;
      const x = 50 + dx / (HFOV / 2) * 50, y = 50 - dy / (vfov / 2) * 50;
      marker.style.display = "block";
      marker.style.left = Math.max(4, Math.min(96, x)) + "%";
      marker.style.top = Math.max(8, Math.min(92, y)) + "%";
      marker.classList.toggle("near", bestD < TOL * 2.2);
      if (bestD < TOL && Math.abs(dx) < HFOV / 2 && Math.abs(dy) < vfov / 2) capture(best);
    } else { marker.style.display = "none"; hint.textContent = "Все цели сняты — жми «Собрать 360°»"; }
    requestAnimationFrame(loop);
  }
  let busy = false;
  function capture(target) {
    if (busy) return; busy = true;
    const sw = usingCamera ? (video.videoWidth || 1280) : sim.width;
    const sh = usingCamera ? (video.videoHeight || 960) : sim.height;
    grab.width = 1024; grab.height = Math.round(1024 * sh / sw);
    grab.getContext("2d").drawImage(usingCamera ? video : sim, 0, 0, grab.width, grab.height);
    grab.toBlob((blob) => {
      frames.push({ blob, yaw: +view.yaw.toFixed(2), pitch: +view.pitch.toFixed(2), roll: +view.roll.toFixed(2) });
      if (target) target.done = true;
      updateProgress();
      flash.classList.remove("on"); void flash.offsetWidth; flash.classList.add("on");
      if (navigator.vibrate) navigator.vibrate(30);
      setTimeout(() => { busy = false; }, 350);
    }, "image/jpeg", 0.9);
  }
  function updateProgress() {
    const done = TARGETS.filter((t) => t.done).length;
    barFill.style.width = (done / TARGETS.length * 100) + "%";
    count.textContent = `${done} / ${TARGETS.length}`;
    $("build0").disabled = frames.length === 0;
  }
  async function startCapture() {
    frames = []; resetTargets(); running = true; yawOffset = 0; orientationOK = false;
    if (typeof DeviceOrientationEvent !== "undefined" && typeof DeviceOrientationEvent.requestPermission === "function") {
      try { await DeviceOrientationEvent.requestPermission(); } catch (e) {}
    }
    window.addEventListener("deviceorientation", onOrient, true);
    await initCamera();
    show("capture"); updateProgress();
    setTimeout(enableDragSim, 1200);
    loop();
  }
  function stopCamera() {
    running = false;
    const s = video.srcObject; if (s) { s.getTracks().forEach((t) => t.stop()); video.srcObject = null; }
  }

  // ---------- СКЛЕЙКА В EQUIRECT НА WebGL2 (порт stitch.py) ----------
  function Rx(a){const c=Math.cos(a),s=Math.sin(a);return[[1,0,0],[0,c,-s],[0,s,c]];}
  function Ry(a){const c=Math.cos(a),s=Math.sin(a);return[[c,0,s],[0,1,0],[-s,0,c]];}
  function rodrigues(k,th){const c=Math.cos(th),s=Math.sin(th),C=1-c,[x,y,z]=k;return[
    [c+x*x*C, x*y*C-z*s, x*z*C+y*s],[y*x*C+z*s, c+y*y*C, y*z*C-x*s],[z*x*C-y*s, z*y*C+x*s, c+z*z*C]];}
  function mul(a,b){const r=[[0,0,0],[0,0,0],[0,0,0]];
    for(let i=0;i<3;i++)for(let j=0;j<3;j++){let s=0;for(let k=0;k<3;k++)s+=a[i][k]*b[k][j];r[i][j]=s;}return r;}
  function vecMat(v,M){return[v[0]*M[0][0]+v[1]*M[1][0]+v[2]*M[2][0],
    v[0]*M[0][1]+v[1]*M[1][1]+v[2]*M[2][1], v[0]*M[0][2]+v[1]*M[1][2]+v[2]*M[2][2]];}
  function frameM(yaw,pitch,roll){
    const u=-yaw*DEG, v=pitch*DEG, ir=roll*DEG;
    const RxM=Rx(v), RyM=Ry(u);
    let ax=vecMat([0,0,1],RxM); ax=vecMat(ax,RyM);
    const n=Math.hypot(ax[0],ax[1],ax[2])||1; ax=[ax[0]/n,ax[1]/n,ax[2]/n];
    return mul(mul(RxM,RyM), rodrigues(ax,ir));
  }
  function colMajor(M){ return new Float32Array([M[0][0],M[1][0],M[2][0],M[0][1],M[1][1],M[2][1],M[0][2],M[1][2],M[2][2]]); }

  const VS = `#version 300 es
  in vec2 aPos; out vec2 vUV;
  void main(){ vUV = aPos*0.5+0.5; gl_Position = vec4(aPos,0.0,1.0); }`;
  const FS_ACCUM = `#version 300 es
  precision highp float;
  in vec2 vUV; uniform sampler2D uTex; uniform mat3 uM;
  uniform float uXmax, uYmax, uPower; out vec4 frag;
  const float PI = 3.14159265358979;
  void main(){
    float lon = (vUV.x-0.5)*2.0*PI;
    float lat = (vUV.y-0.5)*PI;
    float cl = cos(lat);
    vec3 world = vec3(cl*sin(lon), sin(lat), cl*cos(lon));
    vec3 cam = uM*world;
    if(cam.z <= 1e-6){ frag = vec4(0.0); return; }
    float xn = cam.x/cam.z, yn = cam.y/cam.z;
    if(abs(xn) > uXmax || abs(yn) > uYmax){ frag = vec4(0.0); return; }
    float fx = (xn+uXmax)/(2.0*uXmax);
    float fy = (uYmax-yn)/(2.0*uYmax);
    vec3 c = texture(uTex, vec2(fx,fy)).rgb;
    float wx = clamp(1.0-abs(xn)/uXmax, 0.0, 1.0);
    float wy = clamp(1.0-abs(yn)/uYmax, 0.0, 1.0);
    float w = pow(wx*wy, uPower);
    frag = vec4(c*w, w);
  }`;
  const FS_NORM = `#version 300 es
  precision highp float;
  in vec2 vUV; uniform sampler2D uAccum; out vec4 frag;
  void main(){ vec4 a = texture(uAccum, vUV); frag = vec4(a.a > 1e-5 ? a.rgb/a.a : vec3(0.0), 1.0); }`;

  function compile(gl, type, src) {
    const sh = gl.createShader(type); gl.shaderSource(sh, src); gl.compileShader(sh);
    if (!gl.getShaderParameter(sh, gl.COMPILE_STATUS)) throw new Error("shader: " + gl.getShaderInfoLog(sh));
    return sh;
  }
  function program(gl, vs, fs) {
    const p = gl.createProgram();
    gl.attachShader(p, compile(gl, gl.VERTEX_SHADER, vs));
    gl.attachShader(p, compile(gl, gl.FRAGMENT_SHADER, fs));
    gl.bindAttribLocation(p, 0, "aPos"); gl.linkProgram(p);
    if (!gl.getProgramParameter(p, gl.LINK_STATUS)) throw new Error("link: " + gl.getProgramInfoLog(p));
    return p;
  }
  async function stitchEquirect(items, W, power, onProgress) {
    const H = W / 2;
    const canvas = document.createElement("canvas"); canvas.width = W; canvas.height = H;
    const gl = canvas.getContext("webgl2", { antialias: false, preserveDrawingBuffer: true });
    if (!gl) throw new Error("WebGL2 недоступен в этом браузере");
    if (!gl.getExtension("EXT_color_buffer_float")) throw new Error("нет float-текстур (EXT_color_buffer_float)");
    const quad = gl.createBuffer();
    gl.bindBuffer(gl.ARRAY_BUFFER, quad);
    gl.bufferData(gl.ARRAY_BUFFER, new Float32Array([-1,-1, 1,-1, -1,1, -1,1, 1,-1, 1,1]), gl.STATIC_DRAW);
    gl.enableVertexAttribArray(0); gl.vertexAttribPointer(0, 2, gl.FLOAT, false, 0, 0);
    const accumTex = gl.createTexture();
    gl.bindTexture(gl.TEXTURE_2D, accumTex);
    gl.texImage2D(gl.TEXTURE_2D, 0, gl.RGBA16F, W, H, 0, gl.RGBA, gl.HALF_FLOAT, null);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.NEAREST);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, gl.NEAREST);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_S, gl.CLAMP_TO_EDGE);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_T, gl.CLAMP_TO_EDGE);
    const fbo = gl.createFramebuffer();
    gl.bindFramebuffer(gl.FRAMEBUFFER, fbo);
    gl.framebufferTexture2D(gl.FRAMEBUFFER, gl.COLOR_ATTACHMENT0, gl.TEXTURE_2D, accumTex, 0);
    const progAccum = program(gl, VS, FS_ACCUM);
    const progNorm = program(gl, VS, FS_NORM);
    const uM = gl.getUniformLocation(progAccum, "uM");
    const uXmax = gl.getUniformLocation(progAccum, "uXmax");
    const uYmax = gl.getUniformLocation(progAccum, "uYmax");
    const uPower = gl.getUniformLocation(progAccum, "uPower");
    gl.viewport(0, 0, W, H);
    gl.clearColor(0, 0, 0, 0); gl.clear(gl.COLOR_BUFFER_BIT);
    gl.useProgram(progAccum);
    gl.enable(gl.BLEND); gl.blendFunc(gl.ONE, gl.ONE);
    gl.uniform1f(uPower, power);
    const frameTex = gl.createTexture();
    for (let i = 0; i < items.length; i++) {
      const it = items[i];
      const bmp = it.bitmap || await createImageBitmap(it.blob);
      const Wf = bmp.width, Hf = bmp.height;
      const hfov = (it.hfov || HFOV) * DEG;
      const vfov = 2 * Math.atan(Math.tan(hfov / 2) * Hf / Wf);
      gl.bindTexture(gl.TEXTURE_2D, frameTex);
      gl.texImage2D(gl.TEXTURE_2D, 0, gl.RGBA, gl.RGBA, gl.UNSIGNED_BYTE, bmp);
      gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.LINEAR);
      gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, gl.LINEAR);
      gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_S, gl.CLAMP_TO_EDGE);
      gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_T, gl.CLAMP_TO_EDGE);
      gl.uniformMatrix3fv(uM, false, colMajor(frameM(it.yaw, it.pitch, it.roll || 0)));
      gl.uniform1f(uXmax, Math.tan(hfov / 2));
      gl.uniform1f(uYmax, Math.tan(vfov / 2));
      gl.drawArrays(gl.TRIANGLES, 0, 6);
      if (bmp.close) bmp.close();
      if (onProgress) onProgress((i + 1) / items.length);
      if (i % 4 === 3) await new Promise((r) => requestAnimationFrame(r));
    }
    gl.disable(gl.BLEND);
    gl.bindFramebuffer(gl.FRAMEBUFFER, null);
    gl.viewport(0, 0, W, H);
    gl.useProgram(progNorm);
    gl.activeTexture(gl.TEXTURE0); gl.bindTexture(gl.TEXTURE_2D, accumTex);
    gl.uniform1i(gl.getUniformLocation(progNorm, "uAccum"), 0);
    gl.drawArrays(gl.TRIANGLES, 0, 6);
    return await new Promise((res) => canvas.toBlob((b) => res({ blob: b, width: W }), "image/jpeg", 0.92));
  }

  // ---------- ПОТОК: собрать → отправить ----------
  let lastBlob = null, lastUrl = null;
  function pickWidth() { return /Android|iPhone|iPad|iPod/i.test(navigator.userAgent) ? 2048 : 4096; }

  async function buildFromCapture() {
    stopCamera(); show("build");
    $("buildBtns").style.display = "none"; $("preview").style.display = "none"; $("buildErr").textContent = "";
    $("pfill").style.width = "0%"; $("build").querySelector("h2").textContent = "Собираю панораму…";
    const items = frames.map((f) => ({ blob: f.blob, yaw: f.yaw, pitch: f.pitch, roll: f.roll, hfov: HFOV }));
    $("buildStatus").textContent = `Кадров: ${items.length}. Склеиваю на GPU…`;
    try {
      const W = pickWidth();
      const out = await stitchEquirect(items, W, 6.0, (p) => { $("pfill").style.width = (p * 100).toFixed(0) + "%"; });
      lastBlob = out.blob;
      if (lastUrl) URL.revokeObjectURL(lastUrl);
      lastUrl = URL.createObjectURL(out.blob);
      $("preview").src = lastUrl; $("preview").style.display = "block";
      $("build").querySelector("h2").textContent = "Готово";
      $("buildStatus").textContent = `Панорама ${W}×${W / 2}. Проверь и добавь в тур.`;
      $("buildBtns").style.display = "flex";
    } catch (e) {
      $("build").querySelector("h2").textContent = "Не удалось собрать";
      $("buildErr").textContent = e.message || String(e);
      $("buildBtns").style.display = "flex";
    }
  }

  async function sendToTour() {
    if (!lastBlob) return;
    $("sendBtn").disabled = true; $("buildErr").textContent = "";
    $("buildStatus").textContent = "Отправляю в тур…";
    try {
      const fd = new FormData();
      fd.append("csrf", CSRF);
      fd.append("tour_id", String(TOUR_ID));
      fd.append("image", lastBlob, "scene.jpg");
      const r = await fetch("api/upload_scene.php", { method: "POST", body: fd });
      const j = await r.json();
      if (!j.ok) throw new Error(j.error || "Ошибка загрузки");
      window.location.href = j.redirect || ("tour_edit.php?id=" + TOUR_ID);
    } catch (e) {
      $("sendBtn").disabled = false;
      $("buildErr").textContent = e.message || String(e);
      $("buildStatus").textContent = "";
    }
  }

  $("goShoot").addEventListener("click", startCapture);
  $("calib").addEventListener("click", () => { yawOffset += view.yaw; });
  $("shoot").addEventListener("click", () => {
    const t = TARGETS.filter((t) => !t.done).sort((a, b) => angDist(a) - angDist(b))[0];
    capture(t || null);
  });
  $("build0").addEventListener("click", buildFromCapture);
  $("sendBtn").addEventListener("click", sendToTour);
  $("againBtn").addEventListener("click", startCapture);
  $("modeLine").textContent = (typeof DeviceOrientationEvent !== "undefined")
    ? "Телефон: камера + гироскоп. Компьютер: имитация мышью (для проверки)." : "";
})();
</script>
</body>
</html>
