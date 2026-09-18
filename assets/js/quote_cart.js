/* 7 Boys Quote Cart v8 - B2B cases + units */
(function () {
  if (document.getElementById('qb-cart')) return;
  var KEY = 'qb_quote';
  function load() { try { return JSON.parse(localStorage.getItem(KEY)) || {}; } catch(e){ return {}; } }
  function save(c) { localStorage.setItem(KEY, JSON.stringify(c)); updateBtn(); render(); }
  function units(it){ var p = parseInt(it.pcs)||0; return p>0 ? (parseInt(it.qty)||0)*p : (parseInt(it.qty)||0); }
  function unitsLabel(it){ var p = parseInt(it.pcs)||0; return p>0 ? units(it)+' units' : ''; }
  function palletHint(it){
    var cp = parseInt(it.ctpal)||0, q = parseInt(it.qty)||0;
    if (cp>0 && q>=cp) { var pl = q/cp; return (Math.round(pl*10)/10)+' pallet' + (pl>=2?'s':''); }
    return '';
  }
  function updateBtn() {
    var c = load(); var n = Object.values(c).reduce(function(a,b){return a+(parseInt(b.qty)||0);},0);
    var b = document.getElementById('qb-cart-btn');
    if (b) { b.querySelector('.qb-count').textContent = n; b.style.display = n>0 ? 'flex' : 'none'; }
  }
  function add(slug, name, img, href, pcs, ctpal, qty) {
    if (!slug) return;
    var c = load();
    qty = parseInt(qty)||1;
    if (!c[slug]) c[slug] = {slug:slug, name:name, img:img||'', href:href, qty:qty, pcs:parseInt(pcs)||0, ctpal:parseInt(ctpal)||0};
    else { c[slug].qty = (parseInt(c[slug].qty)||0)+qty; if(pcs) c[slug].pcs=parseInt(pcs); if(ctpal) c[slug].ctpal=parseInt(ctpal); }
    save(c);
    var b = document.getElementById('qb-cart-btn');
    if (b) { b.classList.add('pulse'); setTimeout(function(){b.classList.remove('pulse');},300); }
  }
  window.__qbAdd = function(slug, name, img, href, pcs, ctpal, qty){ add(slug,name,img,href,pcs,ctpal,qty); };
  window.__qbAddFromBtn = function(btn){
    var q = 1;
    var wrap = btn.closest ? btn.closest('.qb-step') : null;
    var inp = wrap ? wrap.querySelector('.qb-case-in') : document.querySelector('.qb-case-in');
    if (inp) q = parseInt(inp.value)||1;
    add(btn.getAttribute('data-slug'), btn.getAttribute('data-name'), btn.getAttribute('data-img'), btn.getAttribute('data-href'), btn.getAttribute('data-pcs'), btn.getAttribute('data-ctpal'), q);
  };
  window.__qbGetCart = load;
  window.__qbClear = function(){ localStorage.removeItem(KEY); updateBtn(); render(); };

  function packOf(a){
    return { pcs: a.getAttribute('data-pcs')||'0', ctpal: a.getAttribute('data-ctpal')||'0' };
  }
  function injectCardButtons() {
    document.querySelectorAll('.prod-card').forEach(function(a){
      if (a.querySelector('.qb-add-card')) return;
      var slug = a.getAttribute('href') ? a.getAttribute('href').split('slug=')[1] : '';
      if(!slug) return;
      var nm = a.querySelector('h3') ? a.querySelector('h3').textContent.trim() : '';
      var imgEl = a.querySelector('img'); var img = imgEl ? imgEl.getAttribute('src').split('/').pop() : 'prod-1.jpg';
      var pk = packOf(a);
      var plus = document.createElement('button');
      plus.className = 'qb-add-card'; plus.textContent = '+ Quote';
      plus.title = 'Add a case to Quote';
      plus.onclick = function(e){ e.preventDefault(); e.stopPropagation(); add(decodeURIComponent(slug), nm, img, a.href, pk.pcs, pk.ctpal, 1); };
      a.style.position = a.style.position || 'relative';
      a.appendChild(plus);
    });
  }
  function buildCart() {
    var S = document.createElement('style');
    S.textContent = `
    #qb-cart-btn{position:fixed;left:20px;bottom:226px;z-index:300;width:56px;height:56px;border-radius:50%;background:#2e6b3e;color:#fff;border:none;cursor:pointer;box-shadow:0 6px 20px rgba(46,107,62,.4);display:none;align-items:center;justify-content:center;font-size:22px;transition:transform .2s}
    #qb-cart-btn.pulse{transform:scale(1.15)}
    #qb-cart-btn .qb-count{position:absolute;top:-4px;right:-4px;background:#e63946;color:#fff;font-size:11px;min-width:20px;height:20px;border-radius:10px;display:flex;align-items:center;justify-content:center;padding:0 4px}
    #qb-panel{position:fixed;left:0;top:0;height:100vh;width:380px;max-width:92vw;background:#fff;color:#222;z-index:400;box-shadow:4px 0 30px rgba(0,0,0,.2);transform:translateX(-100%);transition:transform .3s;display:flex;flex-direction:column;font-family:-apple-system,sans-serif}
    #qb-panel.open{transform:none}
    #qb-panel header{background:#2e6b3e;color:#fff;padding:16px;font-weight:600;display:flex;justify-content:space-between;align-items:center}
    #qb-panel .qb-list{flex:1;overflow-y:auto;padding:12px}
    .qb-item{display:flex;gap:10px;align-items:center;padding:10px 0;border-bottom:1px solid #eee}
    .qb-item img{width:44px;height:44px;object-fit:cover;border-radius:8px;border:1px solid #eee}
    .qb-item .nm{flex:1;font-size:13px;font-weight:600}
    .qb-item .nm small{display:block;font-weight:400;color:#2e6b3e;font-size:11px}
    .qb-item .nm .plt{color:#b7791f}
    .qb-item input{width:56px;padding:6px;border:1.5px solid #ddd;border-radius:8px;text-align:center}
    .qb-item .rm{background:none;border:none;color:#e63946;cursor:pointer;font-size:20px}
    .qb-unit{font-size:11px;color:#666;white-space:nowrap}
    #qb-panel footer{padding:14px;border-top:1px solid #eee;display:flex;flex-direction:column;gap:8px}
    #qb-panel footer .qb-totals{text-align:center;font-size:13px;font-weight:700;color:#2e6b3e}
    #qb-panel footer .qb-go{width:100%;padding:12px;border:none;border-radius:10px;background:#2e6b3e;color:#fff;font-weight:700;cursor:pointer;text-align:center;text-decoration:none}
    #qb-panel footer .qb-wa{width:100%;padding:10px;border:1.5px solid #25D366;border-radius:10px;background:#fff;color:#25D366;font-weight:600;cursor:pointer}
    .qb-add-card{position:absolute;bottom:10px;right:10px;background:#2e6b3e;color:#fff;border:none;border-radius:999px;padding:6px 10px;font-size:11px;font-weight:700;cursor:pointer;box-shadow:0 2px 8px rgba(0,0,0,.15)}
    .qb-add-card:hover{background:#1e4a2a}
    html[data-theme="dark"] #qb-panel{background:#1c1a14;color:#f0ede6}
    `;
    document.head.appendChild(S);
    var btn = document.createElement('button'); btn.id='qb-cart-btn'; btn.innerHTML='🛒<span class="qb-count">0</span>'; btn.title='Quote basket';
    btn.onclick = function(){ document.getElementById('qb-panel').classList.toggle('open'); render(); };
    document.body.appendChild(btn);
    var panel = document.createElement('div'); panel.id = 'qb-panel';
    panel.innerHTML = '<header>🛒 Quote Basket <button class="qb-close" style="background:none;border:none;color:#fff;font-size:22px;cursor:pointer">×</button></header><div class="qb-list" id="qbList"></div><footer><div class="qb-totals" id="qbTotals"></div><a class="qb-go" href="/quote.php">View Quote & Request PDF</a><button class="qb-wa" id="qbSendWa">Send via WhatsApp</button></footer>';
    document.body.appendChild(panel);
    panel.querySelector('.qb-close').onclick = function(){ panel.classList.remove('open'); };
    document.getElementById('qbSendWa').onclick = function(){
      var c = load(); var items = Object.values(c);
      if (!items.length) { alert('Your quote is empty'); return; }
      var msg = 'Hello 7 Boys! I would like a quote for:%0A';
      items.forEach(function(it){
        var u = unitsLabel(it);
        msg += '- ' + it.qty + ' case' + (it.qty>1?'s':'') + (u ? ' (' + u + ')' : '') + ' ' + it.name + '%0A';
      });
      var waEl = document.querySelector('.wa-float'); var wa = waEl ? waEl.getAttribute('href').split('/').pop().replace(/[^0-9]/g,'') : '962795109022';
      window.open('https://wa.me/' + wa + '?text=' + msg, '_blank');
    };
  }
  function render() {
    var c = load(); var list = document.getElementById('qbList'); if (!list) return;
    list.innerHTML = '';
    var vals = Object.values(c);
    var tot = document.getElementById('qbTotals');
    if (!vals.length) { list.innerHTML = '<div style="text-align:center;padding:30px;color:#888">No items yet<br><small>Add products to request a price quote</small></div>'; if(tot) tot.textContent=''; return; }
    var tc = 0, tu = 0, hasU = false;
    vals.forEach(function(it){
      tc += parseInt(it.qty)||0;
      var p = parseInt(it.pcs)||0;
      if (p>0) { tu += units(it); hasU = true; }
      var row = document.createElement('div'); row.className = 'qb-item';
      var sub = '';
      var ul = unitsLabel(it);
      if (ul) sub += '<small>' + ul + '</small>';
      var ph = palletHint(it);
      if (ph) sub += '<small class="plt">' + ph + '</small>';
      row.innerHTML = '<img src="/assets/img/'+(it.img||'prod-1.jpg')+'" alt=""><span class="nm">'+it.name+sub+'</span><span class="qb-unit">cases</span><input type="number" min="1" value="'+it.qty+'" data-slug="'+it.slug+'"><button class="rm" data-slug="'+it.slug+'">×</button>';
      list.appendChild(row);
    });
    if (tot) tot.textContent = tc + ' cases' + (hasU ? ' = ' + tu + ' units' : '');
    list.querySelectorAll('input').forEach(function(inp){ inp.onchange = function(){ var c=load(); var s=inp.dataset.slug; if(c[s]){c[s].qty=parseInt(inp.value)||1; save(c);} }; });
    list.querySelectorAll('.rm').forEach(function(b){ b.onclick = function(){ var c=load(); delete c[b.dataset.slug]; save(c); render(); }; });
  }
  buildCart(); injectCardButtons(); updateBtn();
})();

/* cache-bust 20260909c */
