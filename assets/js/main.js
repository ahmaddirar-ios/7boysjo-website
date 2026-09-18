(function(){function apply(t){document.documentElement.setAttribute("data-theme",t);try{localStorage.setItem("theme",t)}catch(e){}var b=document.getElementById("themeToggle");if(b){var s=b.querySelector("span");if(s)s.textContent=t==="dark"?"LIGHT":"DARK";}}var __ttLast=0;window.__toggleTheme=function(){if(Date.now()-__ttLast<450)return;__ttLast=Date.now();var cur=document.documentElement.getAttribute("data-theme")||localStorage.getItem("theme")||"light";var nxt=cur==="dark"?"light":"dark";apply(nxt);};try{var s=localStorage.getItem("theme");if(!s&&window.matchMedia&&window.matchMedia("(prefers-color-scheme: dark)").matches)s="dark";if(s==="dark"||s==="light")apply(s);else apply("light");}catch(e){apply("light");}})();
// 7 Boys — premium interactions
document.addEventListener('DOMContentLoaded', function () {
  // Scroll reveal with stagger
  var els = document.querySelectorAll('.reveal, .tl-item');
  var groups = {};
  if ('IntersectionObserver' in window) {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) {
        if (e.isIntersecting) {
          var el = e.target;
          // stagger siblings inside same parent grid
          var parent = el.parentElement;
          if (parent) {
            if (!(parent.__gid in groups)) groups[parent.__gid = groups.n = (groups.n||0)+1] = 0;
            var idx = Array.prototype.indexOf.call(parent.children, el);
            el.style.setProperty('--d', Math.min(idx * 70, 420) + 'ms');
          }
          el.classList.add('in');
          el.classList.add('show');
          io.unobserve(el);
        }
      });
    }, { threshold: 0.12 });
    els.forEach(function (el) { io.observe(el); });
  } else {
    els.forEach(function (el) { el.classList.add('in'); el.classList.add('show'); });
  }

  // Animated counters
  function animateCount(el) {
    var target = parseInt(el.dataset.count, 10);
    var suffix = el.dataset.suffix || '';
    if (isNaN(target)) return;
    var dur = 1600, start = null;
    function step(ts) {
      if (!start) start = ts;
      var p = Math.min((ts - start) / dur, 1);
      var eased = 1 - Math.pow(1 - p, 3);
      el.textContent = Math.round(target * eased).toLocaleString('en-US') + suffix;
      if (p < 1) requestAnimationFrame(step);
    }
    requestAnimationFrame(step);
  }
  var counters = document.querySelectorAll('.num[data-count]');
  if ('IntersectionObserver' in window && counters.length) {
    var cio = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) {
        if (e.isIntersecting) { animateCount(e.target); cio.unobserve(e.target); }
      });
    }, { threshold: 0.5 });
    counters.forEach(function (c) { cio.observe(c); });
  }

  // Smart navbar + back-to-top
  var header = document.querySelector('header');
  var toTop = document.getElementById('toTop');
  var ticking=false; function onScroll(){ if(ticking) return; ticking=true; requestAnimationFrame(function(){ var y=window.scrollY; header.classList.toggle('scrolled', y>40); if (toTop) toTop.classList.toggle('show', y > 600); ticking=false; }); }
  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll();
  if (toTop) toTop.addEventListener('click', function () {
    window.scrollTo({ top: 0, behavior: 'smooth' });
  });

  // Active menu link highlighting
  var sections = ['about','journey','brands','categories','products','why','contact']
    .map(function(id){ return document.getElementById(id); }).filter(Boolean);
  if ('IntersectionObserver' in window && sections.length) {
    var sio = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) {
        if (e.isIntersecting) {
          document.querySelectorAll('#menu a').forEach(function (a) {
            a.classList.toggle('active', a.getAttribute('href') === '#' + e.target.id);
          });
        }
      });
    }, { rootMargin: '-45% 0px -50% 0px' });
    sections.forEach(function (s) { sio.observe(s); });
  }

  // Mobile menu close on click
  document.querySelectorAll('#menu a').forEach(function (a) {
    a.addEventListener('click', function () { document.getElementById('menu').classList.remove('open'); });
  });
});

