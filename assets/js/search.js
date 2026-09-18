// 7 Boys - Instant search & filter for category pages
(function(){
  var grid = document.getElementById('prodGrid');
  if (!grid) return;
  var q = document.getElementById('prodSearch');
  var bf = document.getElementById('brandFilter');
  var of = document.getElementById('originFilter');
  var chips = document.querySelectorAll('.fchip');
  var countEl = document.getElementById('resultCount');
  var noEl = document.getElementById('noResults');
  var clear = document.getElementById('clearSearch');
  var activeSub = '';

  function doFilter(){
    var term = (q.value||'').toLowerCase().trim();
    var brand = bf.value;
    var origin = of.value;
    var cards = grid.querySelectorAll('.prod-card');
    var vis = 0;
    cards.forEach(function(c){
      var name = (c.getAttribute('data-name')||'').toLowerCase();
      var cb = c.getAttribute('data-brand')||'';
      var co = c.getAttribute('data-origin')||'';
      var cs = c.getAttribute('data-sub')||'';
      var ok = true;
      if (term && name.indexOf(term)===-1) ok = false;
      if (brand && cb !== brand) ok = false;
      if (origin && co !== origin) ok = false;
      if (activeSub && cs !== activeSub) ok = false;
      c.style.display = ok ? '' : 'none';
      if (ok) vis++;
    });
    if (countEl) countEl.textContent = vis + ' product' + (vis!==1?'s':'');
    if (noEl) noEl.style.display = vis===0 ? '' : 'none';
    grid.style.display = vis===0 ? 'none' : '';
    if (clear) clear.style.display = term ? '' : 'none';
  }
  window.__doFilter = doFilter;

  if (q) {
    q.addEventListener('input', doFilter);
    q.addEventListener('keydown', function(e){ if(e.key==='Escape'){ q.value=''; doFilter(); }});
  }
  if (clear) clear.addEventListener('click', function(){ q.value=''; q.focus(); doFilter(); });
  if (bf) bf.addEventListener('change', doFilter);
  if (of) of.addEventListener('change', doFilter);
  chips.forEach(function(ch){
    ch.addEventListener('click', function(){
      chips.forEach(function(c){ c.classList.remove('active'); });
      ch.classList.add('active');
      activeSub = ch.getAttribute('data-sub')||'';
      doFilter();
    });
  });
})();
