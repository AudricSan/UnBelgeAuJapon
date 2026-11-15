/* Compteur de visites interne : pas de cookie, aucune donnée personnelle conservée. */
(function () {
  if (navigator.doNotTrack === '1' || localStorage.getItem('mvaj-ignore') === '1') return;
  var send = function () {
    var data = JSON.stringify({
      p: location.pathname,
      r: document.referrer,
      l: document.documentElement.lang
    });
    if (!(navigator.sendBeacon && navigator.sendBeacon('/api/hit.php', data))) {
      fetch('/api/hit.php', { method: 'POST', body: data, keepalive: true }).catch(function () {});
    }
  };
  if (document.visibilityState === 'prerender') {
    document.addEventListener('visibilitychange', send, { once: true });
  } else {
    send();
  }
})();
