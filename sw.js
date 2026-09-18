const CACHE='7boys-1789396778';
self.addEventListener('install', function(e){
  self.skipWaiting();
  e.waitUntil(caches.open(CACHE).then(function(c){ return c.addAll(['/assets/css/hdr3.css','/assets/img/logo.png']); }).catch(function(){}));
});
self.addEventListener('activate', function(e){
  e.waitUntil(caches.keys().then(function(ks){ return Promise.all(ks.filter(function(k){ return k.indexOf('7boys-')===0 && k!==CACHE; }).map(function(k){ return caches.delete(k); })); }).then(function(){ return self.clients.claim(); }));
});
self.addEventListener('fetch', function(e){
  var u;
  try { u = new URL(e.request.url); } catch(err){ return; }
  if (e.request.method !== 'GET' || u.origin !== location.origin) return;
  if (e.request.mode === 'navigate') {
    e.respondWith(fetch(e.request).then(function(r){
      var c = r.clone();
      caches.open(CACHE).then(function(cc){ cc.put(e.request, c); });
      return r;
    }).catch(function(){ return caches.match(e.request).then(function(r){ return r || caches.match('/'); }); }));
    return;
  }
  if (u.pathname.indexOf('/assets/') === 0) {
    e.respondWith(caches.match(e.request).then(function(r){
      var f = fetch(e.request).then(function(n){
        if (n && n.ok) { var c = n.clone(); caches.open(CACHE).then(function(cc){ cc.put(e.request, c); }); }
        return n;
      }).catch(function(){ return r; });
      return r || f;
    }));
  }
});
