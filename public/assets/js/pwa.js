/* Installable-app support: registers the service worker and powers the "Install the app" button.
   Needs window.BASE_URL (set in partials/header.php). Install blocks are marked up like:
     <div id="installApp" hidden> <button id="installBtn">…</button> <div id="installHelp" hidden></div> </div> */
(function () {
  var base = window.BASE_URL || '';
  var secure = window.isSecureContext;                       // https:// or localhost
  var standalone = (window.matchMedia && matchMedia('(display-mode: standalone)').matches) || navigator.standalone === true;

  if ('serviceWorker' in navigator && secure) {
    window.addEventListener('load', function () {
      navigator.serviceWorker.register(base + '/sw.js', { scope: base + '/' }).catch(function () { /* app still works without it */ });
    });
  }

  var deferred = null;
  window.addEventListener('beforeinstallprompt', function (e) { e.preventDefault(); deferred = e; });
  window.addEventListener('appinstalled', function () {
    deferred = null;
    var help = document.getElementById('installHelp'), btn = document.getElementById('installBtn');
    if (btn) btn.hidden = true;
    if (help) { help.hidden = false; help.textContent = 'Installed! Open it from your home screen or app list.'; }
  });

  function helpText() {
    var ua = navigator.userAgent || '';
    var isIOS = /iPad|iPhone|iPod/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    if (!secure) return 'To install, open this site using its secure address (the one starting with https://).';
    if (isIOS) {
      return /CriOS|FxiOS|EdgiOS|OPiOS/.test(ua)
        ? 'On iPhone/iPad, open this page in Safari, then tap Share and choose "Add to Home Screen".'
        : 'Tap the Share button (square with an arrow) at the bottom of Safari, then choose "Add to Home Screen".';
    }
    if (/Firefox/.test(ua) && !/Android/.test(ua)) return 'Firefox on computers can\u2019t install apps. Open this page in Chrome or Edge to install it.';
    if (/Android/.test(ua)) return 'Open your browser menu (\u22EE) and tap "Install app" or "Add to Home screen".';
    return 'Click the install icon at the right end of the address bar, or open the browser menu and choose "Install\u2026" (Chrome / Edge).';
  }

  document.addEventListener('DOMContentLoaded', function () {
    var block = document.getElementById('installApp');
    if (!block) return;
    if (standalone) return;                                   // already running as the installed app
    block.hidden = false;
    var btn = document.getElementById('installBtn'), help = document.getElementById('installHelp');
    btn.addEventListener('click', function () {
      if (deferred) {
        var p = deferred; deferred = null;
        p.prompt();
        (p.userChoice || Promise.resolve({})).then(function (c) {
          if (c && c.outcome === 'accepted') { block.hidden = true; }
          else { help.hidden = false; help.textContent = 'No problem \u2014 you can install it any time from this page.'; }
        });
      } else {
        help.hidden = false; help.textContent = helpText();
      }
    });
  });
})();
