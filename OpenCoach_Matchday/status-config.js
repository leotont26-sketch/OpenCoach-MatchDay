
(function(){
  function getMeta(name){
    var el=document.querySelector('meta[name="'+name+'"]');
    return el?el.getAttribute('content'):null;
  }
  function intOr(v, d){ var n=parseInt(v,10); return isNaN(n)?d:n; }
  function pick(a,b,c){ return a!=null && a!=='' ? a : (b!=null && b!=='' ? b : c); }
  var ls=null; try{ ls = JSON.parse(localStorage.getItem('SPIELPLAN_CONFIG')||'null'); }catch(e){ ls=null; }

  var base    = pick(getMeta('match-base-date'), ls && (ls.base||ls.baseDate), '');
  var dur     = intOr(pick(getMeta('match-duration'), ls && ls.duration, '18'), 18);
  var upcoming= intOr(pick(getMeta('match-upcoming-window'), ls && ls.upcoming, '10'), 10);

  window.SPIELPLAN_CONFIG = { base: base, duration: dur, upcoming: upcoming };
  try{ localStorage.setItem('SPIELPLAN_CONFIG', JSON.stringify(window.SPIELPLAN_CONFIG)); }catch(e){}
})();
