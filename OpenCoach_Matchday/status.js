
/* status.js v6 - time based status detection only */
(function(){
  const Q = (sel, root=document) => root.querySelector(sel);
  const QA = (sel, root=document) => Array.from(root.querySelectorAll(sel));

  function pad(n){ n = String(n); return n.length < 2 ? ('0' + n) : n; }
  function toISODate(d){ return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
  function parseIntDefault(v, d){ const n = parseInt(v, 10); return isNaN(n) ? d : n; }

  function parseBaseDate(str){
    if(!str) return null;
    const s = String(str).trim();
    if(/^\d{4}-\d{2}-\d{2}$/.test(s)){
      const d = new Date(s + 'T00:00:00');
      if(!isNaN(d)) return d;
    }
    const m = s.match(/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/);
    if(m){
      const d = new Date(m[3] + '-' + pad(m[2]) + '-' + pad(m[1]) + 'T00:00:00');
      if(!isNaN(d)) return d;
    }
    return null;
  }

  function tryParseDateTime(s){
    const t = String(s).trim().replace(' ', 'T');
    let d = new Date(t);
    if(!isNaN(d)) return d;
    const m = String(s).match(/^(\d{1,2})\.(\d{1,2})\.(\d{2,4})(?:\s+(\d{1,2}):(\d{2}))?$/);
    if(m){
      const y = (m[3].length === 2 ? ('20' + m[3]) : m[3]);
      const hh = m[4] ? pad(m[4]) : '00';
      const mm = m[5] ? pad(m[5]) : '00';
      d = new Date(y + '-' + pad(m[2]) + '-' + pad(m[1]) + 'T' + hh + ':' + mm + ':00');
      if(!isNaN(d)) return d;
    }
    return null;
  }

  function parseDateTimeFromRow(tr){
    const ds = tr.getAttribute('data-start') || Q('[data-start]', tr)?.getAttribute('data-start');
    if(ds){
      const p = tryParseDateTime(ds);
      if(p) return p;
    }
    const txt = tr.textContent || '';
    const mCombo = txt.match(/(\d{1,2})\.(\d{1,2})\.(\d{2,4})\s+(\d{1,2}):(\d{2})/);
    if(mCombo){
      const y = (mCombo[3].length === 2 ? ('20' + mCombo[3]) : mCombo[3]);
      return new Date(y + '-' + pad(mCombo[2]) + '-' + pad(mCombo[1]) + 'T' + pad(mCombo[4]) + ':' + pad(mCombo[5]) + ':00');
    }
    const mDate = txt.match(/(\d{1,2})\.(\d{1,2})\.(\d{2,4})/);
    const mTime = txt.match(/(\d{1,2}):(\d{2})/);
    if(mDate && mTime){
      const y = (mDate[3].length === 2 ? ('20' + mDate[3]) : mDate[3]);
      return new Date(y + '-' + pad(mDate[2]) + '-' + pad(mDate[1]) + 'T' + pad(mTime[1]) + ':' + pad(mTime[2]) + ':00');
    }
    if(mTime) return { __timeOnly: true, hh: parseInt(mTime[1], 10), mm: parseInt(mTime[2], 10) };
    return null;
  }

  function getControlMode(){
    return document.querySelector('meta[name="control-mode"]')?.content || 'auto';
  }

  function getConfig(){
    const meta = {
      base: Q('meta[name="match-base-date"]')?.content,
      dur: Q('meta[name="match-duration"]')?.content,
      upc: Q('meta[name="match-upcoming-window"]')?.content
    };
    let base = parseBaseDate(meta.base);
    let duration = parseIntDefault(meta.dur, NaN);
    let upcoming = parseIntDefault(meta.upc, NaN);

    if(!base && window.SPIELPLAN_CONFIG?.baseDate) base = parseBaseDate(window.SPIELPLAN_CONFIG.baseDate);
    if(isNaN(duration) && window.SPIELPLAN_CONFIG?.duration) duration = parseIntDefault(window.SPIELPLAN_CONFIG.duration, NaN);
    if(isNaN(upcoming) && window.SPIELPLAN_CONFIG?.upcoming) upcoming = parseIntDefault(window.SPIELPLAN_CONFIG.upcoming, NaN);

    try {
      const ls = JSON.parse(localStorage.getItem('SPIELPLAN_CONFIG') || '{}');
      if(!base && ls.baseDate) base = parseBaseDate(ls.baseDate);
      if(isNaN(duration) && ls.duration) duration = parseIntDefault(ls.duration, NaN);
      if(isNaN(upcoming) && ls.upcoming) upcoming = parseIntDefault(ls.upcoming, NaN);
    } catch(e) {}

    if(isNaN(duration)) duration = 18;
    if(isNaN(upcoming)) upcoming = 10;
    return { base, duration, upcoming };
  }

  function markRow(tr, status){
    tr.classList.remove('is-live', 'is-upcoming', 'is-finished');
    tr.classList.add('is-' + status);
    const firstCell = Q('td,th', tr);
    if(!firstCell) return;

    const oldBadges = QA('.badge-live, .badge-upcoming, .badge-finished', firstCell);
    oldBadges.forEach(el => el.remove());

    if(Q('.runtime-badge', firstCell)) return;

    const badge = document.createElement('span');
    badge.className = 'badge-' + status;
    badge.textContent = (status === 'live' ? 'LIVE' : status === 'upcoming' ? 'BALD' : 'ENDE');
    badge.style.marginRight = '.5rem';
    firstCell.prepend(badge);
  }

  function computeAndMark(){
    const cfg = getConfig();
    let base = cfg.base;
    if(!base){
      const allText = document.body.textContent || '';
      const md = allText.match(/(\d{1,2})\.(\d{1,2})\.(\d{4})/);
      if(md) base = parseBaseDate(md[0]);
    }
    if(!base){
      const now = new Date();
      base = new Date(now.getFullYear(), now.getMonth(), now.getDate());
      showHint('Basisdatum nicht gefunden. Verwende HEUTE: ' + toISODate(base));
    }

    const now = new Date();
    const controlMode = getControlMode();
    QA('.matches-table:not(.backup-table) tbody tr').forEach(tr => {
      const explicitState = tr.getAttribute('data-runtime-state');
      if(explicitState && explicitState !== 'planned'){ markRow(tr, explicitState); return; }
      if(explicitState === 'planned') return;
      if(controlMode !== 'auto') return;
      const parsed = parseDateTimeFromRow(tr);
      let start = null;
      if(parsed && parsed.__timeOnly){
        start = new Date(base.getFullYear(), base.getMonth(), base.getDate(), parsed.hh, parsed.mm, 0);
      } else if(parsed instanceof Date){
        start = parsed;
      }
      if(!start || isNaN(start)) return;

      const end = new Date(start.getTime() + cfg.duration * 60000);
      let status = null;
      if(now > end) status = 'finished';
      else if(now >= start && now <= end) status = 'live';
      else if(now >= new Date(start.getTime() - cfg.upcoming * 60000) && now < start) status = 'upcoming';

      if(status) markRow(tr, status);
});
}

  function showHint(msg){
    if(Q('.status-hint')) return;
    const hint = document.createElement('div');
    hint.className = 'status-hint';
    hint.textContent = msg;
    hint.style.cssText = 'margin:.5rem 0;padding:.5rem .75rem;border:1px solid #f59e0b;border-radius:10px;color:#fef3c7;background:#78350f33;';
    const host = Q('main') || document.body;
    host.prepend(hint);
  }

  if(document.readyState === 'loading') document.addEventListener('DOMContentLoaded', computeAndMark);
  else computeAndMark();
  setInterval(computeAndMark, 60000);
})();
