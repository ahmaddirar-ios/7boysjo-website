document.addEventListener("DOMContentLoaded",function(){
  // Only on category / search pages where .cat-products exists
  var grids=document.querySelectorAll(".cat-products");
  if(!grids.length) return;
  grids.forEach(function(grid){
    grid.querySelectorAll(".prod-card .body p").forEach(function(p){
      var txt=(p.textContent||"").replace(/\s+/g," ").trim();
      // txt like "Weight 250ml pcs/ct 8 ct/pal 270" or "Weight 100g ..."
      // Extract parts
      var weight="", pcs="", ctpal="";
      var mW=txt.match(/(\d+\s*(ml|g|kg|l))\b/i);
      if(mW) weight=mW[1].replace(/\s+/g,"");
      var mPcs=txt.match(/pcs\/ct\s*(\d+|--)/i);
      if(mPcs) pcs=mPcs[1];
      var mCt=txt.match(/ct\/pal\s*(\d+)/i);
      if(mCt) ctpal=mCt[1];
      // Build pills
      p.innerHTML="";
      if(weight){var s=document.createElement("span");s.className="spec-pill";s.textContent=weight; p.appendChild(s);}
      if(pcs && pcs!=="--"){var s=document.createElement("span");s.className="spec-pill";s.textContent=pcs+" pcs/ct"; p.appendChild(s);}
      if(ctpal){var s=document.createElement("span");s.className="spec-pill";s.textContent=ctpal+" /pal"; p.appendChild(s);}
      if(!p.children.length){
        // fallback: show cleaned text as single pill
        var s=document.createElement("span");s.className="spec-pill";s.textContent=txt.slice(0,40); p.appendChild(s);
      }
    });
  });
});

/* cache-bust 20260909c */
