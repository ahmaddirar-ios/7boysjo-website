/* 7 Boys® Smart Chat widget — reads live catalog via chat.php */
(function () {
  if (document.getElementById('sb-chat')) return;
  var S = document.createElement('style');
  S.textContent = `
  #sb-chat-btn{position:fixed;left:20px;bottom:158px;z-index:300;width:56px;height:56px;border-radius:50%;
    background:rgba(22,22,23,.92);-webkit-backdrop-filter:saturate(180%) blur(20px);backdrop-filter:saturate(180%) blur(20px);
    color:#fff;border:none;cursor:pointer;box-shadow:0 6px 20px rgba(0,0,0,.25);display:flex;align-items:center;justify-content:center;font-size:26px;transition:transform .25s}
  #sb-chat-btn:hover{transform:scale(1.06)}
  #sb-chat{position:fixed;left:20px;bottom:226px;z-index:300;width:340px;max-width:calc(100vw - 40px);height:460px;max-height:calc(100vh - 120px);
    background:#fff;border-radius:18px;box-shadow:0 12px 40px rgba(0,0,0,.22);display:none;flex-direction:column;overflow:hidden;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}
  #sb-chat.open{display:flex;animation:sbpop .25s ease}
  @keyframes sbpop{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:none}}
  #sb-chat header{background:rgba(22,22,23,.92);color:#fff;padding:14px 16px;font-weight:600;display:flex;justify-content:space-between;align-items:center}
  #sb-chat header small{font-weight:400;opacity:.7;font-size:12px}
  #sb-chat .close{background:none;border:none;color:#fff;font-size:20px;cursor:pointer;line-height:1}
  #sb-chat .body{flex:1;overflow-y:auto;padding:14px;display:flex;flex-direction:column;gap:10px;background:#f5f5f7}
  .sb-msg{max-width:85%;padding:10px 14px;border-radius:14px;font-size:14px;line-height:1.4}
  .sb-bot{align-self:flex-start;background:#fff;border:1px solid #e3e3e8}
  .sb-user{align-self:flex-end;background:#2e6b3e;color:#fff}
  .sb-cards{display:flex;flex-direction:column;gap:8px;align-self:flex-start;max-width:100%}
  .sb-card{display:flex;gap:10px;align-items:center;background:#fff;border:1px solid #e3e3e8;border-radius:12px;padding:8px;text-decoration:none;color:inherit;transition:.2s}
  .sb-card:hover{border-color:#2e6b3e;transform:translateY(-1px)}
  .sb-card img{width:44px;height:44px;object-fit:contain;background:#fff;border:1px solid #eee;border-radius:8px;padding:3px;flex:0 0 44px}
  .sb-card .nm{font-weight:600;font-size:13px}
  .sb-card .mt{font-size:11px;color:#888}
  .sb-card .tg{font-size:10px;color:#2e6b3e;text-transform:capitalize}
  #sb-chat .input{display:flex;border-top:1px solid #eee;background:#fff}
  #sb-chat .input input{flex:1;border:none;padding:14px;font-size:14px;outline:none}
  #sb-chat .input button{border:none;background:#2e6b3e;color:#fff;padding:0 18px;font-weight:600;cursor:pointer}
  #sb-chat .typing{font-size:12px;color:#999;padding:4px 2px}
  `;
  document.head.appendChild(S);

  var btn = document.createElement('button');
  btn.id = 'sb-chat-btn'; btn.innerHTML = '💬';
  btn.setAttribute('aria-label', 'Chat');
  document.body.appendChild(btn);

  var box = document.createElement('div');
  box.id = 'sb-chat';
  box.innerHTML = `
    <header>7 Boys® Assistant <small>يحتارج من كتالوجنا</small><button class="close">×</button></header>
    <div class="body" id="sb-body">
      <div class="sb-msg sb-bot">مرحباً! 🍫 اسألني عن أي منتج أو براند عندنا — مثلاً "Coca-Cola" أو "شوكولاتة".</div>
    </div>
    <div class="input"><input id="sb-in" placeholder="اكتب سؤالك..." autocomplete="off"><button id="sb-send">إرسال</button></div>`;
  document.body.appendChild(box);

  var body = box.querySelector('#sb-body');
  var input = box.querySelector('#sb-in');
  var send = box.querySelector('#sb-send');

  function addMsg(text, who) {
    var d = document.createElement('div');
    d.className = 'sb-msg ' + (who === 'user' ? 'sb-user' : 'sb-bot');
    d.textContent = text;
    body.appendChild(d);
    body.scrollTop = body.scrollHeight;
  }
  function addCards(cards) {
    if (!cards.length) return;
    var wrap = document.createElement('div');
    wrap.className = 'sb-cards';
    cards.forEach(function (c) {
      var a = document.createElement('a');
      a.className = 'sb-card'; a.href = c.url;
      a.innerHTML = (c.img ? '<img src="' + c.img + '" alt="">' : '<div style="width:44px;flex:0 0 44px"></div>')
        + '<div><div class="nm">' + c.name + '</div><div class="mt">' + (c.meta || '') + '</div><div class="tg">' + c.type + '</div></div>';
      wrap.appendChild(a);
    });
    body.appendChild(wrap);
    body.scrollTop = body.scrollHeight;
  }
  function ask(q) {
    q = q.trim();
    if (!q) return;
    addMsg(q, 'user');
    input.value = '';
    var t = document.createElement('div');
    t.className = 'typing'; t.textContent = 'جاري البحث...';
    body.appendChild(t); body.scrollTop = body.scrollHeight;
    fetch('/chat.php?q=' + encodeURIComponent(q)).then(function (r) { return r.json(); }).then(function (d) {
      body.removeChild(t);
      if (d.reply) addMsg(d.reply, 'bot');
      if (d.cards) addCards(d.cards);
    }).catch(function () {
      body.removeChild(t);
      addMsg('صار خطأ بسيط، جرّب مرة ثانية.', 'bot');
    });
  }

  btn.addEventListener('click', function () { box.classList.toggle('open'); if (box.classList.contains('open')) input.focus(); });
  box.querySelector('.close').addEventListener('click', function () { box.classList.remove('open'); });
  send.addEventListener('click', function () { ask(input.value); });
  input.addEventListener('keydown', function (e) { if (e.key === 'Enter') ask(input.value); });
})();

/* cache-bust 20260909c */