// 3D tilt effect on product & brand cards
document.addEventListener('DOMContentLoaded', function () {
  if (window.matchMedia('(hover:hover)').matches) {
    document.querySelectorAll('.prod-card, .logo-tile').forEach(function (card) {
      card.addEventListener('mousemove', function (e) {
        var r = card.getBoundingClientRect();
        var x = (e.clientX - r.left) / r.width - 0.5;
        var y = (e.clientY - r.top) / r.height - 0.5;
        card.style.transform = 'perspective(900px) rotateY(' + (x * 8).toFixed(2) + 'deg) rotateX(' + (-y * 8).toFixed(2) + 'deg) translateY(-6px)';
      });
      card.addEventListener('mouseleave', function () {
        card.style.transform = '';
      });
    });
  }

  // Sub-category filter chips
  var filterBars = document.querySelectorAll('.sub-filters');
  filterBars.forEach(function (bar) {
    var chips = bar.querySelectorAll('.sub-chip');
    var grid = bar.closest('main').querySelector('.cat-products');
    if (!grid) return;
    chips.forEach(function (chip) {
      chip.addEventListener('click', function (e) {
        e.preventDefault();
        chips.forEach(function (c) { c.classList.remove('active'); });
        chip.classList.add('active');
        var sub = chip.getAttribute('data-sub');
        grid.querySelectorAll('.prod-card').forEach(function (card) {
          var show = (sub === 'all' || card.getAttribute('data-sub') === sub);
          card.classList.toggle('hidden', !show);
        });
      });
    });
  });
});

// Scroll reveal + nav shadow + animated counters
document.addEventListener('DOMContentLoaded', function () {
  // nav shadow on scroll
  var header = document.querySelector('.site-header');
  function onScroll() {
    if (window.scrollY > 20) header.classList.add('scrolled');
    else header.classList.remove('scrolled');
  }
  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll();

  // reveal sections on scroll
  var io = new IntersectionObserver(function (entries) {
    entries.forEach(function (en) {
      if (en.isIntersecting) {
        en.target.classList.add('in');
        // animate counter if it has data-count
        en.target.querySelectorAll?.('[data-count]').forEach(function (el) {
          animateCount(el);
        });
        io.unobserve(en.target);
      }
    });
  }, { threshold: 0.15 });
  document.querySelectorAll('.reveal, .section-title').forEach(function (el) { io.observe(el); });

  // mark stats section for counter
  var stats = document.querySelector('.stats');
  if (stats) io.observe(stats);
});

function animateCount(el) {
  var target = parseFloat(el.getAttribute('data-count'));
  var suffix = el.getAttribute('data-suffix') || '';
  var dur = 1200, start = null;
  function step(ts) {
    if (!start) start = ts;
    var p = Math.min((ts - start) / dur, 1);
    var val = Math.floor((1 - Math.pow(1 - p, 3)) * target);
    el.textContent = (suffix && target >= 100 ? '+' : '') + val + suffix;
    if (p < 1) requestAnimationFrame(step);
  }
  requestAnimationFrame(step);
}

// Brand carousel: arrow nav + drag scroll
function initCarousels() {
  document.querySelectorAll('.brand-carousel').forEach(function (car) {
    var track = car.querySelector('.brand-track');
    if (!track || track.dataset.init) return;
    track.dataset.init = '1';
    var stepAmt = 230;
    var prev = car.querySelector('.carousel-arrow.prev');
    var next = car.querySelector('.carousel-arrow.next');
    if (prev) prev.addEventListener('click', function () { track.scrollBy({ left: -stepAmt * 2, behavior: 'smooth' }); });
    if (next) next.addEventListener('click', function () { track.scrollBy({ left: stepAmt * 2, behavior: 'smooth' }); });
    var down = false, sx = 0, sl = 0;
    track.addEventListener('mousedown', function (e) { down = true; sx = e.pageX; sl = track.scrollLeft; track.style.cursor = 'grabbing'; });
    window.addEventListener('mouseup', function () { down = false; track.style.cursor = ''; });
    track.addEventListener('mousemove', function (e) { if (down) track.scrollLeft = sl - (e.pageX - sx); });
    track.addEventListener('wheel', function (e) {
      if (Math.abs(e.deltaY) > Math.abs(e.deltaX)) { track.scrollLeft += e.deltaY; e.preventDefault(); }
    }, { passive: false });
  });
}
if (document.readyState !== 'loading') initCarousels();
else document.addEventListener('DOMContentLoaded', initCarousels);

// Header shrink on scroll — premium
(function(){var h=document.querySelector(".site-header");if(!h)return;var t=function(){h.classList.toggle("scrolled",window.scrollY>18)};window.addEventListener("scroll",t,{passive:true});t()})();


