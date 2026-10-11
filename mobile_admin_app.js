/* Rice Valy Mobile Admin App UI v1.0 */
(function(){
  'use strict';
  const MOBILE_MAX = 991.98;
  const isMobile = () => window.matchMedia('(max-width: '+MOBILE_MAX+'px)').matches;

  const svg = (name) => {
    const icons = {
      products:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5M12 22V12"/></svg>',
      orders:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18M16 10a4 4 0 0 1-8 0"/></svg>',
      customers:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
      users:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M20 21a8 8 0 0 0-16 0"/><circle cx="12" cy="7" r="4"/></svg>',
      reports:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M3 3v18h18"/><path d="m7 16 4-5 4 3 5-7"/></svg>'
    };
    return icons[name] || '';
  };

  function pageTitle(){
    const h = document.querySelector('.page-title, .modal-title');
    if(h && h.textContent.trim()) return h.textContent.trim();
    return (document.title || 'Rice Valy Admin').split(' - ')[0].split(' — ')[0];
  }

  function addTopBrand(){
    const bar = document.querySelector('.navbar-custom .container-fluid');
    if(!bar || bar.querySelector('.rv-mobile-brand')) return;
    const brand = document.createElement('div');
    brand.className = 'rv-mobile-brand';
    brand.innerHTML = '<div><span class="rv-mobile-brand-title"></span><span class="rv-mobile-brand-sub">Rice Valy Admin</span></div>';
    brand.querySelector('.rv-mobile-brand-title').textContent = pageTitle();
    const logo = bar.querySelector('.logo-box');
    if(logo && logo.nextSibling) logo.parentNode.insertBefore(brand, logo.nextSibling);
    else bar.appendChild(brand);
  }

  function addDrawer(){
    if(!document.querySelector('.left-side-menu')) return;
    if(!document.querySelector('.rv-mobile-backdrop')){
      const backdrop = document.createElement('div');
      backdrop.className = 'rv-mobile-backdrop';
      backdrop.setAttribute('aria-hidden','true');
      document.body.appendChild(backdrop);
      backdrop.addEventListener('click', closeDrawer);
    }
    document.querySelectorAll('.button-menu-mobile').forEach(btn => {
      if(btn.dataset.rvBound) return;
      btn.dataset.rvBound = '1';
      btn.setAttribute('aria-label','Open navigation');
      btn.addEventListener('click', function(e){
        if(!isMobile()) return;
        e.preventDefault();
        e.stopImmediatePropagation();
        document.body.classList.toggle('rv-drawer-open');
      }, true);
    });
    const sidebar = document.querySelector('.left-side-menu');
    if(sidebar && !sidebar.querySelector('.rv-drawer-close')){
      const box = sidebar.querySelector('.user-box') || sidebar.firstElementChild;
      if(box){
        const close = document.createElement('button');
        close.type = 'button';
        close.className = 'rv-drawer-close';
        close.setAttribute('aria-label','Close navigation');
        close.innerHTML = '&times;';
        close.addEventListener('click', closeDrawer);
        box.appendChild(close);
      }
    }
    if(sidebar && !sidebar.dataset.rvBound){
      sidebar.dataset.rvBound = '1';
      sidebar.addEventListener('click', function(e){
        const a = e.target.closest('a');
        if(!a || !isMobile()) return;
        const href = (a.getAttribute('href') || '').trim();
        if(href && href !== '#' && !href.startsWith('#') && !a.hasAttribute('data-bs-toggle')){
          setTimeout(closeDrawer,80);
        }
      });
    }
  }
  function closeDrawer(){ document.body.classList.remove('rv-drawer-open'); }

  function addBottomNav(){
    if(document.querySelector('.rv-bottom-nav') || !document.querySelector('.left-side-menu')) return;
    const path = location.pathname.toLowerCase();
    const items = [
      ['products','Products','site_management.php', path.endsWith('/site_management.php')],
      ['orders','Orders','order_management.php?status=all&source=online', path.endsWith('/order_management.php')],
      ['customers','Customers','online_customers.php', path.endsWith('/online_customers.php')],
      ['users','Users','registered_users.php', path.endsWith('/registered_users.php')],
      ['reports','Reports','online_business_report.php', path.endsWith('/online_business_report.php')]
    ];
    const nav = document.createElement('nav');
    nav.className='rv-bottom-nav';
    nav.setAttribute('aria-label','Mobile admin navigation');
    nav.innerHTML = items.map(x => '<a href="'+x[2]+'"'+(x[3]?' class="active" aria-current="page"':'')+'>'+svg(x[0])+'<span>'+x[1]+'</span></a>').join('');
    document.body.appendChild(nav);
  }

  function decorateTable(table){
    if(!table || table.dataset.rvCardReady === '1') return;
    const head = table.querySelector('thead tr');
    if(!head) return;
    const labels = Array.from(head.children).map(th => th.textContent.replace(/\s+/g,' ').trim() || 'Info');
    table.classList.add('rv-card-table');
    table.dataset.rvCardReady='1';
    table.querySelectorAll('tbody tr').forEach(row => decorateRow(row,labels));
  }
  function decorateRow(row,labels){
    const cells = Array.from(row.children).filter(el=>el.tagName==='TD');
    if(!cells.length) return;
    if(cells.length===1 && (cells[0].hasAttribute('colspan') || cells[0].textContent.trim().toLowerCase().includes('no '))){
      row.classList.add('rv-empty-row');
      return;
    }
    cells.forEach((td,i)=>{
      if(!td.dataset.label) td.dataset.label = labels[i] || 'Info';
    });
  }
  function decorateTables(){
    document.querySelectorAll('.table-responsive table, table.order-table, table.customer-table, table.user-table, table.report-table, table.item-table, table.order-history, #sectionProductsTable').forEach(table => {
      const head = table.querySelector('thead tr');
      if(!head) return;
      const labels = Array.from(head.children).map(th => th.textContent.replace(/\s+/g,' ').trim() || 'Info');
      if(!table.dataset.rvCardReady){table.classList.add('rv-card-table');table.dataset.rvCardReady='1';}
      table.querySelectorAll('tbody tr').forEach(row=>decorateRow(row,labels));
    });
  }

  function findKpiGrids(){
    const cards = document.querySelectorAll('.stat-card, .metric-card');
    const rows = new Map();
    cards.forEach(card => {
      const col = card.closest('[class*="col-"]');
      const row = col && col.parentElement;
      if(row && row.classList.contains('row')){
        if(!rows.has(row)) rows.set(row,0);
        rows.set(row,rows.get(row)+1);
      }
    });
    rows.forEach((count,row)=>{ if(count>=2) row.classList.add('rv-kpi-grid'); });
  }

  function markSidebarActive(){
    const current = location.pathname.split('/').pop();
    document.querySelectorAll('#sidebar-menu a[href]').forEach(a=>{
      try{
        const u = new URL(a.getAttribute('href'),location.href);
        if(u.pathname.split('/').pop()===current && current){ a.classList.add('active'); }
      }catch(e){}
    });
  }

  function init(){
    document.body.classList.add('rv-admin-app');
    addTopBrand();
    addDrawer();
    addBottomNav();
    markSidebarActive();
    decorateTables();
    findKpiGrids();

    document.addEventListener('keydown', e=>{ if(e.key==='Escape') closeDrawer(); });
    window.addEventListener('resize',()=>{ if(!isMobile()) closeDrawer(); },{passive:true});

    let queued=false;
    const observer = new MutationObserver(()=>{
      if(queued) return;
      queued=true;
      requestAnimationFrame(()=>{queued=false;decorateTables();findKpiGrids();});
    });
    observer.observe(document.body,{childList:true,subtree:true});
  }

  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',init);
  else init();
})();
