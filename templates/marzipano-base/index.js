'use strict';
// Marzipano single-equirect viewer.
// Phase 1: меню-навигация. Phase 3: стрелки-переходы (linkHotspots),
// направленный yaw и префетч связанных сцен. Углы — в радианах.
// Читает window.APP_DATA (data.js, генерится src/generator/build-data.js).
(function () {
  var Marzipano = window.Marzipano;
  var data = window.APP_DATA;

  var panoEl = document.querySelector('#pano');
  var viewer = new Marzipano.Viewer(panoEl, {
    controls: { mouseViewMode: (data.settings && data.settings.mouseViewMode) || 'drag' }
  });

  var MAX_FOV = 100 * Math.PI / 180;

  var scenes = data.scenes.map(function (sd) {
    var source, geometry, limitRes;
    if (sd.type === 'cube' || sd.levels) {
      // Phase 2: multires cube-тайлы + preview (мгновенная сцена, §8.2).
      source = Marzipano.ImageUrlSource.fromString(
        'tiles/' + sd.id + '/{z}/{f}/{y}/{x}.jpg',
        { cubeMapPreviewUrl: 'tiles/' + sd.id + '/preview.jpg' }
      );
      geometry = new Marzipano.CubeGeometry(sd.levels);
      limitRes = sd.faceSize || 2048;
    } else {
      source = Marzipano.ImageUrlSource.fromString(sd.equirectUrl);
      geometry = new Marzipano.EquirectGeometry([{ width: sd.equirectWidth || 4000 }]);
      limitRes = sd.equirectWidth || 4000;
    }
    var limiter = Marzipano.RectilinearView.limit.traditional(limitRes, MAX_FOV);
    var view = new Marzipano.RectilinearView(sd.initialViewParameters, limiter);
    var scene = viewer.createScene({ source: source, geometry: geometry, view: view });
    return { data: sd, scene: scene, view: view };
  });

  var byId = {};
  scenes.forEach(function (s) { byId[s.data.id] = s; });

  var listEl = document.querySelector('#sceneList');
  var titleEl = document.querySelector('#titleText');
  var toggleEl = document.querySelector('#menuToggle');

  // --- префетч: греем кэш браузера для связанных панорам ---
  var prefetched = {};
  function prefetch(s) {
    (s.data.linkHotspots || []).forEach(function (h) {
      var t = byId[h.target];
      if (!t || prefetched[h.target]) return;
      prefetched[h.target] = true;
      var img = new Image();
      img.src = t.data.equirectUrl;
    });
  }

  function switchTo(s) {
    s.view.setParameters(s.data.initialViewParameters);
    s.scene.switchTo();
    titleEl.textContent = s.data.name;
    Array.prototype.forEach.call(listEl.children, function (btn) {
      btn.classList.toggle('active', btn.dataset.id === s.data.id);
    });
    prefetch(s);
  }

  // --- стрелки-переходы ---
  function makeLinkEl(h) {
    var target = byId[h.target];
    var wrap = document.createElement('div');
    wrap.className = 'link-hotspot';
    wrap.title = 'В: ' + (target ? target.data.name : h.target);
    wrap.innerHTML = '<span class="link-arrow">▲</span>'
      + '<span class="link-label">' + (target ? target.data.name : h.target) + '</span>';
    wrap.addEventListener('click', function () {
      if (target) switchTo(target);
    });
    return wrap;
  }

  scenes.forEach(function (s) {
    var container = s.scene.hotspotContainer();
    (s.data.linkHotspots || []).forEach(function (h) {
      container.createHotspot(makeLinkEl(h), { yaw: h.yaw, pitch: h.pitch });
    });
  });

  // --- меню сцен ---
  scenes.forEach(function (s) {
    var btn = document.createElement('button');
    btn.className = 'scene-item';
    btn.textContent = s.data.name;
    btn.dataset.id = s.data.id;
    btn.addEventListener('click', function () {
      switchTo(s);
      listEl.classList.add('hidden');
    });
    listEl.appendChild(btn);
  });

  toggleEl.addEventListener('click', function () {
    listEl.classList.toggle('hidden');
  });

  if (data.settings && data.settings.autorotateEnabled) {
    var autorotate = Marzipano.autorotate({ yawSpeed: 0.03, targetPitch: 0, targetFov: Math.PI / 2 });
    viewer.setIdleMovement(3000, autorotate);
  }

  if (scenes.length) switchTo(scenes[0]);
})();
