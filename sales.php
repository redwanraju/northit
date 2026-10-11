
<style>
:root {
  --rv-bg: #f0f4f8;
  --rv-card: #ffffff;
  --rv-border: #e2e8f0;
  --rv-text: #1e293b;
  --rv-muted: #64748b;
  --rv-green: #16a34a;
  --rv-blue: #2563eb;
  --rv-red: #dc2626;
  --rv-orange: #d97706;
  --rv-shadow: 0 1px 8px rgba(0,0,0,0.04);
  --rv-shadow-hover: 0 16px 40px rgba(0,0,0,0.10);
  --rv-radius: 18px;
}

.v3-main {
  flex: 1;
  padding: 24px;
  background: var(--rv-bg);
  min-height: 100vh;
  font-family: 'Segoe UI', system-ui, sans-serif;
}

.v3-fixed-actionbar {
  position: sticky;
  top: 0;
  z-index: 9999;
  margin: -24px -24px 24px;
  padding: 12px 24px;
  background: linear-gradient(135deg, #052e16 0%, #14532d 55%, #166534 100%);
  backdrop-filter: blur(14px);
  border-bottom: 1px solid rgba(255,255,255,0.16);
  box-shadow: 0 12px 34px rgba(5, 46, 22, 0.28);
}
.v3-fixed-actionbar-inner {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 16px;
}
.v3-fixed-brand {
  display: flex;
  align-items: center;
  gap: 10px;
  color: #ffffff;
  font-weight: 900;
  white-space: nowrap;
  letter-spacing: 0.2px;
}
.v3-fixed-brand i {
  width: 38px;
  height: 38px;
  border-radius: 14px;
  background: rgba(255,255,255,0.15);
  color: #bbf7d0;
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: inset 0 0 0 1px rgba(255,255,255,0.16);
}
.v3-fixed-actions {
  display: flex;
  align-items: center;
  gap: 10px;
  overflow-x: auto;
  padding-bottom: 2px;
}
.v3-fixed-actions::-webkit-scrollbar { height: 4px; }
.v3-fixed-actions::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.35); border-radius: 20px; }
.v3-fixed-btn {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  padding: 9px 14px;
  border-radius: 999px;
  background: rgba(255,255,255,0.12);
  color: #ffffff;
  border: 1px solid rgba(255,255,255,0.18);
  text-decoration: none;
  font-size: 0.82rem;
  font-weight: 800;
  white-space: nowrap;
  transition: all 0.25s ease;
  cursor: pointer;
  font-family: inherit;
}
.v3-fixed-btn:hover {
  background: rgba(255,255,255,0.22);
  color: #ffffff;
  transform: translateY(-1px);
  box-shadow: 0 10px 24px rgba(0,0,0,0.18);
}
.v3-fixed-btn-primary, .v3-fixed-btn-primary:hover {
  background: linear-gradient(135deg, #f59e0b, #d97706);
  color: #111827;
  border-color: rgba(255,255,255,0.28);
}
.v3-header, .v3-summary-card, .v3-source-card, .v3-balance-card, .v3-stat-card, .v3-panel, .v3-menu-item, .v3-stock-card {
  background: var(--rv-card);
  border: 1px solid var(--rv-border);
  box-shadow: var(--rv-shadow);
}
.v3-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 16px;
  margin-bottom: 20px;
  padding: 22px 28px;
  border-radius: 16px;
}
.v3-header-text h4 { font-size: 1.5rem; font-weight: 800; margin: 0 0 6px; color: var(--rv-text); }
.v3-header-text h4 i { color: #f59e0b; margin-right: 8px; }
.v3-header-text p { color: var(--rv-muted); margin: 0; font-size: 0.85rem; }
.v3-live-badge {
  display: flex;
  align-items: center;
  gap: 8px;
  background: #ecfdf5;
  color: #059669;
  padding: 8px 18px;
  border-radius: 50px;
  font-size: 0.8rem;
  font-weight: 700;
}
.v3-pulse-dot {
  width: 8px;
  height: 8px;
  background: #22c55e;
  border-radius: 50%;
  animation: v3-pulse 2s infinite;
}
@keyframes v3-pulse {
  0%, 100% { opacity: 1; transform: scale(1); }
  50% { opacity: 0.5; transform: scale(1.35); }
}
.v3-summary-row, .v3-source-row, .v3-top-row, .v3-balance-row, .v3-mid-row {
  display: grid;
  gap: 16px;
  margin-bottom: 20px;
}
.v3-summary-row, .v3-top-row { grid-template-columns: repeat(4, 1fr); }
.v3-source-row { grid-template-columns: repeat(3, 1fr); }
.v3-balance-row, .v3-mid-row { grid-template-columns: repeat(2, 1fr); }
.v3-summary-card { padding: 20px; border-radius: 14px; }
.v3-summary-card h6 { margin: 0 0 12px; font-size: 0.9rem; font-weight: 800; color: var(--rv-text); }
.v3-summary-line {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  padding: 7px 0;
  font-size: 0.85rem;
  border-bottom: 1px solid #f1f5f9;
}
.v3-summary-line:last-child { border-bottom: none; }
.v3-summary-label { font-weight: 700; }
.v3-summary-value { font-weight: 800; font-size: 0.9rem; white-space: nowrap; }
.v3-green { color: var(--rv-green); }
.v3-blue { color: var(--rv-blue); }
.v3-red { color: var(--rv-red); }
.v3-orange { color: var(--rv-orange); }
.v3-gray { color: #475569; }
.v3-section-title {
  font-size: 0.8rem;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 1.5px;
  color: #94a3b8;
  margin: 30px 0 14px;
  display: flex;
  align-items: center;
  gap: 10px;
}
.v3-section-title::after { content: ''; flex: 1; height: 1px; background: var(--rv-border); }
.v3-section-title i { color: #f59e0b; }
.v3-source-note {
  background: #f8fafc;
  border: 1px dashed #cbd5e1;
  color: #475569;
  padding: 9px 12px;
  border-radius: 12px;
  font-size: 0.78rem;
  margin-bottom: 16px;
}
.v3-source-card {
  border-radius: 16px;
  padding: 20px;
  position: relative;
  overflow: hidden;
}
.v3-source-card::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 5px; }
.v3-source-pos::before { background: var(--rv-blue); }
.v3-source-online::before { background: var(--rv-green); }
.v3-source-total::before { background: #f59e0b; }
.v3-source-title { display: flex; justify-content: space-between; align-items: center; gap: 10px; margin-bottom: 14px; }
.v3-source-title h6 { margin: 0; font-size: 0.95rem; color: var(--rv-text); font-weight: 800; }
.v3-source-icon { width: 38px; height: 38px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 16px; }
.v3-source-pos .v3-source-icon { background: #eff6ff; color: var(--rv-blue); }
.v3-source-online .v3-source-icon { background: #ecfdf5; color: var(--rv-green); }
.v3-source-total .v3-source-icon { background: #fffbeb; color: var(--rv-orange); }
.v3-source-big { font-size: 1.8rem; font-weight: 900; color: var(--rv-text); line-height: 1; margin-bottom: 4px; }
.v3-source-sub { font-size: 0.78rem; color: var(--rv-muted); margin-bottom: 12px; }
.v3-balance-card { border-radius: 14px; overflow: hidden; }
.v3-balance-header { padding: 14px 20px; font-size: 1.1rem; font-weight: 800; text-align: center; }
.v3-assets-header { background: #e0f2fe; color: #0369a1; }
.v3-liability-header { background: #fee2e2; color: #b91c1c; }
.v3-balance-body { padding: 16px 20px; }
.v3-balance-item {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  padding: 10px 0;
  font-size: 0.9rem;
  border-bottom: 1px solid #f1f5f9;
}
.v3-balance-total {
  border-bottom: none;
  padding-top: 14px;
  margin-top: 8px;
  border-top: 2px solid var(--rv-border);
  font-weight: 800;
  font-size: 1rem;
}
.v3-balance-label { font-weight: 700; color: #334155; }
.v3-balance-value { font-weight: 800; color: var(--rv-text); font-family: 'Courier New', monospace; white-space: nowrap; }
.v3-stat-card { padding: 22px; border-radius: 20px; transition: all 0.3s ease; position: relative; overflow: hidden; }
.v3-stat-card:hover, .v3-menu-item:hover, .v3-stock-card:hover { transform: translateY(-4px); box-shadow: var(--rv-shadow-hover); }
.v3-stat-card::after { content: ''; position: absolute; bottom: 0; left: 0; right: 0; height: 3px; }
.v3-stat-revenue::after { background: linear-gradient(90deg, #3b82f6, #60a5fa); }
.v3-stat-paid::after { background: linear-gradient(90deg, #10b981, #34d399); }
.v3-stat-due::after { background: linear-gradient(90deg, #f59e0b, #fbbf24); }
.v3-stat-expense::after { background: linear-gradient(90deg, #ef4444, #f87171); }
.v3-stat-icon { width: 48px; height: 48px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 20px; margin-bottom: 12px; }
.v3-stat-revenue .v3-stat-icon { background: #eff6ff; color: #3b82f6; }
.v3-stat-paid .v3-stat-icon { background: #ecfdf5; color: #10b981; }
.v3-stat-due .v3-stat-icon { background: #fffbeb; color: #f59e0b; }
.v3-stat-expense .v3-stat-icon { background: #fef2f2; color: #ef4444; }
.v3-stat-label { font-size: 0.75rem; color: var(--rv-muted); text-transform: uppercase; letter-spacing: 0.5px; font-weight: 800; margin-bottom: 6px; display: block; }
.v3-stat-value { font-size: 1.6rem; font-weight: 900; color: var(--rv-text); display: block; margin-bottom: 4px; }
.v3-stat-sub { font-size: 0.75rem; color: #94a3b8; font-weight: 600; }
.v3-panel { padding: 24px; border-radius: 20px; }
.v3-panel h5 { margin: 0 0 20px; font-size: 0.92rem; font-weight: 800; color: var(--rv-text); display: flex; align-items: center; gap: 8px; }
.v3-bar-list { display: flex; flex-direction: column; gap: 18px; }
.v3-bar-item { display: grid; grid-template-columns: 1fr 120px auto; align-items: center; gap: 12px; }
.v3-bar-item span { font-size: 0.85rem; color: #475569; font-weight: 700; }
.v3-bar-item b { font-size: 0.9rem; color: var(--rv-text); font-weight: 800; text-align: right; white-space: nowrap; }
.v3-bar-track { height: 8px; background: #f1f5f9; border-radius: 50px; overflow: hidden; }
.v3-bar-fill { height: 100%; background: linear-gradient(90deg, #3b82f6, #60a5fa); border-radius: 50px; transition: width 0.6s ease; }
.v3-bar-fill.v3-red { background: linear-gradient(90deg, #ef4444, #f87171); }
.v3-bar-fill.v3-greenbar { background: linear-gradient(90deg, #10b981, #34d399); }
.v3-dues-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; }
.v3-due-card { background: #f8fafc; padding: 18px; border-radius: 16px; display: flex; flex-direction: column; align-items: center; gap: 6px; border: 1px solid var(--rv-border); }
.v3-due-card i { font-size: 22px; color: var(--rv-muted); }
.v3-due-card span { font-size: 0.75rem; color: var(--rv-muted); font-weight: 700; }
.v3-due-card b { font-size: 1.1rem; color: var(--rv-text); font-weight: 900; }
.v3-grand {
  background: linear-gradient(135deg, #1e293b 0%, #334155 100%);
  color: white;
  padding: 24px 28px;
  border-radius: 20px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 16px;
  margin-bottom: 28px;
  box-shadow: 0 8px 32px rgba(30, 41, 59, 0.25);
}
.v3-grand-left { display: flex; align-items: center; gap: 16px; }
.v3-grand-left i { width: 48px; height: 48px; background: rgba(250, 204, 21, 0.15); border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 22px; color: #facc15; }
.v3-grand-left div { display: flex; flex-direction: column; }
.v3-grand-label { font-size: 0.75rem; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 800; margin-bottom: 4px; }
.v3-grand-value { font-size: 1.8rem; font-weight: 900; color: #facc15; }
.v3-grand-right { display: flex; flex-direction: column; align-items: flex-end; gap: 8px; }
.v3-grand-chip { background: rgba(255,255,255,0.08); padding: 4px 14px; border-radius: 50px; font-size: 0.7rem; color: #cbd5e1; font-weight: 700; }
.v3-grand-progress { width: 160px; height: 6px; background: rgba(255,255,255,0.1); border-radius: 50px; overflow: hidden; }
.v3-grand-progress-fill { height: 100%; background: linear-gradient(90deg, #facc15, #fbbf24); border-radius: 50px; transition: width 0.6s ease; }

/* Net Profit Special Card */
.v3-profit-card {
  background: linear-gradient(135deg, #064e3b 0%, #047857 100%);
  padding: 24px 28px;
  border-radius: 20px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 16px;
  margin-bottom: 28px;
  box-shadow: 0 8px 32px rgba(4, 120, 87, 0.25);
}
.v3-profit-left { display: flex; align-items: center; gap: 16px; }
.v3-profit-left i { width: 48px; height: 48px; background: rgba(250, 204, 21, 0.15); border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 22px; color: #facc15; }
.v3-profit-left div { display: flex; flex-direction: column; }
.v3-profit-label { font-size: 0.75rem; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 800; margin-bottom: 4px; }
.v3-profit-value { font-size: 1.8rem; font-weight: 900; }
.v3-profit-right { display: flex; flex-direction: column; align-items: flex-end; gap: 8px; }
.v3-profit-chip { background: rgba(255,255,255,0.08); padding: 4px 14px; border-radius: 50px; font-size: 0.7rem; color: #cbd5e1; font-weight: 700; }
.v3-profit-detail { font-size: 0.75rem; color: #94a3b8; }

.v3-warning-box { background: #fff7ed; border: 1px solid #fed7aa; color: #9a3412; border-radius: 12px; padding: 12px 16px; margin-bottom: 16px; font-size: 0.85rem; }
.v3-stock-section { margin-bottom: 24px; margin-top: 24px; }
.v3-stock-header { display: flex; align-items: center; gap: 10px; margin-bottom: 14px; }
.v3-stock-header h5 { margin: 0; font-size: 0.85rem; font-weight: 800; color: var(--rv-red); text-transform: uppercase; letter-spacing: 0.5px; }
.v3-stock-count { background: var(--rv-red); color: #fff; padding: 2px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 800; }
.v3-stock-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 12px; }
.v3-stock-card { border-color: #fecaca; border-radius: 16px; padding: 16px 18px; display: flex; align-items: center; gap: 14px; transition: all 0.25s ease; position: relative; overflow: hidden; }
.v3-stock-card::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 4px; background: #ef4444; }
.v3-stock-icon { width: 44px; height: 44px; background: #fef2f2; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 18px; color: var(--rv-red); flex-shrink: 0; }
.v3-stock-info { flex: 1; min-width: 0; }
.v3-stock-name { font-size: 0.95rem; font-weight: 800; color: var(--rv-text); margin: 0 0 4px; display: block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.v3-stock-meta { display: flex; align-items: center; gap: 8px; font-size: 0.75rem; color: var(--rv-muted); }
.v3-stock-bar { width: 60px; height: 6px; background: #f1f5f9; border-radius: 50px; overflow: hidden; }
.v3-stock-bar-fill { height: 100%; background: #ef4444; border-radius: 50px; transition: width 0.4s ease; }
.v3-stock-qty { background: #fef2f2; color: var(--rv-red); padding: 3px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 900; flex-shrink: 0; }

/* Modal Styles */
.rv-modal-open { overflow: hidden; }
.rv-modal { position: fixed; inset: 0; z-index: 20000; display: none; align-items: center; justify-content: center; padding: 22px; background: rgba(2, 6, 23, 0.66); backdrop-filter: blur(8px); }
.rv-modal.rv-show { display: flex; }
.rv-modal-card { width: min(1280px, 98vw); height: min(900px, 94vh); background: #ffffff; border-radius: 24px; overflow: hidden; display: flex; flex-direction: column; box-shadow: 0 34px 90px rgba(0,0,0,0.42); border: 1px solid rgba(255,255,255,0.24); }
.rv-modal-card.rv-modal-lg { width: min(980px, 96vw); }
.rv-modal-card.rv-modal-xl { width: min(1280px, 98vw); }
.rv-modal-card.rv-modal-wide { width: min(1540px, 99vw); height: min(940px, 96vh); }
.rv-modal-head { min-height: 62px; display: flex; align-items: center; justify-content: space-between; gap: 14px; padding: 14px 18px 14px 22px; background: linear-gradient(135deg, #052e16, #14532d 62%, #166534); color: #ffffff; }
.rv-modal-title-wrap { display: flex; align-items: center; gap: 10px; min-width: 0; }
.rv-modal-title-wrap i { width: 36px; height: 36px; display: flex; align-items: center; justify-content: center; border-radius: 12px; background: rgba(255,255,255,0.14); color: #fde68a; }
.rv-modal-title { font-size: 1rem; font-weight: 900; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.rv-modal-close { width: 38px; height: 38px; border: 0; border-radius: 12px; background: rgba(255,255,255,0.16); color: #ffffff; cursor: pointer; font-size: 18px; transition: all 0.2s ease; }
.rv-modal-close:hover { background: rgba(239,68,68,0.9); }
.rv-modal-body { position: relative; flex: 1; background: #f8fafc; }
.rv-modal-loader { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; color: #166534; font-weight: 800; background: #f8fafc; z-index: 1; }
.rv-modal-frame { position: relative; z-index: 2; width: 100%; height: 100%; border: 0; background: #ffffff; }

/* Payment Modal Styles */
.rv-pay-overlay { position: fixed; inset: 0; z-index: 25000; display: none; align-items: center; justify-content: center; padding: 18px; background: rgba(2, 6, 23, 0.55); }
.rv-pay-overlay.rv-pay-show { display: flex; }
.rv-pay-card { width: min(672px, 96vw); background: #ffffff; border-radius: 6px; box-shadow: 0 24px 72px rgba(0,0,0,0.36); overflow: hidden; font-family: 'Segoe UI', system-ui, sans-serif; }
.rv-pay-head { height: 48px; padding: 0 20px; background: #27983a; color: #fff; display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.rv-pay-head.rv-pay-orange { background: #0f766e; }
.rv-pay-title { font-size: 1.45rem; font-weight: 900; letter-spacing: 0.2px; }
.rv-pay-close { border: 0; background: transparent; color: rgba(0,0,0,0.52); font-size: 22px; line-height: 1; cursor: pointer; padding: 4px; }
.rv-pay-body { padding: 16px 12px 8px; }
.rv-pay-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.rv-pay-group { margin-bottom: 10px; }
.rv-pay-group.rv-full { grid-column: 1 / -1; }
.rv-pay-label { display: block; margin-bottom: 5px; font-size: 1rem; line-height: 1.1; color: #27272a; font-weight: 900; }
.rv-pay-label .req { color: #e11d48; }
.rv-pay-input, .rv-pay-select, .rv-pay-textarea { width: 100%; border: 1px solid #cfd6dc; border-radius: 4px; background: #ffffff; padding: 8px 14px; font-size: 1rem; color: #3f3f46; outline: none; }
.rv-pay-input, .rv-pay-select { height: 43px; }
.rv-pay-textarea { min-height: 72px; resize: vertical; }
.rv-pay-input:focus, .rv-pay-select:focus, .rv-pay-textarea:focus { border-color: #22c55e; box-shadow: 0 0 0 3px rgba(34,197,94,0.12); }
.rv-pay-input[readonly] { background: #e9ecef; }
.rv-native-select { position: absolute !important; left: -10000px !important; width: 1px !important; height: 1px !important; opacity: 0 !important; pointer-events: none !important; }
.rv-live-select { position: relative; width: 100%; z-index: 1; }
.rv-live-select.rv-live-open { z-index: 20; }
.rv-live-toggle { width: 100%; min-height: 45px; border: 1px solid #cfd6dc; border-radius: 4px; background: #ffffff; color: #27272a; padding: 8px 12px; display: flex; align-items: center; justify-content: space-between; gap: 10px; font-size: 1rem; font-weight: 700; cursor: pointer; text-align: left; }
.rv-live-toggle:focus, .rv-live-select.rv-live-open .rv-live-toggle { border-color: #22c55e; box-shadow: 0 0 0 3px rgba(34,197,94,0.12); }
.rv-live-value { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: #3f3f46; }
.rv-live-value.rv-placeholder { color: #8a8f98; }
.rv-live-panel { display: none; position: absolute; top: calc(100% + 4px); left: 0; right: 0; background: #ffffff; border: 1px solid #cbd5e1; border-radius: 8px; box-shadow: 0 18px 42px rgba(15,23,42,0.22); overflow: hidden; }
.rv-live-select.rv-live-open .rv-live-panel { display: block; }
.rv-live-search { position: relative; padding: 8px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; }
.rv-live-search i { position: absolute; left: 20px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 13px; }
.rv-live-input { width: 100%; height: 40px; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 12px 8px 34px; outline: none; font-size: 0.95rem; }
.rv-live-input:focus { border-color: #22c55e; box-shadow: 0 0 0 3px rgba(34,197,94,0.10); }
.rv-live-list { max-height: 245px; overflow-y: auto; padding: 6px; }
.rv-live-option { width: 100%; border: 0; background: #ffffff; border-radius: 7px; padding: 9px 10px; display: flex; align-items: center; justify-content: space-between; gap: 10px; cursor: pointer; text-align: left; font-family: inherit; }
.rv-live-option:hover, .rv-live-option.rv-active { background: #ecfdf5; }
.rv-live-option-name { display: block; font-size: 0.95rem; font-weight: 800; color: #0f172a; }
.rv-live-option-due { white-space: nowrap; font-size: 0.82rem; font-weight: 900; color: #16a34a; }
.rv-live-option[data-due-zero="1"] .rv-live-option-due { color: #94a3b8; }
.rv-live-no-result { display: none; padding: 14px 12px; color: #ef4444; font-weight: 800; text-align: center; }
.rv-live-error .rv-live-toggle { border-color: #ef4444; box-shadow: 0 0 0 3px rgba(239,68,68,0.12); }
.rv-pay-hint { display: block; margin-top: 5px; color: #64748b; font-size: 0.78rem; font-weight: 700; }
.rv-pay-actions { display: flex; align-items: center; justify-content: center; gap: 6px; padding: 0 12px 8px; }
.rv-pay-btn { border: 0; border-radius: 999px; padding: 8px 28px; font-size: 1rem; font-weight: 800; color: #fff; cursor: pointer; min-width: 136px; }
.rv-pay-save { background: #22a538; }
.rv-pay-print { background: #1685d9; }
.rv-dashboard-alert { margin: 0 0 16px; border-radius: 12px; padding: 12px 16px; font-weight: 800; border-left: 4px solid; }
.rv-dashboard-alert.success { background: #dcfce7; color: #166534; border-color: #22c55e; }
.rv-dashboard-alert.error { background: #fee2e2; color: #991b1b; border-color: #ef4444; }

@media(max-width: 1200px) {
  .v3-summary-row, .v3-source-row, .v3-top-row { grid-template-columns: repeat(2, 1fr); }
  .v3-menu { grid-template-columns: repeat(4, 1fr); }
  .v3-stock-grid { grid-template-columns: repeat(3, 1fr); }
}
@media(max-width: 767px) {
  .v3-main { padding: 14px; }
  .v3-fixed-actionbar { margin: -14px -14px 18px; padding: 10px 14px; }
  .v3-fixed-actionbar-inner { align-items: flex-start; flex-direction: column; gap: 10px; }
  .v3-fixed-actions { width: 100%; }
  .v3-fixed-btn { padding: 8px 12px; font-size: 0.78rem; }
  .v3-header { padding: 16px 20px; }
  .v3-header-text h4 { font-size: 1.2rem; }
  .v3-summary-row, .v3-source-row, .v3-balance-row, .v3-mid-row, .v3-top-row { grid-template-columns: 1fr; }
  .v3-menu { grid-template-columns: repeat(2, 1fr); }
  .v3-stock-grid { grid-template-columns: 1fr; }
  .v3-bar-item { grid-template-columns: 1fr; gap: 6px; }
  .v3-grand, .v3-profit-card { flex-direction: column; text-align: center; }
  .v3-grand-right, .v3-profit-right { align-items: center; }
  .rv-pay-row { grid-template-columns: 1fr; gap: 0; }
  .rv-pay-title { font-size: 1.2rem; }
  .rv-pay-actions { flex-direction: column; }
  .rv-pay-btn { width: 100%; }
  .rv-modal { padding: 10px; }
  .rv-modal-card { width: 100%; height: 94vh; border-radius: 18px; }
  .rv-modal-head { padding: 12px 14px; }
}
</style>
