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
  function onScroll() {
    var y = window.scrollY;
    header.classList.toggle('scrolled', y > 40);
    if (toTop) toTop.classList.toggle('show', y > 600);
  }
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

/* ===== Apple-grade page polish (added) ===== */
(function () {
  // 1) Buttery smooth in-page anchor scrolling with fixed-header offset
  document.querySelectorAll('a[href^="#"]').forEach(function (a) {
    a.addEventListener('click', function (e) {
      var id = a.getAttribute('href').slice(1);
      if (!id) return;
      var t = document.getElementById(id);
      if (!t) return;
      e.preventDefault();
      var off = 56; // slim nav height + breathing room
      window.scrollTo({ top: t.getBoundingClientRect().top + window.scrollY - off, behavior: 'smooth' });
      history.replaceState(null, '', '#' + id);
    });
  });

  // 2) Header condenses as you scroll (Apple nav behavior)
  var hdr = document.querySelector('.site-header');
  if (hdr) {
    var lastY = 0;
    window.addEventListener('scroll', function () {
      var y = window.scrollY;
      hdr.style.transition = 'background .3s ease';
      // hide-on-scroll-down, show-on-scroll-up (apple.com does this)
      if (y > 140 && y > lastY) hdr.style.transform = 'translateY(-100%)';
      else hdr.style.transform = 'translateY(0)';
      lastY = y;
    }, { passive: true });
  }

  // 3) Scroll progress hairline under the nav
  var prog = document.createElement('div');
  prog.style.cssText = 'position:fixed;top:0;left:0;height:2px;width:0;background:linear-gradient(90deg,#2e6b3e,#c0a040);z-index:201;transition:width .08s linear;pointer-events:none;';
  document.body.appendChild(prog);
  window.addEventListener('scroll', function () {
    var h = document.documentElement.scrollHeight - window.innerHeight;
    prog.style.width = (h > 0 ? (window.scrollY / h) * 100 : 0) + '%';
  }, { passive: true });

  // 4) Gentle hero parallax (content drifts slower than scroll)
  var hero = document.querySelector('.hero');
  if (hero && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    window.addEventListener('scroll', function () {
      var y = window.scrollY;
      if (y < window.innerHeight) hero.style.transform = 'translateY(' + (y * 0.18).toFixed(1) + 'px)';
    }, { passive: true });
  }

  // 5) Cards: soft spring lift instead of hard tilt jump
  var style = document.createElement('style');
  style.textContent =
    '.prod-card,.cat-tile,.brand-tile{will-change:transform;transition:transform .45s cubic-bezier(.2,.9,.25,1),box-shadow .45s cubic-bezier(.2,.9,.25,1)!important}' +
    '@media(prefers-reduced-motion:reduce){*{animation:none!important;transition:none!important}}';
  document.head.appendChild(style);
})();

  // Featured loop: seamless auto-scroll infinite
  document.querySelectorAll('.featured-carousel.loop .feat-track, .beverages-carousel.loop .bev-track').forEach(function(track){
    var car = track.closest('.featured-carousel, .beverages-carousel');
    var isHover = false;
    car.addEventListener('mouseenter', function(){ isHover=true; });
    car.addEventListener('mouseleave', function(){ isHover=false; });
    var speed = 0.7;
    var maxScroll = function(){ return track.scrollWidth - track.clientWidth; };
    function tick(){
      if(!isHover && maxScroll()>10){
        track.scrollLeft += speed;
        if(track.scrollLeft >= maxScroll()) track.scrollLeft = 0;
        else if(track.scrollLeft <= 0 && speed<0) track.scrollLeft = maxScroll();
      }
      requestAnimationFrame(tick);
    }
    requestAnimationFrame(tick);
  });
  });
}
if (document.readyState !== 'loading') initCarousels();
else document.addEventListener('DOMContentLoaded', initCarousels);

/* cache-bust 20260909c */