// Scroll Reveal - lightweight IntersectionObserver
(function(){
  if(window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
  var obs = new IntersectionObserver(function(ents){
    ents.forEach(function(e){ if(e.isIntersecting){ e.target.classList.add('in'); obs.unobserve(e.target); } });
  }, {threshold:0.12, rootMargin:'0px 0px -40px 0px'});
  function initReveal(){
    var els = document.querySelectorAll('.section-title, .page-title, .cat-hero h1, .cat-hero p, .prod-card, .cat-tile, .brand-tile, .pd-wrap, .pd-img, .pd-info, .about-text, .contact-grid, .certs-row, .faq-item, .map-wrap, .hero h1, .hero p, .hero-cta, .hero-stats');
    els.forEach(function(el,i){
      if(el.classList.contains('reveal')) return;
      el.classList.add('reveal');
      // stagger for cards in same row
      if(el.classList.contains('prod-card') || el.classList.contains('cat-tile') || el.classList.contains('brand-tile') || el.classList.contains('faq-item')){
        var idx = Array.from(el.parentNode.children).indexOf(el) % 4;
        if(idx===1) el.classList.add('delay1');
        else if(idx===2) el.classList.add('delay2');
        else if(idx===3) el.classList.add('delay3');
      }
      obs.observe(el);
    });
  }
  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded', initReveal);
  else initReveal();
  // re-init after dynamic content
  setTimeout(initReveal, 800);
})();

// Lenis Smooth Scroll DISABLED - was causing heavy scroll (1.1s duration)
(function(){
  // disabled for performance - using native smooth scroll only
  document.documentElement.style.scrollBehavior='smooth';
  return;
  var isMobile = window.innerWidth < 860;
  // inject Lenis CDN
  var s = document.createElement('script');
  s.src = 'https://cdn.jsdelivr.net/gh/studio-freight/lenis@1/bundled/lenis.min.js';
  s.onload = function(){
    if(!window.Lenis) return;
    if(isMobile) return; // disable on mobile - breaks drawer
    var lenis = new Lenis({
      duration: isMobile ? 0.8 : 1.1,
      easing: function(t){ return Math.min(1, 1.001 - Math.pow(2, -10 * t)); },
      smoothWheel: true,
      smoothTouch: false
    });
    function raf(time){ lenis.raf(time); requestAnimationFrame(raf); }
    requestAnimationFrame(raf);
    // mobile: reduce stagger delay
    if(isMobile){
      document.querySelectorAll('.reveal.delay1,.reveal.delay2,.reveal.delay3').forEach(function(el){
        el.style.transitionDelay = '0.04s';
      });
    }
  };
  s.onerror = function(){ document.documentElement.style.scrollBehavior='smooth'; };
  document.head.appendChild(s);
  // fallback
  if(!isMobile) document.documentElement.style.scrollBehavior='smooth';
})();


// Skeleton loader - show while images load
(function(){
  var grids = document.querySelectorAll('.cat-products, .brand-track, .brands-grid');
  grids.forEach(function(grid){
    if(grid.children.length>0) return;
    // inject 6 skeletons if grid empty on load
    for(var i=0;i<6;i++){
      var sk = document.createElement('div');
      sk.className = 'sk-card';
      sk.innerHTML = '<div class="skeleton sk-img"></div><div class="sk-body"><div class="skeleton sk-line w80"></div><div class="skeleton sk-line w60"></div><div class="skeleton sk-line w40"></div></div>';
      grid.appendChild(sk);
    }
    // remove skeletons when real cards appear (observer)
    var mo = new MutationObserver(function(){
      var hasReal = grid.querySelector('.prod-card, .cat-tile, .brand-tile');
      if(hasReal){
        grid.querySelectorAll('.sk-card').forEach(function(s){ s.remove(); });
        mo.disconnect();
      }
    });
    mo.observe(grid, {childList:true});
    setTimeout(function(){ grid.querySelectorAll('.sk-card').forEach(function(s){ s.style.opacity='0'; setTimeout(function(){ s.remove(); },300); }); }, 4000);
  });
})();
// drawerFix - force reflow on first open (mobile WebKit bug)
(function(){var btn=document.querySelector('.nav-toggle');var drawer=document.querySelector('.drawer');if(!btn||!drawer)return;var orig=btn.getAttribute('onclick');if(orig)btn.removeAttribute('onclick');btn.addEventListener('click',function(e){e.preventDefault();e.stopPropagation();var open=document.body.classList.contains('drawer-open');if(open){document.body.classList.remove('drawer-open');}else{var s=drawer.style.transition;drawer.style.transition='none';void drawer.offsetHeight;drawer.style.transition=s||'';document.body.classList.add('drawer-open');}},true);})();


/* cache-bust 20260909c */
