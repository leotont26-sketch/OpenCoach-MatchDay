
// team-filter.js
(function(){
  function text(el){ return (el && (el.textContent||'').trim()) || ''; }
  function $(sel,ctx){ return (ctx||document).querySelector(sel); }
  function $all(sel,ctx){ return Array.from((ctx||document).querySelectorAll(sel)); }

  function findHeimGastIndexes(table){
    const ths = $all('thead th', table).map(th => text(th).toLowerCase());
    let heim = ths.findIndex(t => t.includes('heim'));
    let gast = ths.findIndex(t => t.includes('gast'));
    // Fallback: try "home"/"away"
    if(heim < 0) heim = ths.findIndex(t => t.includes('home'));
    if(gast < 0) gast = ths.findIndex(t => t.includes('away'));
    return {heim, gast};
  }

  function collectTeams(table){
    const set = new Set();
    const idx = findHeimGastIndexes(table);
    const rows = $all('tbody tr', table);
    rows.forEach(tr => {
      const tds = $all('td', tr);
      if(idx.heim >= 0 && tds[idx.heim]){
        const v = text(tds[idx.heim]);
        if(v) set.add(v);
      }
      if(idx.gast >= 0 && tds[idx.gast]){
        const v = text(tds[idx.gast]);
        if(v) set.add(v);
      }
    });
    return Array.from(set).sort((a,b)=>a.localeCompare(b,'de'));
  }

  function makeSelect(options, currentValue, name){
    const sel = document.createElement('select');
    sel.name = name || 'team';
    sel.id = 'teamSelect';
    sel.className = ''; // styling via .filters select already present

    const optAll = document.createElement('option');
    optAll.value = '';
    optAll.textContent = 'Alle Teams';
    sel.appendChild(optAll);

    options.forEach(t => {
      const o = document.createElement('option');
      o.value = t;
      o.textContent = t;
      if(currentValue && currentValue === t) o.selected = true;
      sel.appendChild(o);
    });
    return sel;
  }

  function submitClosestForm(el){
    const form = el.closest('form');
    if(form){
      form.submit();
      return;
    }
    // Fallback: click a filter button
    const btn = $('.filters button, .filters a[role="button"]');
    if(btn){ btn.click(); }
  }

  function run(){
    // find original team input (text or datalist)
    const filters = $('.filters') || document;
    let input = $('input[name="team"]', filters) || $('input#teamFilter', filters) || $('input[placeholder*="Team"]', filters);
    // collect teams from first table on page
    const table = $('table');
    if(!table) return;
    const teams = collectTeams(table);

    // if no input found, we try to add one near the other filter controls
    let currentValue = '';
    let name = 'team';
    if(input){
      currentValue = input.value || '';
      name = input.name || 'team';
    }

    const select = makeSelect(teams, currentValue, name);
    select.addEventListener('change', function(){
      // mirror into a hidden input if the backend expects name="team" and select has different name
      if(input && input.name && input.name !== select.name){
        input.value = select.value;
      } else if(!input){
        // create hidden input to carry value
        const hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = name;
        hidden.value = select.value;
        if(filters && filters.appendChild) filters.appendChild(hidden);
      }
      submitClosestForm(select);
    });

    if(input){
      input.replaceWith(select);
    }else{
      // inject into filters
      if(filters && filters.appendChild){
        filters.appendChild(select);
      }
    }
  }

  if(document.readyState === 'loading'){
    document.addEventListener('DOMContentLoaded', run);
  }else{
    run();
  }
})();
