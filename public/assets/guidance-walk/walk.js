/* Guidance Office street-view walkthrough.
   GuidanceWalk.init(rootElement, { imgs:[{src,w,h}], map:'url', route:[[x,y]..], stops:[[x,y]..],
                                    mapW, mapH, captions:{index:'text'} })
   Requires THREE (r128). Photos 1..N-1 are panoramas; the last is a normal photo (arrival). */
(function(global){
  var ARC = 230;               // assumed horizontal degrees covered by each panorama photo
  var NS = 'http://www.w3.org/2000/svg';

  var ARROW_UP = '<svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="5 15 12 7 19 15"/><polyline points="5 21 12 13 19 21" opacity=".55"/></svg>';
  var ARROW_DN = '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="5 9 12 17 19 9"/></svg>';

  function svgEl(n, a) { var e = document.createElementNS(NS, n); for (var k in a) e.setAttribute(k, a[k]); return e; }

  function init(root, cfg) {
    var N = cfg.imgs.length, cur = 0, captions = cfg.captions || {};
    root.classList.add('gw');
    root.innerHTML =
      '<div class="gw-view"></div>' +
      '<div class="gw-arrive"><div class="banner">You\'ve arrived \u2014 Guidance Office</div><img alt="Guidance Office entrance"></div>' +
      '<div class="gw-fade"></div>' +
      '<div class="gw-top"><div class="gw-pill">Guidance Office <b>Walkthrough</b></div><div class="gw-pill gw-step"></div></div>' +
      '<div class="gw-look"><span class="a l">\u25c0</span><span class="t">Drag left / right to look around</span><span class="a r">\u25b6</span></div>' +
      '<button class="gw-side l" aria-label="Look left">\u2039</button><button class="gw-side r" aria-label="Look right">\u203a</button>' +
      '<div class="gw-nav"><button class="gw-arrow fwd" aria-label="Forward" title="Forward">' + ARROW_UP + '</button><div class="gw-lbl">Forward</div>' +
      '<button class="gw-arrow back" aria-label="Back" title="Back">' + ARROW_DN + '</button><div class="gw-lbl">Back</div></div>' +
      '<div class="gw-mini" title="Tap to enlarge"><div class="stage"><img alt="Campus map"><svg></svg></div><div class="tag">MAP</div></div>';

    function $(s) { return root.querySelector(s); }
    var host = $('.gw-view'), fade = $('.gw-fade'), arrive = $('.gw-arrive'), look = $('.gw-look');
    var fwd = $('.gw-arrow.fwd'), back = $('.gw-arrow.back'), sideL = $('.gw-side.l'), sideR = $('.gw-side.r');

    // ---------- mini map ----------
    var mini = $('.gw-mini'), svg = $('.gw-mini svg');
    $('.gw-mini img').src = cfg.map;
    svg.setAttribute('viewBox', '0 0 ' + cfg.mapW + ' ' + cfg.mapH);
    var pts = cfg.route.map(function (p) { return p[0] + ',' + p[1]; }).join(' ');
    svg.appendChild(svgEl('polyline', { points: pts, fill: 'none', stroke: '#000', 'stroke-width': 6, 'stroke-linejoin': 'round', 'stroke-linecap': 'round', opacity: .55 }));
    svg.appendChild(svgEl('polyline', { points: pts, fill: 'none', stroke: '#ffd400', 'stroke-width': 3.6, 'stroke-linejoin': 'round', 'stroke-linecap': 'round' }));
    var dots = [], labels = [];
    cfg.stops.forEach(function (s, i) {
      var g = svgEl('g', { style: 'cursor:pointer' });
      var c = svgEl('circle', { cx: s[0], cy: s[1], r: 7, fill: '#1b1b1b', stroke: '#fff', 'stroke-width': 1.5 });
      var t = svgEl('text', { x: s[0], y: s[1] + 3, 'text-anchor': 'middle', 'font-size': 8, 'font-weight': 700, fill: '#fff', 'font-family': 'sans-serif' });
      t.textContent = i + 1; g.appendChild(c); g.appendChild(t);
      g.addEventListener('click', function (ev) { if (mini.classList.contains('big')) { ev.stopPropagation(); go(i); } });
      svg.appendChild(g); dots.push(c); labels.push(t);
    });
    mini.addEventListener('click', function () { mini.classList.toggle('big'); });

    // ---------- 3D viewer ----------
    var renderer = new THREE.WebGLRenderer({ antialias: true });
    renderer.setPixelRatio(Math.min(devicePixelRatio, 3)); host.appendChild(renderer.domElement);
    var scene = new THREE.Scene(); scene.background = new THREE.Color(0x101214);
    var camera = new THREE.PerspectiveCamera(60, 1, 0.1, 100); camera.rotation.order = 'YXZ';
    var R = 10, mesh = null, yaw = 0, pitch = 0, yawT = null, vSpan = 60, texCache = {};
    var loader = new THREE.TextureLoader();

    function getTex(i, cb) {
      if (texCache[i]) { cb(texCache[i]); return; }
      loader.load(cfg.imgs[i].src, function (t) { t.minFilter = THREE.LinearFilter; t.generateMipmaps = false; t.anisotropy = renderer.capabilities.getMaxAnisotropy(); texCache[i] = t; cb(t); });
    }
    function buildPano(i, tex) {
      if (mesh) { scene.remove(mesh); mesh.geometry.dispose(); mesh.material.dispose(); }
      var im = cfg.imgs[i];
      vSpan = ARC * im.h / im.w;
      var arc = ARC * Math.PI / 180, H = 2 * R * Math.tan((vSpan * Math.PI / 180) / 2);
      mesh = new THREE.Mesh(new THREE.CylinderGeometry(R, R, H, 96, 1, true, Math.PI - arc / 2, arc),
        new THREE.MeshBasicMaterial({ map: tex, side: THREE.BackSide }));
      mesh.scale.x = -1; scene.add(mesh);
      yaw = 0; pitch = 0; yawT = null; resize();
    }
    function limits() {
      var vf = camera.fov * Math.PI / 180, hf = 2 * Math.atan(Math.tan(vf / 2) * camera.aspect);
      return { y: Math.max(0, (ARC * Math.PI / 180 - hf) / 2), p: Math.max(0, (vSpan * Math.PI / 180 - vf) / 2) };
    }
    function applyView() {
      var L = limits(); yaw = Math.max(-L.y, Math.min(L.y, yaw)); pitch = Math.max(-L.p, Math.min(L.p, pitch));
      camera.rotation.y = yaw; camera.rotation.x = pitch;
    }
    function resize() {
      var w = host.clientWidth || 1, h = host.clientHeight || 1; renderer.setSize(w, h);
      camera.aspect = w / h;
      var base = camera.aspect < 1 ? 78 : 62; camera.fov = Math.min(base, Math.max(30, vSpan - 1));
      camera.updateProjectionMatrix(); applyView();
    }
    if (global.ResizeObserver) new ResizeObserver(resize).observe(host); else addEventListener('resize', resize);

    var drag = null;
    host.addEventListener('pointerdown', function (e) { drag = { x: e.clientX, y: e.clientY }; yawT = null; host.setPointerCapture(e.pointerId); look.classList.add('compact'); });
    host.addEventListener('pointermove', function (e) {
      if (!drag) return;
      var k = camera.fov * Math.PI / 180 / host.clientHeight;
      yaw += (e.clientX - drag.x) * k; pitch += (e.clientY - drag.y) * k; drag = { x: e.clientX, y: e.clientY }; applyView();
    });
    host.addEventListener('pointerup', function () { drag = null; });
    host.addEventListener('pointercancel', function () { drag = null; });
    host.addEventListener('wheel', function (e) { camera.fov = Math.max(30, Math.min(75, camera.fov + e.deltaY * .04)); camera.updateProjectionMatrix(); applyView(); e.preventDefault(); }, { passive: false });

    function turn(dir) { var L = limits(); yawT = Math.max(-L.y, Math.min(L.y, yaw + dir * 0.5)); look.classList.add('compact'); }
    sideL.onclick = function () { turn(+1); };   // positive yaw = view turns left
    sideR.onclick = function () { turn(-1); };

    // ---------- navigation ----------
    function updateUI() {
      $('.gw-step').innerHTML = 'Step <b>' + (cur + 1) + '</b> of ' + N + (captions[cur] ? '<span class="gw-cap"> \u00b7 ' + captions[cur] + '</span>' : '');
      back.disabled = cur === 0; fwd.disabled = cur === N - 1;
      dots.forEach(function (c, i) {
        var on = i === cur;
        c.setAttribute('r', on ? 10 : 7); c.setAttribute('fill', on ? '#f2b705' : (i < cur ? '#555' : '#1b1b1b')); c.setAttribute('stroke', on ? '#000' : '#fff');
        labels[i].setAttribute('fill', on ? '#000' : '#fff'); labels[i].setAttribute('font-size', on ? 10 : 8);
      });
      var panoStep = cur < N - 1;
      sideL.style.display = sideR.style.display = look.style.display = panoStep ? '' : 'none';
    }
    function go(i) {
      if (i < 0 || i >= N) return;
      cur = i; updateUI(); fade.style.opacity = 1;
      setTimeout(function () {
        if (i === N - 1) {
          arrive.querySelector('img').src = cfg.imgs[i].src; arrive.style.display = 'flex'; fade.style.opacity = 0; return;
        }
        arrive.style.display = 'none';
        getTex(i, function (t) { buildPano(i, t); fade.style.opacity = 0; if (i + 1 < N - 1) getTex(i + 1, function () { }); });
      }, 180);
    }
    fwd.onclick = function () { go(cur + 1); };
    back.onclick = function () { go(cur - 1); };
    addEventListener('keydown', function (e) {
      if (e.key === 'ArrowUp') go(cur + 1);
      if (e.key === 'ArrowDown') go(cur - 1);
    });

    (function loop() {
      requestAnimationFrame(loop);
      if (yawT !== null) { yaw += (yawT - yaw) * .14; if (Math.abs(yawT - yaw) < .002) { yaw = yawT; yawT = null; } applyView(); }
      var L = limits(); sideL.style.opacity = yaw < L.y - .02 ? 1 : .15; sideR.style.opacity = yaw > -L.y + .02 ? 1 : .15;
      renderer.render(scene, camera);
    })();
    go(0);
    return { go: go };
  }
  global.GuidanceWalk = { init: init };
})(window);
