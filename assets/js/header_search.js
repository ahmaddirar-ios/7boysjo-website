// Global header search - 7 Boys v2
(function(){
  var btn = document.getElementById('hdrSearchBtn');
  var input = document.getElementById('hdrSearchInput');
  var res = document.getElementById('hdrSearchResults');
  var wrap = document.getElementById('hdrSearch');
  if (!btn || !input || !res || !wrap) return;
  var products = null;
  var brands = null;
  var loaded = false;

  function loadData(){
    if (loaded) return Promise.resolve();
    return fetch('/api/products.php?type=products').then(function(r){ return r.json(); }).then(function(d){ products=d; loaded=true; }).catch(function(){ products=[]; loaded=true; })
      .then(function(){ return fetch('/api/products.php?type=brands').then(function(r){ return r.json(); }).then(function(d){ brands=d; }).catch(function(){ brands=[]; }); });
  }
  function slugify(p){
    var s=(p.name||'product').toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-|-$/g,'');
    return s + '-' + (p.id||'0');
  }
  function brandName(slug){
    if(!brands) return slug;
    for(var i=0;i<brands.length;i++) if(brands[i].slug===slug) return brands[i].name;
    return slug;
  }
  function render(list){
    if(!list.length){ res.innerHTML='<div class="hdr-no">No results</div>'; res.style.display='block'; return; }
    var html='';
    list.slice(0,8).forEach(function(p){
      var slug=slugify(p);
      var bn=brandName(p.brand);
      html+='<a class="hdr-res-item" href="/product.php?slug='+encodeURIComponent(slug)+'"><img src="/assets/img/'+(p.image||'prod-1.jpg')+'" alt=""><div><div class="rname">'+p.name+'</div><div class="rmeta">'+bn+' \u00b7 '+(p.origin||'')+'</div></div></a>';
    });
    if(list.length>8) html+='<div class="hdr-more">'+(list.length-8)+' more results</div>';
    res.innerHTML=html;
    res.style.display='block';
  }
  function doSearch(){
    var q=(input.value||'').toLowerCase().trim();
    if(!q){ res.style.display='none'; res.innerHTML=''; return; }
    function filter(){
      var out=products.filter(function(p){
        var hay=(p.name+' '+(p.brand||'')+' '+(p.desc||'')+' '+(p.origin||'')+' '+brandName(p.brand)).toLowerCase();
        return hay.indexOf(q)!==-1;
      });
      render(out);
    }
    if(!loaded){ loadData().then(filter); } else { filter(); }
  }

  /* toggle bound in header inline script */
  input.addEventListener('input', doSearch);
  input.addEventListener('keydown', function(e){
    if(e.key==='Escape'){ wrap.classList.remove('open'); input.value=''; res.style.display='none'; }
    if(e.key==='Enter' && res.children.length>0){
      var first=res.querySelector('.hdr-res-item');
      if(first) window.location.href=first.getAttribute('href');
    }
  });
  // Keep open when clicking inside
  wrap.addEventListener('click', function(e){ e.stopPropagation(); });
  document.addEventListener('click', function(){
    wrap.classList.remove('open');
  });
  loadData();
})();

/* cache-bust 20260909c */
