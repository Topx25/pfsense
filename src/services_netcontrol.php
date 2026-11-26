<?php
/*
 * services_netcontrol.php  —  Network Control
 *
 * Full console: devices, groups, schedules, site blocking, video control,
 * blocked-attempt log and stale-MAC cleanup.
 *
 * Identity is MAC-first: every rule is stored against a MAC and re-applied to
 * whatever IP that MAC currently holds, so a DHCP change never orphans a rule
 * or hits the wrong device. A randomised (locally administered) MAC cannot be
 * matched reliably, so those are pinned by IP and flagged as the weaker case.
 */

require_once("guiconfig.inc");
require_once("/usr/local/pkg/netcontrol.inc");

$pgtitle = array(gettext("Services"), gettext("Network Control"));
include("head.inc");

$store = nc_store_load();
function nc_h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>

<style>
#nc{--line:#e8edf3;--soft:#f5f8fb;--dim:#64748b;--faint:#94a3b8;
    --ink:#0f172a;
    --ok:#059669;--okb:#d1fae5;--warn:#b45309;--warnb:#fef3c7;
    --crit:#dc2626;--critb:#fee2e2;--acc:#2563eb;--accb:#dbeafe;
    --card:#ffffff;--bg:#f1f5f9;--head:#f8fafc;
    --r:14px;--rs:10px;
    --sh:0 1px 2px rgba(15,23,42,.04),0 1px 3px rgba(15,23,42,.06);
    --shc:0 1px 3px rgba(15,23,42,.05),0 4px 12px rgba(15,23,42,.05);
    --mono:ui-monospace,Menlo,Consolas,monospace}

/* The page sits on a tinted background so the white cards read as cards. */
#nc{background:var(--bg);padding:18px;border-radius:var(--r);color:var(--ink)}
#nc .panel,#nc .panel-default{border:0;border-radius:var(--r);
  box-shadow:var(--shc);background:var(--card);overflow:hidden}
#nc .panel-heading{background:var(--card);border-bottom:1px solid var(--line);
  padding:14px 18px}
#nc .panel-title{font-size:14px;font-weight:700;color:var(--ink);margin:0}
#nc .panel-body{padding:18px}

/* ---- page header ------------------------------------------------- */
#nc .nc-hd{display:flex;align-items:center;gap:14px;flex-wrap:wrap;
  margin-bottom:18px}
#nc .nc-hd .ic{width:46px;height:46px;border-radius:12px;flex:none;
  display:flex;align-items:center;justify-content:center;font-size:20px;
  background:linear-gradient(135deg,var(--acc),#4f46e5);color:#fff;
  box-shadow:0 4px 12px rgba(37,99,235,.28)}
#nc .nc-hd h1{font-size:21px;font-weight:800;margin:0 0 2px;color:var(--ink)}
#nc .nc-hd .sub{font-size:12.5px;color:var(--dim)}
#nc .nc-hd .sp-r{margin-inline-start:auto;display:flex;gap:9px;flex-wrap:wrap}
#nc .nc-bar{display:flex;align-items:center;justify-content:flex-end;
  gap:9px;flex-wrap:wrap;margin-bottom:10px}
#nc .nc-bar .sp-r{display:flex;gap:9px;flex-wrap:wrap;align-items:center}
#nc .nc-note{background:var(--accb);border:1px solid rgba(37,99,235,.2);
  border-radius:var(--rs);padding:12px 16px;margin-bottom:14px;font-size:13px;
  color:#1e40af}
#nc .al{display:flex;gap:11px;align-items:center;flex-wrap:wrap;padding:13px 16px;
  margin-bottom:10px;border-radius:var(--rs);background:var(--warnb);
  border:1px solid rgba(180,83,9,.18);
  border-inline-start:3px solid var(--warn);font-size:13px;
  box-shadow:var(--sh)}
#nc .al.crit{background:var(--critb);border-left-color:var(--crit)}
#nc .al.info{background:var(--accb);border-left-color:var(--acc)}
#nc .al .grow{flex:1;min-width:180px}
#nc .al-t{margin-bottom:3px}
#nc .al-id{display:flex;gap:5px;flex-wrap:wrap;align-items:center;
  font-family:var(--mono);font-size:11.5px;color:var(--dim)}
#nc .al-n{font-weight:600;font-family:inherit;font-size:12.5px;color:#333}

#nc .sp{display:grid;grid-template-columns:repeat(auto-fit,minmax(184px,1fr));
  gap:13px;background:none;border:0;margin-bottom:16px;overflow:visible}
#nc .spc{background:var(--card);padding:15px 17px;border-radius:var(--r);
  box-shadow:var(--sh);border:1px solid var(--line);
  transition:box-shadow .16s,transform .16s}
#nc .spc:hover{box-shadow:var(--shc);transform:translateY(-1px)}
#nc .spc.src{background:var(--card)}
/* the big number */
#nc .spc .v,#nc .spc b{font-size:25px;font-weight:800;color:var(--ink);
  letter-spacing:-.6px;font-variant-numeric:tabular-nums}
#nc .spc .u{font-size:12px;font-weight:600;color:var(--dim);margin-inline-start:3px}
#nc .spc .l{font-size:12px;color:var(--dim);font-weight:600;margin-bottom:7px;
  display:flex;align-items:center;gap:6px}
#nc .spc .l .i{width:22px;height:22px;border-radius:7px;flex:none;
  display:flex;align-items:center;justify-content:center;font-size:11px;
  background:var(--accb);color:var(--acc)}
/* the thin progress bar under a tile */
#nc .spc .bar{height:6px;border-radius:99px;background:var(--soft);
  margin-top:9px;overflow:hidden}
#nc .spc .bar>i{display:block;height:100%;border-radius:99px;
  background:linear-gradient(90deg,var(--ok),#34d399);transition:width .4s}
#nc .spc .bar.hot>i{background:linear-gradient(90deg,var(--crit),#f87171)}
#nc .spc .bar.warm>i{background:linear-gradient(90deg,var(--warn),#fbbf24)}
#nc .spc .l{font-size:10px;color:var(--faint);text-transform:uppercase;letter-spacing:.04em}
#nc .spc .v{font-size:19px;font-weight:600;font-family:var(--mono);
  font-variant-numeric:tabular-nums;margin-top:2px;line-height:1.15}
#nc .spc .v small{font-size:10px;color:var(--faint);font-weight:400}
#nc .spc .s{font-size:10.5px;color:var(--dim);margin-top:2px;font-family:var(--mono)}
#nc .trk{height:5px;background:var(--soft);border-radius:3px;overflow:hidden;margin-top:5px}
#nc .trk i{display:block;height:100%;border-radius:3px;transition:width .4s}
#nc .spark{display:flex;align-items:flex-end;gap:2px;height:22px;margin-top:6px}
#nc .spark i{flex:1;background:var(--acc);opacity:.4;border-radius:1px;min-height:2px}
#nc .spark i:last-child{opacity:1}

#nc .tabs{display:flex;overflow-x:auto;border-bottom:2px solid var(--line);margin-bottom:0}
#nc .tabs button{border:0;background:none;padding:9px 14px;font-size:13px;color:var(--dim);
  cursor:pointer;white-space:nowrap;border-bottom:2px solid transparent;margin-bottom:-2px}
#nc .tabs button.on{color:var(--acc);border-bottom-color:var(--acc);font-weight:600}
#nc .tabs .c{font-family:var(--mono);font-size:10.5px;background:var(--soft);
  border-radius:9px;padding:1px 6px;margin-left:4px}

#nc .bar{display:flex;gap:7px;align-items:center;flex-wrap:wrap;padding:9px 0}
#nc .bar input[type=search],#nc .bar input[type=text]{padding:5px 9px;border:1px solid var(--line);
  border-radius:4px;font-size:13px;min-width:130px}
#nc .bar input[type=search]{flex:1}
#nc .seg{display:flex;border:1px solid var(--line);border-radius:4px;overflow:hidden}
#nc .seg button{border:0;background:#fff;padding:5px 10px;font-size:12px;color:var(--dim);
  cursor:pointer;border-right:1px solid var(--line);white-space:nowrap}
#nc .seg button:last-child{border-right:0}
#nc .seg button.on{background:var(--acc);color:#fff;font-weight:600}

#nc table{width:100%;border-collapse:collapse;font-size:12.5px}
#nc th{font-size:10px;text-transform:uppercase;color:var(--faint);letter-spacing:.04em;
  padding:7px 8px;text-align:left;border-bottom:1px solid var(--line);background:#fafbfc}
#nc td{padding:6px 8px;border-bottom:1px solid var(--soft);vertical-align:middle}
#nc tr.heavy{background:#fff7df}
#nc tr.heavy td:first-child{box-shadow:inset 3px 0 0 #b45309}
#nc tr.heavy td.num.hot{color:#92400e}
#nc .pl.mac-conflict{background:#fff0c9;color:#84410a}
#nc tr.blk{background:var(--critb);opacity:.78}
#nc tr.blk .n{text-decoration:line-through}
#nc tr.sel{background:var(--accb)}
#nc tr.flash{animation:ncf .5s ease 3}
@keyframes ncf{0%,100%{background:var(--critb)}50%{background:var(--warnb)}}
#nc .id{min-width:210px}
#nc .id .n{font-weight:600;line-height:1.3}
#nc .id .m{font-family:var(--mono);font-size:10.5px;color:var(--dim);margin-top:1px;
  white-space:nowrap}
#nc .id .m .r{color:var(--warn)}
#nc .ty{font-size:12px;white-space:nowrap;line-height:1.3}

/* new-device policy */
#nc .pol{border:1px solid var(--line);border-radius:var(--r);overflow:hidden;
  margin-bottom:16px;box-shadow:var(--sh);background:var(--card)}
#nc .polh{display:flex;align-items:center;gap:12px;padding:14px 18px;
  background:var(--head);border-bottom:1px solid var(--line);flex-wrap:wrap}
#nc .polh.on{background:var(--critb);
  border-bottom-color:color-mix(in srgb,var(--crit) 20%,transparent)}
#nc .polh h4{margin:0;font-size:14px;font-weight:700}
#nc .polh .sub{font-size:11.5px;color:var(--dim);flex:1;min-width:140px}
#nc .polb{padding:0}

/* every setting is one row: a fixed label column, then its control */
#nc .polf{display:grid;grid-template-columns:118px 1fr;gap:14px;
  align-items:start;padding:14px 18px;border-bottom:1px solid var(--line)}
#nc .polf:last-child{border-bottom:0}
#nc .polf > label,#nc .polf > .fl{font-size:12px;color:var(--dim);
  font-weight:600;padding-top:6px}
#nc .polf .fv{min-width:0}
#nc .polf .hint{font-size:11px;color:var(--faint);margin-top:7px;
  line-height:1.5}
#nc .polf .hint b{color:var(--dim)}
#nc .polr{display:contents}

/* a switch row puts the control first, then its own explanation */
#nc .polf.sw-row{grid-template-columns:118px 1fr}
#nc .polf.sw-row .swlab{display:flex;align-items:center;gap:9px;
  font-size:12.5px;font-weight:600;color:var(--ink);cursor:pointer}

#nc .chips{display:flex;gap:6px;flex-wrap:wrap}
#nc .chip{display:inline-flex;align-items:center;padding:6px 13px;
  border-radius:99px;font-size:12px;font-weight:600;cursor:pointer;
  background:var(--card);border:1px solid var(--line);color:var(--dim);
  transition:background .13s,color .13s,border-color .13s}
#nc .chip:hover{background:var(--head);color:var(--ink)}
#nc .chip.on{background:var(--acc);color:#fff;border-color:var(--acc)}

/* the speed slider belongs to the "limit" action and hides with it */
#nc .limrow{display:flex;align-items:center;gap:11px;margin-top:10px;
  padding-top:10px;border-top:1px dashed var(--line)}
#nc .limrow input[type=range]{width:150px;accent-color:var(--acc)}
#nc .limrow .num{font-family:var(--mono);font-size:12.5px;font-weight:700;
  background:var(--soft);border:1px solid var(--line);border-radius:8px;
  padding:4px 11px;font-variant-numeric:tabular-nums}

#nc .schrow{display:flex;gap:9px;align-items:center;flex-wrap:wrap;
  margin-top:10px;padding-top:10px;border-top:1px dashed var(--line)}
#nc .schrow .lbl{font-size:11.5px;color:var(--dim);font-weight:600}
#nc .schrow input[type=time]{font-family:var(--mono);font-size:12.5px;
  border:1px solid var(--line);border-radius:8px;padding:6px 10px;
  background:var(--card);color:var(--ink)}
#nc .schrow input[type=time]:focus{outline:0;border-color:var(--acc);
  box-shadow:0 0 0 3px color-mix(in srgb,var(--acc) 15%,transparent)}

#nc .polprev{border:1px solid var(--line);border-radius:var(--r);
  overflow:hidden;margin-bottom:16px;box-shadow:var(--sh);background:var(--card)}
#nc .polprev .ph{padding:12px 18px;background:var(--head);font-size:12px;
  color:var(--dim);border-bottom:1px solid var(--line)}
#nc .polprev .ph b{color:var(--ink)}
#nc .polw{background:var(--warnb);border:1px solid
  color-mix(in srgb,var(--warn) 25%,transparent);border-radius:var(--rs);
  padding:12px 16px;font-size:12.5px;line-height:1.7;margin-bottom:16px}
#nc .polw b{color:var(--warn)}
#nc input[type=time]{padding:4px 7px;border:1px solid var(--line);
  border-radius:4px;font-size:12px;font-family:var(--mono)}

/* live traffic - device cards */
#nc .lv-note{font-size:12px;color:var(--dim)}
#nc .dvc{border:1px solid var(--soft);border-radius:5px;margin-bottom:7px;
  background:#fff;overflow:hidden}
#nc .dvc.blk{border-color:var(--crit);background:var(--critb)}
#nc .dvh{display:grid;grid-template-columns:1fr auto auto;gap:12px;
  align-items:center;padding:9px 11px;cursor:pointer}
#nc .dvh:hover{background:#fafbfc}
#nc .dvi{min-width:0;flex:1}
#nc .dvh .l1{display:flex;align-items:center;gap:7px;flex-wrap:wrap;min-width:0}
#nc .dvh .l1 .nm{font-weight:700;font-size:13px;white-space:nowrap;
  overflow:hidden;text-overflow:ellipsis;max-width:100%}
#nc .dvh .l1 .host{display:inline;margin-top:0;margin-inline-start:2px}
#nc .dvi .l1{display:flex;align-items:center;gap:6px;flex-wrap:wrap;line-height:1.35}
#nc .dvi .nm{font-weight:600;font-size:13.5px}
#nc .dvi .l2{font-family:var(--mono);font-size:10.5px;color:var(--dim);
  margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
#nc .dvi .l2 .r{color:var(--warn)}
#nc .dvm{display:grid;grid-template-columns:repeat(4, 62px);gap:8px;
  align-items:center;white-space:nowrap}
#nc .dvm .k{text-align:center;min-width:0}
#nc .dvm .k .v{font-family:var(--mono);font-size:12.5px;font-weight:700;
  font-variant-numeric:tabular-nums;overflow:hidden;text-overflow:ellipsis;
  font-variant-numeric:tabular-nums;line-height:1.2}
#nc .dvm .k .t{text-transform:none;letter-spacing:0;font-size:9.5px;color:var(--faint);text-transform:uppercase}
#nc .dva{display:flex;gap:5px}
#nc .dvs{padding:0 11px 9px;border-top:1px dashed var(--soft)}
#nc .svr{display:grid;grid-template-columns:1fr 90px auto auto;gap:9px;
  align-items:center;padding:6px 0;border-bottom:1px solid var(--soft);font-size:12px}
#nc .svr:last-child{border-bottom:0}
#nc .svr .sn{min-width:0}
#nc .svr .sn b{font-weight:600}
#nc .svr .sn .hh{font-family:var(--mono);font-size:10px;color:var(--faint);
  display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
#nc .svr .sb{height:4px;background:var(--soft);border-radius:2px;overflow:hidden}
#nc .svr .sb i{display:block;height:100%;background:var(--acc)}
#nc .svr .sm{font-family:var(--mono);font-size:10.5px;color:var(--dim);
  white-space:nowrap;text-align:left}
#nc .chev{transition:transform .15s;color:var(--faint);font-size:11px}
#nc .dvc.open .chev{transform:rotate(90deg)}
#nc .lv{display:flex;flex-direction:column;gap:1px;background:var(--soft);
  border:1px solid var(--soft);border-radius:5px;overflow:hidden}
#nc .lvr{background:#fff;padding:9px 11px;display:grid;
  grid-template-columns:1fr auto auto auto;gap:10px;align-items:center}
#nc .lvr:hover{background:#fafbfc}
#nc .lvn{min-width:0}
#nc .lvn .s{font-weight:600;font-size:13px}
#nc .lvn .h{font-family:var(--mono);font-size:10.5px;color:var(--faint);
  overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
#nc .lvbar{width:110px;height:5px;background:var(--soft);border-radius:3px;overflow:hidden}
#nc .lvbar i{display:block;height:100%;background:var(--acc);border-radius:3px}
#nc .lvc{font-family:var(--mono);font-size:11.5px;color:var(--dim);
  white-space:nowrap;text-align:right;min-width:74px}
#nc .lvd{display:flex;flex-wrap:wrap;gap:4px;padding:7px 11px 9px 11px;background:#fafbfc;
  border-top:1px dashed var(--soft)}
#nc .lvd .dv{font-size:10.5px;font-family:var(--mono);background:#fff;
  border:1px solid var(--soft);border-radius:3px;padding:1px 6px;color:var(--dim)}
#nc .ty .vn{font-size:10.5px;color:var(--faint);margin-top:1px}

/* global per-device ceiling */
#nc .glim{border:1px solid var(--line);border-radius:5px;background:#fafbfc;
  padding:11px 13px;margin-bottom:12px}
#nc .glim .gh{display:flex;align-items:baseline;justify-content:space-between;
  gap:10px;flex-wrap:wrap;margin-bottom:9px}
#nc .glim h4{margin:0;font-size:13px;font-weight:600}
#nc .glim .sub{font-size:11.5px;color:var(--dim)}
#nc .glim .gr{display:flex;gap:16px;align-items:flex-end;flex-wrap:wrap}
#nc .gf{display:flex;flex-direction:column;gap:4px;min-width:150px;flex:1}
#nc .gf label{font-size:11px;color:var(--dim)}
#nc .gf .gin{display:flex;align-items:center;gap:8px}
#nc .gf input[type=range]{flex:1;min-width:80px;height:3px}
#nc .gf .gv{font-family:var(--mono);font-size:15px;font-weight:600;
  min-width:62px;text-align:right}
#nc .gf .gv small{font-size:10px;color:var(--faint);font-weight:400}
#nc .gf .ghint{font-size:10.5px;color:var(--faint)}
#nc .gf .ghint.warn{color:var(--warn)}
#nc .gf .ghint.crit{color:var(--crit)}
#nc .ty .vn{font-size:10.5px;color:var(--faint);margin-top:1px}
#nc .num{font-family:var(--mono);font-variant-numeric:tabular-nums;white-space:nowrap}
#nc .num.hot{color:var(--crit);font-weight:700}
#nc .tg{display:inline-block;font-size:9.5px;font-weight:600;padding:0 5px;
  border-radius:3px;background:var(--soft);color:var(--dim);white-space:nowrap;
  line-height:1.6;vertical-align:1px}
#nc .tg.mac{background:var(--okb);color:var(--ok)}
#nc .tg.ipb{background:var(--warnb);color:var(--warn)}
#nc .tg.prot{background:var(--accb);color:var(--acc)}
#nc .tg.tmp{background:var(--accb);color:var(--acc)}
#nc .tg.hot{background:var(--crit);color:#fff}
/* ---- aligned grid tables -------------------------------------- */
#nc table.ncgrid{table-layout:fixed;width:100%;border-collapse:separate;
  border-spacing:0;background:var(--card)}
#nc table.ncgrid th,#nc table.ncgrid td{
  padding:13px 12px;vertical-align:middle;
  border-bottom:1px solid var(--line);
  overflow:hidden;text-overflow:ellipsis}
#nc table.ncgrid thead th{
  position:sticky;top:0;z-index:2;background:var(--head);
  font-size:11.5px;font-weight:700;letter-spacing:.2px;color:var(--dim);
  white-space:nowrap;border-bottom:1px solid var(--line)}
#nc table.ncgrid tbody tr{transition:background .13s}
#nc table.ncgrid tbody tr:hover{background:var(--head)}
#nc table.ncgrid tbody tr:last-child td{border-bottom:0}
/* per-column alignment */
#nc table.ncgrid th.s{text-align:right}
#nc table.ncgrid th.c{text-align:center}
#nc table.ncgrid th.num{text-align:left}
#nc table.ncgrid td{text-align:right}
#nc table.ncgrid td.c,#nc table.ncgrid td:first-child{text-align:center}
#nc table.ncgrid td.num{text-align:left}
/* digits line up in a column instead of drifting */
#nc table.ncgrid td,#nc table.ncgrid .m,#nc table.ncgrid .num{
  font-variant-numeric:tabular-nums;font-feature-settings:"tnum" 1}
#nc table.ncgrid .m{
  font-family:var(--mono);font-size:11.5px;color:var(--dim);
  direction:ltr;unicode-bidi:embed;text-align:right;white-space:nowrap;
  overflow:hidden;text-overflow:ellipsis}
#nc .host{display:block;font-family:var(--mono);font-size:10.5px;
  color:var(--faint);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
  margin-top:1px;direction:ltr;unicode-bidi:embed;text-align:right}
#nc .ren{border:0;background:none;color:var(--faint);cursor:pointer;
  padding:1px 3px;border-radius:5px;font-size:10px;line-height:1;opacity:0;
  flex:none;transition:opacity .13s,background .13s,color .13s}
#nc tr:hover .ren,#nc .ren:focus-visible{opacity:1}
#nc .ren:hover{background:var(--accb);color:var(--acc)}
#nc .nm input{font-family:inherit;font-size:13px;font-weight:600;width:100%;
  min-width:0;border:1px solid var(--acc);border-radius:7px;padding:3px 7px;
  background:var(--card);color:var(--ink)}
#nc .nm input:focus{outline:0;box-shadow:0 0 0 3px rgba(37,99,235,.15)}
#nc table.ncgrid td .nm{
  display:flex;align-items:center;gap:5px;min-width:0;
  font-weight:700;color:var(--ink);font-size:13px}
/* the text itself is what clips, not the row that holds the pencil */
#nc table.ncgrid td .nm>span.t{white-space:nowrap;overflow:hidden;
  text-overflow:ellipsis;min-width:0}
#nc table.ncgrid td:last-child{white-space:nowrap;overflow:visible}
#nc table.ncgrid td:last-child>*{display:inline-flex;gap:6px;
  justify-content:center;white-space:nowrap}
#nc table.ncgrid td:last-child .btn,
#nc table.ncgrid td:last-child button{margin:0 2px}
#nc table.ncgrid select{max-width:100%;box-sizing:border-box}
#nc .twrap{overflow-x:auto;max-height:70vh;overflow-y:auto;
  border-radius:var(--rs);border:1px solid var(--line)}

/* the small square glyph in front of a device name, as in the mockup */
#nc .dvbadge{width:30px;height:30px;border-radius:9px;flex:none;
  display:inline-flex;align-items:center;justify-content:center;
  background:var(--accb);color:var(--acc);font-size:13px}

/* the table needs room for its fixed columns before it starts scrolling */
#nc table.ncgrid{min-width:1070px}
#nc table.ncgrid tbody tr.blk{background:rgba(220,38,38,.035)}
#nc table.ncgrid tbody tr.blk:hover{background:rgba(220,38,38,.06)}
#nc table.ncgrid tbody tr.heavy{box-shadow:inset 3px 0 0 #b45309}
#nc table.ncgrid tbody tr.sel{background:var(--accb)}
#nc .empty{text-align:center;color:var(--faint);padding:34px 12px;font-size:13px}
#nc tr.flash{animation:ncfl 1.6s ease-out}
@keyframes ncfl{0%,40%{background:var(--warnb)}100%{background:transparent}}

/* ---- login + usage history --------------------------------------- */
#nc .hrow{display:flex;align-items:center;gap:13px;padding:11px 16px;
  border-bottom:1px solid var(--line);flex-wrap:wrap}
#nc .hrow:last-child{border-bottom:0}
#nc .hrow:hover{background:var(--head)}
#nc .hrow.kick{background:rgba(220,38,38,.03)}
#nc .hmeta{flex:1;min-width:150px}
#nc .hn{font-weight:700;font-size:13px;display:flex;gap:6px;align-items:center;
  flex-wrap:wrap}
#nc .hw{font-size:11px;color:var(--dim);margin-top:3px;display:flex;gap:8px;
  flex-wrap:wrap}
#nc .hw .m{display:inline;font-size:10.5px}
#nc .hb{font-size:14px;font-weight:700;min-width:62px;text-align:left;
  font-variant-numeric:tabular-nums}
#nc .hb i{display:block;font-style:normal;font-size:10px;color:var(--faint);
  font-weight:400;margin-top:2px}
#nc .ht{font-family:var(--mono);font-size:10.5px;color:var(--faint);
  min-width:92px;white-space:nowrap}
#nc .hday{border-bottom:2px solid var(--line)}
#nc .hday:last-child{border-bottom:0}
#nc .hdh{display:flex;align-items:center;gap:10px;padding:10px 16px;
  background:var(--head);font-size:12.5px;border-bottom:1px solid var(--line)}
#nc .hdh span{margin-inline-start:auto;color:var(--dim);font-size:11.5px}

/* ---- the action log --------------------------------------------- */
#nc .logbox{max-height:62vh;overflow-y:auto;font-size:11.5px}
#nc .lg{display:grid;grid-template-columns:132px 92px 118px 1fr;gap:10px;
  padding:9px 16px;border-bottom:1px solid var(--line);align-items:baseline}
#nc .lg:last-child{border-bottom:0}
#nc .lg:hover{background:var(--head)}
#nc .lg .t{font-family:var(--mono);color:var(--faint);font-size:10.5px;
  white-space:nowrap}
#nc .lg .w{font-weight:600;color:var(--dim);white-space:nowrap;
  overflow:hidden;text-overflow:ellipsis}
#nc .lg .f{font-family:var(--mono);color:var(--faint);font-size:10.5px;
  direction:ltr;unicode-bidi:embed;text-align:right;white-space:nowrap;
  overflow:hidden;text-overflow:ellipsis}
#nc .lg .m{font-family:inherit;font-size:12px;color:var(--ink);
  direction:rtl;text-align:right;white-space:normal;word-break:break-word}
#nc .lg .m code{font-family:var(--mono);font-size:11px;color:var(--dim);
  background:var(--soft);padding:1px 5px;border-radius:5px;direction:ltr;
  display:inline-block}
/* the kind of action, taken from the first word of the line */
#nc .lg .k{display:inline-block;padding:1px 8px;border-radius:99px;
  font-size:10px;font-weight:700;margin-inline-end:6px;
  background:var(--soft);color:var(--dim)}
#nc .lg.blk .k{background:var(--critb);color:var(--crit)}
#nc .lg.unb .k{background:var(--okb);color:var(--ok)}
#nc .lg.sys .k{background:var(--accb);color:var(--acc)}
#nc .lg.wrn .k{background:var(--warnb);color:var(--warn)}
#nc .logbox .empty{padding:30px;text-align:center;color:var(--faint)}
@media (max-width:720px){
  #nc .lg{grid-template-columns:1fr;gap:3px}
  #nc .lg .f,#nc .lg .w{font-size:10px}
}

/* ---- blocked device rows ----------------------------------------- */
#nc .brow{display:flex;align-items:center;gap:13px;padding:12px 16px;
  border-bottom:1px solid var(--line);flex-wrap:wrap;
  background:rgba(220,38,38,.03)}
#nc .brow:last-child{border-bottom:0}
#nc .brow:hover{background:rgba(220,38,38,.06)}
#nc .bmeta{flex:1;min-width:170px}
#nc .bn{font-weight:700;font-size:13px;display:flex;gap:6px;align-items:center;
  flex-wrap:wrap}
#nc .bw{font-size:11px;color:var(--dim);margin-top:4px;display:flex;gap:9px;
  align-items:center;flex-wrap:wrap}
#nc .bw .m{display:inline;font-size:10.5px}
#nc .bacts{display:flex;gap:6px;flex-wrap:wrap}

/* ---- video, grouped by service ----------------------------------- */
#nc .vg{border:1px solid var(--line);border-radius:var(--rs);overflow:hidden;
  margin:10px 14px;background:var(--card)}
#nc .vg.open{box-shadow:var(--sh)}
#nc .vgh{display:flex;align-items:center;gap:12px;padding:12px 15px;
  cursor:pointer;flex-wrap:wrap;transition:background .13s}
#nc .vgh:hover{background:var(--head)}
#nc .vg.open .vgh{background:var(--head);border-bottom:1px solid var(--line)}
#nc .vgm{flex:1;min-width:130px}
#nc .vgn{font-size:13.5px;font-weight:700;display:flex;gap:7px;
  align-items:center;flex-wrap:wrap}
#nc .vgs{font-size:11px;color:var(--faint);margin-top:3px}
#nc .vgb{font-size:13px;font-weight:700;font-variant-numeric:tabular-nums;
  min-width:72px;text-align:left}
#nc .vgh .chev{color:var(--faint);font-size:11px;flex:none}
#nc .vgd{background:var(--card)}
#nc .vgd .vrow{padding:11px 15px 11px 22px}
#nc .vgd .vrow:last-child{border-bottom:0}
#nc .vh{display:inline-block;font-family:var(--mono);font-size:10px;
  color:var(--dim);background:var(--soft);border:1px solid var(--line);
  border-radius:5px;padding:1px 6px;margin-inline-end:4px;
  direction:ltr;unicode-bidi:embed;max-width:230px;overflow:hidden;
  text-overflow:ellipsis;white-space:nowrap;vertical-align:middle}
#nc .vnote{margin:12px 14px;padding:10px 13px;border-radius:var(--rs);
  background:var(--soft);border:1px solid var(--line);font-size:11.5px;
  color:var(--dim);line-height:1.7}

#nc .gotoblk{background:var(--crit);color:#fff;border:0;cursor:pointer;
  font-family:inherit;font-size:11px;font-weight:600;padding:3px 9px;
  border-radius:99px;transition:background .13s}
#nc .gotoblk:hover{background:#991b1b}

/* ---- live video rows --------------------------------------------- */
#nc .vrow{display:flex;align-items:center;gap:13px;padding:12px 16px;
  border-bottom:1px solid var(--line);flex-wrap:wrap}
#nc .vrow:last-child{border-bottom:0}
#nc .vrow:hover{background:var(--head)}
#nc .vrow.blk{background:rgba(220,38,38,.04)}
#nc .vk{width:32px;height:32px;border-radius:9px;flex:none;display:flex;
  align-items:center;justify-content:center;font-size:14px;font-weight:700;
  background:var(--soft);color:var(--dim)}
#nc .vk.youtube{background:#fee2e2;color:#dc2626}
#nc .vk.tiktok{background:#e8edf3;color:#0f172a}
#nc .vk.facebook{background:#dbeafe;color:#1d4ed8}
#nc .vk.netflix{background:#fee2e2;color:#b91c1c}
#nc .vk.instagram{background:#fce7f3;color:#be185d}
#nc .vk.twitch{background:#ede9fe;color:#6d28d9}
#nc .vmeta{flex:1;min-width:150px}
#nc .vtag{padding:2px 8px;border-radius:99px;font-size:10px;font-weight:700;
  background:var(--soft);color:var(--faint)}
#nc .vtag.stream{background:var(--critb);color:var(--crit)}
#nc .vtag.guess{background:var(--warnb);color:var(--warn)}
#nc .vn{font-weight:700;font-size:13px;display:flex;gap:7px;align-items:baseline;
  flex-wrap:wrap}
#nc .vh{font-family:var(--mono);font-size:10.5px;color:var(--faint);
  font-weight:400;direction:ltr;unicode-bidi:embed}
#nc .vw{font-size:11.5px;color:var(--dim);margin-top:3px;display:flex;gap:7px;
  align-items:center;flex-wrap:wrap}
#nc .vw .m{display:inline;font-size:10.5px}
#nc .vb{text-align:left;font-variant-numeric:tabular-nums;font-size:13px;
  font-weight:700;min-width:84px}
#nc .vb i{display:block;font-style:normal;font-size:10px;color:var(--faint);
  font-weight:400;margin-top:2px}
#nc .vacts{display:flex;gap:6px;flex-wrap:wrap}

/* ---- sortable + resizable columns -------------------------------- */
#nc table.ncgrid th[data-sort]{cursor:pointer;user-select:none;
  position:sticky;top:0}
#nc table.ncgrid th[data-sort]:hover{color:var(--ink);background:var(--soft)}
#nc table.ncgrid th[data-sort]::after{content:'\2195';opacity:.25;
  font-size:9px;margin-inline-start:5px;display:inline-block}
#nc table.ncgrid th.sorted{color:var(--acc)}
#nc table.ncgrid th.sorted[aria-sort="ascending"]::after{content:'\25b2';
  opacity:1;font-size:8px}
#nc table.ncgrid th.sorted[aria-sort="descending"]::after{content:'\25bc';
  opacity:1;font-size:8px}
/* the drag handle sits on the column's leading edge (left, in RTL) */
#nc table.ncgrid th{position:relative}
#nc .rsz{position:absolute;inset-inline-start:0;top:0;bottom:0;width:7px;
  cursor:col-resize;z-index:3}
#nc .rsz:hover{background:var(--acc);opacity:.5}
#nc body.rszing,body.rszing{cursor:col-resize!important;user-select:none}
body.rszing #nc .rsz{background:var(--acc);opacity:.5}

/* ---- live per-device speed -------------------------------------- */
#nc .rt{display:flex;flex-direction:column;gap:4px;align-items:flex-start}
#nc .rt b{font-size:13px;font-weight:700;color:var(--dim);
  font-variant-numeric:tabular-nums}
#nc .rt i{font-style:normal;font-size:9.5px;color:var(--faint);
  margin-inline-start:3px}
#nc .rt.on b{color:var(--acc)}
#nc .rt.leak b{color:var(--crit)}
#nc .rt.leak .sbar>i{background:var(--crit)}
#nc .rt .z{color:var(--faint)}
#nc .sbar{display:block;width:100%;height:4px;border-radius:99px;
  background:var(--soft);overflow:hidden}
#nc .sbar>i{display:block;height:100%;border-radius:99px;background:var(--acc);
  transition:width .5s ease-out}
#nc .sbar.warm>i{background:var(--warn)}
#nc .sbar.hot>i{background:var(--crit)}

/* ---- policy preview / history rows ------------------------------- */
#nc .polprev .pvr{display:flex;align-items:center;gap:11px;
  padding:9px 16px;border-bottom:1px solid var(--line);flex-wrap:nowrap}
#nc .polprev .pvr:last-child{border-bottom:0}
#nc .polprev .pvr:hover{background:var(--head)}
#nc .polprev .pn{flex:1;min-width:0;display:flex;align-items:baseline;
  gap:9px;flex-wrap:wrap}
#nc .polprev .pn b{font-size:12.5px;font-weight:700;white-space:nowrap;
  overflow:hidden;text-overflow:ellipsis;max-width:100%}
#nc .polprev .pm{font-family:var(--mono);font-size:10.5px;color:var(--faint);
  direction:ltr;unicode-bidi:embed;white-space:nowrap}
#nc .polprev .pvr .tg{flex:none}
#nc .polprev .pvr .btn{flex:none}
/* the list scrolls inside the card rather than pushing the page down */
#nc .polprev .pvlist{max-height:330px;overflow-y:auto}
@media (max-width:560px){
  #nc .polprev .pvr{flex-wrap:wrap;gap:7px}
  #nc .polprev .pn{flex:1 1 100%}
}

/* ---- expandable row ---------------------------------------------- */
#nc .exp{border:0;background:none;color:var(--faint);cursor:pointer;
  font-size:11px;line-height:1;padding:5px;border-radius:6px;
  transition:background .13s,color .13s}
#nc .exp:hover{background:var(--accb);color:var(--acc)}
#nc .exp[aria-expanded="true"]{color:var(--acc)}
#nc .exp:focus-visible{outline:2px solid var(--acc);outline-offset:1px}
#nc tr.exrow>td{background:var(--head);padding:0;
  border-bottom:2px solid var(--line)}
#nc tr.exrow.blk>td{background:rgba(220,38,38,.04)}
#nc tr.exrow:hover>td{background:var(--head)}
#nc .dgrid{display:grid;gap:1px;padding:0;
  grid-template-columns:repeat(auto-fit,minmax(176px,1fr));
  background:var(--line)}
#nc .df{background:var(--head);padding:11px 15px}
#nc .dl{font-size:10.5px;color:var(--faint);font-weight:600;margin-bottom:4px}
#nc .dv{font-size:12.5px;color:var(--ink);word-break:break-word}
#nc .dv .m{display:inline;font-size:11.5px}
#nc .dwrap{padding:0 0 2px}
#nc .dnote{margin:10px 14px;padding:10px 13px;font-size:12px;line-height:1.65;
  background:var(--accb);color:var(--acc);border-radius:var(--rs);
  border:1px solid color-mix(in srgb,var(--acc) 20%,transparent)}
#nc .dnote:last-child{margin-bottom:12px}
#nc .dnote b{font-weight:700}
#nc .dnote.warn{background:var(--warnb);color:var(--warn);
  border-color:color-mix(in srgb,var(--warn) 25%,transparent)}

/* ---- pills, buttons, inputs ------------------------------------ */
#nc .tg{display:inline-flex;align-items:center;gap:4px;
  padding:3px 9px;border-radius:99px;font-size:11px;font-weight:600;
  background:var(--soft);color:var(--dim);border:1px solid var(--line)}
#nc .st{display:inline-flex;align-items:center;gap:5px;
  padding:4px 11px;border-radius:99px;font-size:11.5px;font-weight:700;
  background:var(--okb);color:var(--ok);border:1px solid rgba(5,150,105,.2)}
#nc .st.off{background:var(--critb);color:var(--crit);
  border-color:rgba(220,38,38,.2)}
#nc .st.idle{background:var(--soft);color:var(--faint);border-color:var(--line)}
#nc .st .dot{width:6px;height:6px;border-radius:99px;background:currentColor}
#nc .btn{border-radius:8px;font-weight:600;border:1px solid var(--line);
  transition:background .13s,box-shadow .13s,border-color .13s}
#nc .btn-xs{padding:5px 11px;font-size:11.5px}
#nc .btn-default{background:var(--card);color:var(--dim)}
#nc .btn-default:hover{background:var(--head);border-color:#cbd5e1;color:var(--ink)}
#nc .btn-danger{background:var(--critb);color:var(--crit);
  border-color:rgba(220,38,38,.22)}
#nc .btn-danger:hover{background:#fecaca;color:#991b1b}
#nc .btn-success{background:var(--okb);color:var(--ok);
  border-color:rgba(5,150,105,.22)}
#nc .btn-success:hover{background:#a7f3d0}
#nc .btn-primary{background:var(--acc);color:#fff;border-color:var(--acc);
  box-shadow:0 1px 3px rgba(37,99,235,.3)}
#nc .btn-primary:hover{background:#1d4ed8;border-color:#1d4ed8}
#nc input[type=text],#nc input[type=search],#nc input[type=number],#nc select{
  border-radius:9px;border:1px solid var(--line);padding:8px 12px;
  background:var(--card);font-size:13px;color:var(--ink);
  transition:border-color .13s,box-shadow .13s}
#nc input[type=text]:focus,#nc input[type=search]:focus,#nc select:focus{
  outline:0;border-color:var(--acc);box-shadow:0 0 0 3px rgba(37,99,235,.12)}

/* ---- tab strip --------------------------------------------------- */
#nc .tabs{display:flex;gap:4px;flex-wrap:wrap;background:var(--card);
  padding:6px;border-radius:var(--r);box-shadow:var(--sh);
  border:1px solid var(--line);margin-bottom:16px}
#nc .tabs>button,#nc .tabs>a{border:0;background:none;border-radius:9px;
  padding:9px 15px;font-size:13px;font-weight:600;color:var(--dim);
  display:inline-flex;align-items:center;gap:7px;transition:all .13s}
#nc .tabs>button:hover{background:var(--head);color:var(--ink)}
#nc .tabs>button.on{background:var(--acc);color:#fff;
  box-shadow:0 1px 3px rgba(37,99,235,.3)}
#nc .tabs .cnt{padding:1px 7px;border-radius:99px;font-size:10.5px;
  font-weight:700;background:var(--soft);color:var(--dim)}
#nc .tabs>button.on .cnt{background:rgba(255,255,255,.22);color:#fff}

/* ---- reports ----------------------------------------------------- */
#nc .rkpi{display:grid;grid-template-columns:repeat(auto-fit,minmax(132px,1fr));
  gap:11px;margin-bottom:14px}
#nc .rk{background:var(--card);border:1px solid var(--line);border-radius:11px;
  padding:13px 15px;box-shadow:var(--sh)}
#nc .rk .v{font-size:23px;font-weight:700;letter-spacing:-.6px;
  font-variant-numeric:tabular-nums;line-height:1.15}
#nc .rk .t{font-size:11.5px;color:var(--dim);margin-top:3px}
#nc .rk.ok .v{color:var(--ok)}
#nc .rk.warn .v{color:var(--warn)}
#nc .rk.bad .v{color:var(--crit)}
#nc .rbar{height:6px;border-radius:99px;background:var(--soft);overflow:hidden}
#nc .rbar>i{display:block;height:100%;border-radius:99px;background:var(--acc)}
#nc .rhours{display:flex;gap:3px;align-items:flex-end;height:92px;
  padding:0 2px;margin-top:4px}
#nc .rhb{flex:1;display:flex;flex-direction:column;justify-content:flex-end;
  align-items:center;height:100%}
#nc .rhb>i{display:block;width:100%;background:var(--acc);
  border-radius:3px 3px 0 0;min-height:2px}
#nc .rhb>span{font-size:9px;color:var(--dim);margin-top:3px}

/* ---- grouped sections -------------------------------------------- */
#nc table.ncgrid tbody tr.ghead td{
  background:var(--head);padding:7px 12px;border-bottom:1px solid var(--line);
  font-size:11.5px;font-weight:700;color:var(--dim);letter-spacing:.2px;
  text-align:right}
#nc table.ncgrid tbody tr.ghead:hover td{background:var(--head)}
#nc table.ncgrid tbody tr.ghead td{cursor:pointer;user-select:none}
#nc table.ncgrid tbody tr.ghead:hover td{background:var(--line)}
#nc table.ncgrid tbody tr.ghead.on td{
  border-bottom-color:var(--acc);color:var(--ink)}
#nc table.ncgrid tbody tr.ghead .gcar{
  display:inline-block;width:14px;color:var(--dim);font-size:10px}
#nc table.ncgrid tbody tr.ghead b.gon{
  font-weight:600;font-size:10.5px;color:var(--ok);
  margin-inline-start:8px}
#nc table.ncgrid tbody tr.ghead i{
  font-style:normal;margin-inline-start:7px;padding:1px 7px;border-radius:99px;
  background:var(--card);color:var(--dim);font-weight:600;font-size:10.5px}

/* ---- progress ---------------------------------------------------- */
/* a determinate-looking sweep across the top while work is in flight */
#nc .prog{position:fixed;top:0;left:0;right:0;height:3px;z-index:9999;
  background:transparent;pointer-events:none;opacity:0;
  transition:opacity .18s ease}
#nc .prog.on{opacity:1}
#nc .prog>i{display:block;height:100%;width:40%;border-radius:0 2px 2px 0;
  background:linear-gradient(90deg,transparent,var(--acc));
  animation:ncsweep 1.1s ease-in-out infinite}
@keyframes ncsweep{
  0%{transform:translateX(-100%)}
  100%{transform:translateX(350%)}
}

/* the button that was clicked: keep its label, add a spinner, lock it */
#nc .btn.working{position:relative;pointer-events:none;opacity:.72}
#nc .btn.working>.spin{display:inline-block;width:11px;height:11px;
  margin-inline-end:6px;vertical-align:-1px;border-radius:50%;
  border:2px solid currentColor;border-top-color:transparent;
  animation:ncspin .6s linear infinite}
@keyframes ncspin{to{transform:rotate(360deg)}}

/* a row whose action is running: mark it rather than dim the whole table */
#nc table.ncgrid tbody tr.working{background:var(--accb)}
#nc table.ncgrid tbody tr.working td:first-child{
  box-shadow:inset 3px 0 0 var(--acc)}

@media (prefers-reduced-motion:reduce){
  #nc .prog>i,#nc .btn.working>.spin{animation-duration:0s}
  #nc .prog>i{width:100%}
}

/* ---- filter segment --------------------------------------------- */
#nc .seg{display:flex;gap:4px;flex-wrap:wrap}
#nc .seg button{border:1px solid var(--line);background:var(--card);
  border-radius:8px;padding:6px 12px;font-size:12px;font-weight:600;
  color:var(--dim);transition:all .13s}
#nc .seg button:hover{background:var(--head);color:var(--ink)}
#nc .seg button.on{background:var(--acc);color:#fff;border-color:var(--acc)}

#nc .tg.cpu{background:var(--okb);color:var(--ok);border-color:rgba(5,150,105,.25)}
#nc .tg.prot{background:var(--accb);color:var(--acc);border-color:rgba(37,99,235,.25)}
#nc .tg.hot{background:var(--critb);color:var(--crit);border-color:rgba(220,38,38,.25)}
#nc .tg.tmp{background:var(--warnb);color:var(--warn);border-color:rgba(180,83,9,.25)}
#nc .tg.ipb{background:var(--soft);color:var(--faint)}
#nc .tg.mac{background:var(--accb);color:var(--acc);border-color:rgba(37,99,235,.25)}

#nc .spd{display:flex;align-items:center;gap:6px;min-width:128px}
#nc .spd input[type=range]{flex:1;min-width:54px;height:3px}
#nc .spd .v{font-family:var(--mono);font-size:11px;font-weight:600;min-width:42px;text-align:right}
#nc .spd.unl .v{color:var(--acc)}

#nc .bulk{display:flex;gap:7px;align-items:center;flex-wrap:wrap;padding:8px 11px;
  background:var(--accb);border-radius:4px;margin-bottom:8px;font-size:13px}
#nc .bulk b{font-family:var(--mono);color:var(--acc)}

#nc .sheet{position:fixed;inset:0;background:rgba(10,14,20,.55);z-index:9999;
  display:flex;align-items:center;justify-content:center;
  padding:max(16px,env(safe-area-inset-left,0px));
  backdrop-filter:blur(2px);animation:ncfade .14s ease-out}
@keyframes ncfade{from{opacity:0}to{opacity:1}}
#nc .sheet .bx{background:var(--card);border-radius:var(--r);width:100%;
  max-width:560px;max-height:86vh;overflow-y:auto;box-shadow:var(--shc),
  0 20px 48px rgba(15,23,42,.22);padding:20px;color:var(--ink);
  animation:ncpop .16s ease-out}
@keyframes ncpop{from{transform:translateY(6px);opacity:.6}
  to{transform:none;opacity:1}}
#nc .sheet .bx *{max-width:100%;box-sizing:border-box}
#nc .sheet h4{margin:0 0 4px;font-size:17px;font-weight:700;line-height:1.35;
  word-break:break-word}
#nc .sheet .who{font-family:var(--mono);font-size:11.5px;color:var(--dim);
  margin-bottom:15px;word-break:break-all;line-height:1.5;
  direction:ltr;unicode-bidi:embed;text-align:right}
#nc .sheet .what{background:var(--soft);border-radius:var(--rs);
  padding:12px 14px;line-height:1.6;font-size:13px;margin-bottom:14px}
/* the device profile: a real grid, not one field per line */
#nc .sheet .prof{display:grid;gap:1px;background:var(--line);
  grid-template-columns:repeat(auto-fit,minmax(150px,1fr));
  border:1px solid var(--line);border-radius:var(--rs);overflow:hidden;
  margin-bottom:14px}
#nc .sheet .pb{background:var(--card);padding:10px 13px}
#nc .sheet .pb .l{font-size:10.5px;color:var(--faint);font-weight:600;
  margin-bottom:3px}
#nc .sheet .pb .v{font-size:12.5px;color:var(--ink);word-break:break-word}
#nc .sheet .btns{display:flex;gap:9px;justify-content:flex-start;
  margin-top:16px;flex-wrap:wrap}
#nc .sheet .btns .btn{padding:9px 18px;font-size:13px}
#nc .sheet .note{font-size:11px;color:var(--faint);margin-top:11px}
#nc .sheet .dur{margin-bottom:14px}
#nc .sheet .dur .dl{font-size:11.5px;color:var(--dim);font-weight:600;
  margin-bottom:7px}
#nc .sheet ul{margin:0;padding:0;list-style:none;max-height:200px;
  overflow-y:auto;border:1px solid var(--line);border-radius:var(--rs);
  margin-bottom:14px}
#nc .sheet ul li{padding:8px 13px;border-bottom:1px solid var(--line);
  font-size:12.5px;display:flex;gap:8px;align-items:baseline;flex-wrap:wrap}
#nc .sheet ul li:last-child{border-bottom:0}
#nc .sheet ul li .mm{font-family:var(--mono);font-size:10.5px;
  color:var(--faint);direction:ltr;unicode-bidi:embed}
#nc .sheet .cmd{font-family:var(--mono);font-size:10.5px;color:var(--dim);
  direction:ltr;unicode-bidi:embed;text-align:right;
  background:#fafbfc;border:1px solid var(--soft);border-radius:4px;
  padding:8px 10px;margin-bottom:12px;white-space:pre-wrap;word-break:break-all;
  max-height:140px;overflow-y:auto;direction:ltr;text-align:left;line-height:1.5}
#nc .sheet ul{margin:0 0 11px;padding:0;list-style:none;font-size:11.5px;
  color:var(--dim);max-height:150px;overflow-y:auto;overflow-x:hidden;
  border:1px solid var(--soft);border-radius:4px}
#nc .sheet ul li{padding:4px 9px;border-bottom:1px solid var(--soft);
  display:flex;gap:7px;align-items:baseline;white-space:nowrap;
  overflow:hidden;text-overflow:ellipsis}
#nc .sheet ul li:last-child{border-bottom:0}
#nc .sheet ul li b{font-weight:600;color:var(--fg);flex:0 1 auto;
  overflow:hidden;text-overflow:ellipsis}
#nc .sheet ul li .mm{font-family:var(--mono);font-size:10px;color:var(--faint);
  margin-right:auto}
#nc .sheet .btns{display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap}
#nc .sheet .note{font-size:11px;color:var(--faint);margin-top:10px;text-align:center}
#nc .dur{margin-bottom:11px}
#nc .dur .dl{font-size:11.5px;color:var(--dim);margin-bottom:5px}
#nc .dur .seg{width:100%;display:flex}
#nc .dur .seg button{flex:1 1 0;text-align:center;padding:6px 4px;
  font-size:11.5px;white-space:nowrap;min-width:0}

#nc .rules,#nc .att,#nc .sched{border:1px solid var(--soft);border-radius:4px;overflow:hidden}
#nc .rule,#nc .arow,#nc .srow{display:grid;gap:9px;align-items:center;
  padding:8px 11px;border-bottom:1px solid var(--soft);font-size:12.5px}
#nc .rule{grid-template-columns:auto 1fr auto auto auto}
#nc .arow{grid-template-columns:auto 1fr auto}
#nc .srow{grid-template-columns:auto 1fr auto auto}
#nc .rule:last-child,#nc .arow:last-child,#nc .srow:last-child{border-bottom:0}
#nc .dom{font-family:var(--mono);font-size:12px}
#nc .sw{position:relative;width:32px;height:18px;display:inline-block}
#nc .sw input{position:absolute;inset:0;opacity:0;margin:0;cursor:pointer;width:100%;height:100%}
#nc .sw span{position:absolute;inset:0;border-radius:99px;background:#ccc;transition:.15s}
#nc .sw span::after{content:"";position:absolute;top:2px;left:2px;width:14px;height:14px;
  border-radius:50%;background:#fff;transition:.15s;box-shadow:0 1px 2px rgba(0,0,0,.3)}
#nc .sw input:checked+span{background:var(--crit)}
#nc .sw.g input:checked+span{background:var(--ok)}
#nc .sw input:checked+span::after{transform:translateX(14px)}

#nc .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(195px,1fr));gap:10px}
#nc .card{border:1px solid var(--soft);border-radius:5px;padding:11px}
#nc .card.on{border-color:var(--acc);background:var(--accb)}
#nc .card h5{margin:0 0 3px;font-size:13px;font-weight:600;display:flex;
  justify-content:space-between;gap:6px}
#nc .card .c{font-family:var(--mono);font-size:10.5px;color:var(--faint)}
#nc .card .mem{font-size:11.5px;color:var(--dim);margin-bottom:9px;min-height:30px}
#nc .tl{display:flex;height:22px;border:1px solid var(--soft);border-radius:4px;
  overflow:hidden;margin-bottom:4px}
#nc .tl div{flex:1;position:relative}
#nc .tl .w{background:var(--warnb)}#nc .tl .f{background:var(--okb)}
#nc .tl .now{position:absolute;inset:0 auto 0 50%;width:2px;background:var(--crit)}
#nc .tlx{display:flex;justify-content:space-between;font-family:var(--mono);
  font-size:9.5px;color:var(--faint);margin-bottom:11px}
#nc .hist{display:flex;align-items:flex-end;gap:3px;height:48px;margin:4px 0 6px}
#nc .hist i{flex:1;background:var(--acc);border-radius:2px 2px 0 0;min-height:2px}
#nc .prof{display:grid;grid-template-columns:repeat(auto-fit,minmax(104px,1fr));
  gap:1px;background:var(--soft);border:1px solid var(--soft);border-radius:4px;
  overflow:hidden;margin-bottom:11px}
#nc .pb{background:#fff;padding:9px 11px}
#nc .pb .l{font-size:9.5px;color:var(--faint);text-transform:uppercase}
#nc .pb .v{font-family:var(--mono);font-size:13px;font-weight:600;margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
#nc .empty{text-align:center;padding:24px;color:var(--faint);font-size:13px}

/* ---- bilingual ---------------------------------------------------- */
#nc .langsw{display:inline-flex;border:1px solid var(--line);border-radius:8px;
  overflow:hidden;background:var(--card)}
#nc .langsw button{border:0;background:none;padding:6px 12px;font-size:12px;
  font-weight:700;color:var(--dim);cursor:pointer;font-family:inherit;
  transition:background .13s,color .13s}
#nc .langsw button.on{background:var(--acc);color:#fff}
#nc .langsw button:not(.on):hover{background:var(--head);color:var(--ink)}
/* English reads left to right; the table keeps its own column order */
#nc[data-lang="en"]{direction:ltr}
#nc[data-lang="en"] .m,#nc[data-lang="en"] .host{text-align:left}
#nc[data-lang="en"] table.ncgrid th.s,
#nc[data-lang="en"] table.ncgrid td.s{text-align:left}
#nc[data-lang="en"] table.ncgrid th.num,
#nc[data-lang="en"] table.ncgrid td.num{text-align:right}
#nc[data-lang="en"] .polf{grid-template-columns:150px 1fr}
#nc[data-lang="en"] .hdh span,#nc[data-lang="en"] .vgb,
#nc[data-lang="en"] .hb{text-align:right}

/* ==== dashboard layout ============================================= */
#nc{background:#f4f6fa;padding:22px 24px}
#nc [hidden]{display:none!important}
#nc .faint{color:var(--faint)}
#nc .nc-bar.hd2{display:flex;align-items:flex-start;justify-content:space-between;
  gap:12px;flex-wrap:wrap;margin-bottom:16px}
#nc .hd2 h1{font-size:22px;font-weight:700;margin:0;color:var(--ink)}
#nc .hd2 .sub{font-size:12.5px;color:var(--dim);margin-top:4px}
#nc .hd2 .sub b{color:var(--ink)}
#nc .hd2 .sp-r{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
#nc .livepill{display:inline-flex;align-items:center;gap:7px;background:#e8f7ee;
  color:#15803d;font-size:12px;font-weight:600;padding:6px 12px;border-radius:99px}
#nc .livepill i{width:7px;height:7px;border-radius:50%;background:#22c55e}
#nc .livepill.bad{background:var(--critb);color:var(--crit)}
#nc .livepill.bad i{background:var(--crit)}
#nc .btn2{display:inline-flex;align-items:center;gap:7px;background:#fff;
  border:1px solid #dfe4ec;border-radius:9px;padding:7px 14px;font-size:13px;
  font-weight:600;color:var(--ink);cursor:pointer;line-height:1.2;font-family:inherit;
  white-space:nowrap}
#nc .btn2:hover{background:#f8fafc;border-color:#cbd3de}
#nc .btn2.pri{color:var(--acc);border-color:#c7d7fb}
#nc .btn2:disabled{opacity:.6;cursor:default}

#nc .sp{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:12px;margin-bottom:14px}
@media (max-width:1250px){#nc .sp{grid-template-columns:repeat(3,minmax(0,1fr))}}
@media (max-width:640px){#nc .sp{grid-template-columns:repeat(2,minmax(0,1fr))}}
#nc .sp .k{background:#fff;border:1px solid #e6eaf0;border-radius:12px;padding:14px 16px;min-width:0}
#nc .sp .k .l{font-size:12.5px;color:#475569;font-weight:600;margin-bottom:8px;
  text-transform:none;letter-spacing:0}
#nc .sp .k .v{font-size:26px;font-weight:700;color:var(--ink);font-family:inherit;
  line-height:1.1;white-space:nowrap}
#nc .sp .k .v small{font-size:13px;color:var(--dim);font-weight:500;margin-inline-start:5px}
#nc .sp .k .v .sl{color:#94a3b8;font-weight:400}
#nc .sp .k .br{display:flex;align-items:center;gap:8px;margin-top:10px}
#nc .sp .k .tr2{flex:1;height:5px;background:#eef1f5;border-radius:99px;overflow:hidden}
#nc .sp .k .tr2 i{display:block;height:100%;border-radius:99px}
#nc .sp .k .pc{font-size:11.5px;font-weight:700}
#nc .sp .k .s{font-size:12px;color:var(--dim);margin-top:7px;font-family:inherit}

#nc .glim{background:#fff;border:1px solid #e6eaf0;border-radius:12px;padding:12px 14px;margin-bottom:16px}
#nc .gl2{display:flex;align-items:center;gap:12px;flex-wrap:wrap}
#nc .gl2 .gic{width:36px;height:36px;border-radius:9px;background:var(--accb);
  color:var(--acc);display:flex;align-items:center;justify-content:center;flex:none;font-size:15px}
#nc .gl2 .gtx{flex:1;min-width:220px}
#nc .gl2 h4{margin:0 0 2px;font-size:14px;font-weight:700}
#nc .gl2 .sub{font-size:12px;color:var(--dim)}
#nc .gchip{display:inline-flex;align-items:center;gap:6px;background:#f1f5f9;
  border-radius:8px;padding:7px 12px;font-size:13.5px;font-weight:700;white-space:nowrap}
#nc .gchip.dn b{color:var(--acc)}
#nc .gchip.up b{color:#c2410c}
#nc .glim .gr{margin-top:12px;padding-top:12px;border-top:1px solid #eef1f5}

#nc .tabs{overflow:visible;flex-wrap:wrap;border-bottom:1px solid #e3e8ef;
  margin-bottom:14px;gap:2px;align-items:flex-end}
#nc .tabs button{padding:10px 14px;font-size:14px;font-weight:500;color:#475569;
  border:0;border-bottom:2px solid transparent;margin-bottom:-1px;background:none;white-space:nowrap}
#nc .tabs button.on{color:var(--acc);border-bottom-color:var(--acc);font-weight:600}
#nc .tabs .c{font-size:11px;background:#eef1f5;color:#475569;border-radius:99px;
  padding:1px 7px;margin-inline-start:6px;font-family:inherit;font-weight:600}
#nc .tabs #c-blk{background:#fee2e2;color:#dc2626}
#nc .tmore{position:relative}
#nc .tmenu,#nc .ddm{position:absolute;top:calc(100% + 4px);inset-inline-end:0;z-index:40;
  background:#fff;border:1px solid #e3e8ef;border-radius:10px;
  box-shadow:0 10px 28px rgba(15,23,42,.14);padding:6px;min-width:190px;
  display:flex;flex-direction:column;gap:1px}
#nc .tabs .tmenu button,#nc .ddm button{text-align:start;border:0;border-radius:7px;padding:8px 10px;
  margin:0;font-size:13px;color:#334155;background:none;cursor:pointer;
  display:flex;justify-content:space-between;align-items:center;gap:10px}
#nc .tabs .tmenu button:hover,#nc .ddm button:hover{background:#f1f5f9}
#nc .tabs .tmenu button.on,#nc .ddm button.on{background:var(--accb);color:var(--acc);font-weight:600}

#nc .tb2{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:14px}
#nc .srch{flex:1 1 260px;display:flex;align-items:center;gap:8px;background:#fff;
  border:1px solid #dfe4ec;border-radius:9px;padding:0 12px;height:38px;margin:0;font-weight:400}
#nc .srch i{color:#94a3b8}
#nc .srch input{border:0;outline:0;flex:1;height:100%;font-size:13.5px;background:none;min-width:0;padding:0}
#nc .seg2{display:flex;background:#eef1f5;border-radius:9px;padding:3px;gap:2px}
#nc .seg2 button{border:0;background:none;padding:6px 13px;font-size:13px;font-weight:500;
  color:#475569;border-radius:7px;cursor:pointer;white-space:nowrap}
#nc .seg2 button.on{background:#fff;color:var(--acc);font-weight:600;
  box-shadow:0 1px 2px rgba(15,23,42,.1)}
#nc .dd{position:relative;min-width:0}
#nc .ddb{display:inline-flex;align-items:center;gap:8px;height:38px;background:#fff;
  border:1px solid #dfe4ec;border-radius:9px;padding:0 13px;font-size:13px;
  color:var(--ink);cursor:pointer;white-space:nowrap;max-width:100%}
#nc .ddb.on{border-color:var(--acc);background:#f5f8ff}
#nc .ddb b{font-weight:600}
#nc .ddh{color:#64748b;font-size:12px;overflow:hidden;text-overflow:ellipsis;max-width:330px}

#nc .dcard{background:#fff;border:1px solid #e6eaf0;border-radius:12px;overflow:hidden}
#nc .chips{display:flex;align-items:center;gap:8px;flex-wrap:wrap;padding:12px 14px;
  border-bottom:1px solid #eef1f5}
#nc .chip{display:inline-flex;align-items:center;gap:8px;border:1px solid #dfe4ec;
  background:#fff;border-radius:9px;padding:7px 12px;font-size:13.5px;font-weight:600;
  color:#334155;cursor:pointer;white-space:nowrap}
#nc .chip i{font-style:normal;font-size:11px;background:#eef1f5;color:#475569;
  border-radius:99px;padding:1px 7px}
#nc .chip.on{background:#eef4ff;border-color:#9dbbf8;color:var(--acc)}
#nc .chip.on i{background:#dbe7fe;color:var(--acc)}
#nc .chips .cs{margin-inline-start:auto;display:flex;align-items:center;gap:12px;
  font-size:12.5px;color:var(--dim)}
#nc .chips .cs b{color:#15803d;font-weight:600}
#nc .dcard .table-responsive{border:0;margin:0}
#nc .dcard table.ncgrid{border:0;margin:0;box-shadow:none;border-radius:0}
#nc .dcard table.ncgrid thead th{background:#fff;font-size:12px;font-weight:600;color:#475569;
  padding:12px 10px;border-bottom:1px solid #eef1f5;text-transform:none;letter-spacing:0}
#nc .dcard table.ncgrid thead th.sorted{color:var(--acc)}
#nc .dcard table.ncgrid tbody td{padding:11px 10px;border-bottom:1px solid #f1f4f8;
  vertical-align:middle;font-size:13.5px}
#nc .dcard table.ncgrid tbody tr[data-mac]{cursor:pointer}
#nc .dcard table.ncgrid tbody tr[data-mac]:hover td{background:#fafbfd}
#nc .dcard .dv{min-width:0}
#nc .dcard .dv .t{font-weight:600;color:var(--ink);font-size:14px}
#nc .mac2{font-family:var(--mono);font-size:11.5px;color:#64748b;display:flex;gap:6px;
  align-items:center;flex-wrap:wrap;margin-top:3px}
#nc .mac2 .tg{font-family:inherit}
#nc .tg.rnd{background:#f3e8ff;color:#7e22ce}
#nc .tg.fix{background:#e0f2fe;color:#0369a1}
#nc .sp2{display:inline-flex;flex-direction:column;line-height:1.4;font-size:12.5px;
  align-items:flex-start;white-space:nowrap}
#nc .sp2 .dn{color:#334155}
#nc .sp2 .dn.on{color:var(--acc);font-weight:700}
#nc .sp2 .up{color:#64748b}
#nc .pl{display:inline-flex;align-items:center;gap:6px;border-radius:99px;padding:3px 10px;
  font-size:12px;font-weight:600;text-transform:capitalize;white-space:nowrap}
#nc .pl:before{content:"";width:6px;height:6px;border-radius:50%;background:currentColor}
#nc .pl.dl{background:#e8f0ff;color:#1d4ed8}
#nc .pl.on{background:#e8f7ee;color:#15803d}
#nc .pl.lim{background:#fef3c7;color:#b45309}
#nc .pl.blk{background:#fee2e2;color:#dc2626}
#nc .pl.off{background:#f1f5f9;color:#64748b}
#nc td.act{white-space:nowrap}
#nc .ib{width:30px;height:30px;border:0;background:none;border-radius:7px;color:#475569;
  cursor:pointer;display:inline-flex;align-items:center;justify-content:center;font-size:14px}
#nc .ib:hover{background:#f1f5f9}
#nc .ib:disabled{opacity:.35;cursor:default}
#nc .ib.red{color:#dc2626}
#nc .ib.grn{color:#15803d}
#nc .more2{text-align:center}
#nc .more2 button{border:0;background:none;color:var(--acc);font-weight:600;font-size:13px;
  cursor:pointer;padding:13px;width:100%}
#nc .more2 button:hover{background:#fafbfd}
#nc .more2:empty{display:none}

#nc .lpop{position:fixed;z-index:1000;background:#fff;border:1px solid #e3e8ef;border-radius:10px;
  box-shadow:0 12px 32px rgba(15,23,42,.18);padding:10px;width:236px}
#nc .lph{font-size:12px;font-weight:700;color:#475569;margin-bottom:8px}
#nc .lpo{display:grid;grid-template-columns:repeat(3,1fr);gap:6px}
#nc .lpo button,#nc .lpc button{border:1px solid #dfe4ec;background:#fff;border-radius:7px;
  padding:6px 0;font-size:12.5px;cursor:pointer;color:#334155}
#nc .lpo button:hover,#nc .lpc button:hover{border-color:var(--acc);color:var(--acc)}
#nc .lpo button.on{border-color:var(--acc);color:var(--acc);background:#f5f8ff;font-weight:600}
#nc .lpc{display:flex;gap:6px;margin-top:8px}
#nc .lpc input{flex:1;min-width:0;border:1px solid #dfe4ec;border-radius:7px;padding:5px 8px;font-size:12.5px}
#nc .lpc button{padding:6px 12px}
/* the older pill-style tab strip and monospace cells, undone for this layout */
#nc .tabs{background:none;padding:0;border-radius:0;box-shadow:none;border:0;
  border-bottom:1px solid #e3e8ef;gap:2px;margin-bottom:14px}
#nc .tabs>button,#nc .tmore>#tmore{border:0;border-bottom:2px solid transparent;border-radius:0;
  box-shadow:none;background:none;color:#475569;padding:10px 14px;font-size:14px;
  font-weight:500;margin-bottom:-1px;display:inline-flex;align-items:center;gap:0;cursor:pointer}
#nc .tabs>button:hover,#nc .tmore>#tmore:hover{background:none;color:var(--ink)}
#nc .tabs>button.on,#nc .tmore>#tmore.on{background:none;color:var(--acc);box-shadow:none;
  border-bottom-color:var(--acc);font-weight:600}
#nc .tabs>button.on .c{background:var(--accb);color:var(--acc)}
#nc .tmore>#tmore i{margin-inline-start:6px}
#nc .dcard table.ncgrid td,#nc .dcard table.ncgrid td.num{font-family:inherit}
#nc .dcard table.ncgrid .m{font-family:inherit;font-size:13.5px;color:var(--ink)}
#nc .dcard table.ncgrid thead th{position:static}
/* a number and its unit read left to right in both languages ("23 Mb", never "Mb 23") */
#nc .gchip,#nc .sp2 span,#nc .gl3,#nc .lpo button,#nc .dcard td.num,#nc .dcard td.c b,
#nc .sp .k .s,#nc .sp .k .v{direction:ltr;unicode-bidi:isolate}
#nc:not([data-lang="en"]) .sp .k .v,#nc:not([data-lang="en"]) .sp .k .s{text-align:right}
/* details panel: a plain grid across the full row, not a centred column */
#nc .dcard table.ncgrid tbody tr.exrow>td{padding:0;text-align:start;white-space:normal;
  overflow:visible;background:#f8fafc;border-bottom:1px solid #e6eaf0}
#nc .dcard table.ncgrid tr.exrow>td>.dwrap{display:block;gap:0;justify-content:normal;white-space:normal;
  width:100%;box-sizing:border-box;padding:14px 16px}
#nc .dcard .dgrid{grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:10px 22px;background:none}
#nc .dcard .df{background:none;padding:0;text-align:start;border:0}
#nc .dcard .dl{font-size:11.5px;color:#64748b;font-weight:600;margin-bottom:3px;font-family:inherit}
#nc .dcard .df .dv{font-size:13.5px;color:var(--ink);min-width:0;font-weight:500}
#nc .dcard .df .dv .m{font-size:12.5px;color:var(--ink)}
#nc .dcard .dnote{margin:12px 0 0}
/* headers and number cells use the page font, and "Current speed" fits */
#nc .dcard table.ncgrid thead th,#nc .dcard table.ncgrid td.num,#nc .dcard table.ncgrid th.num{
  font-family:inherit;overflow:visible;text-overflow:clip;white-space:nowrap}
#nc .dcard table.ncgrid td.num b,#nc .dcard table.ncgrid td.num{font-weight:500}
#nc .dcard table.ncgrid td.num.hot{font-weight:700;color:var(--crit)}
</style>

<style>
#nc[data-layout="studio"]{background:#f3f1eb;border:1px solid #d7ded5;border-radius:16px;padding:clamp(12px,2vw,26px)}
#nc[data-layout="studio"] .nc-bar{background:#131b29;color:#f1f4fb;border-radius:12px;padding:20px}
#nc[data-layout="studio"] .nc-bar h1{color:#f1f4fb;font-size:clamp(24px,3vw,38px)}
#nc[data-layout="studio"] .nc-bar .sub{color:#c1cde0}
#nc[data-layout="studio"] #layout-toggle{background:#88e7cb;color:#0b3026;border-color:#88e7cb}
#nc[data-layout="studio"] .tabs{background:#fffdf8;border:1px solid #d7ded5;border-radius:12px;padding:8px;margin-bottom:12px}
#nc[data-layout="studio"] .tabs button.on{background:#d9eee4;color:#126043;border-radius:8px}
#nc[data-layout="studio"] #t-dev .tb2{background:#fffdf8;border-radius:12px;padding:12px}
#nc[data-layout="studio"] #t-dev table.ncgrid thead{background:#e6eee6}
#nc .nc-inspect{display:none}
#nc .nc-duration [lang="en"],#nc[data-lang="en"] .nc-duration [lang="ar"]{display:none}
#nc[data-lang="en"] .nc-duration [lang="en"]{display:inline}
#nc[data-layout="studio"]:has(#t-dev:not([hidden])) #sp{display:none}
#nc .nc-inspector{width:min(780px,calc(100% - 32px));max-height:calc(100% - 32px);padding:0;
  background:#f4f6f8;color:var(--ink);border:1px solid #d7ded5;border-radius:18px;
  box-shadow:0 18px 65px #131b2940;overflow:auto;overflow-wrap:anywhere}
#nc .nc-inspector::backdrop{background:#131b2999}
#nc .nc-inspector-head{position:sticky;top:0;z-index:1;display:flex;align-items:center;justify-content:space-between;gap:12px;
  padding:14px clamp(14px,3vw,24px);background:#fff;border-bottom:1px solid #dce3eb}
#nc .nc-inspector h2{margin:0;font-size:20px}
#nc #nc-inspector-content{padding:clamp(14px,3vw,24px)}
#nc .nc-device-summary{padding:clamp(14px,3vw,24px);background:#131b29;color:#eef3fb;border-radius:13px;margin-bottom:14px}
#nc .nc-device-summary h3{margin:0;color:inherit;font-size:clamp(20px,4vw,30px)}
#nc .nc-device-identity{display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap}
#nc .nc-device-addresses{display:flex;gap:8px;flex-wrap:wrap;margin:12px 0 0}
#nc .nc-device-addresses bdi{padding:5px 9px;border:1px solid #465773;border-radius:7px;color:#e0ebf9;font:13px monospace}
#nc .nc-copy{padding:4px 8px;border:1px solid #617693;border-radius:6px;background:#24354e;color:#f1f4fb}
#nc .nc-rule-state{margin:0 0 14px;padding:12px;border:1px solid #dce3eb;border-radius:9px;background:#fff}
#nc .nc-rule-state p{margin:4px 0}
#nc .nc-limit-fields{display:flex;flex-wrap:wrap;gap:12px;margin:8px 0}
#nc .nc-limit-fields label{display:grid;gap:4px;margin:0}
#nc .nc-limit-fields input{width:110px}
#nc{--ui-surface:#fff;--ui-soft:#f4f7fb;--ui-border:#d8e2ee;--ui-heading:#172c46}
#nc .nc-bar{gap:16px;padding:18px 20px;background:var(--ui-surface);border:1px solid var(--ui-border);border-radius:12px}
#nc .nc-bar h1{margin:0 0 5px;font-size:clamp(22px,3vw,32px);line-height:1.2;color:var(--ui-heading)}
#nc .nc-bar .sp-r{gap:8px}
#nc .tabs{gap:6px;flex-wrap:wrap;padding:6px;background:var(--ui-surface);border:1px solid var(--ui-border);border-radius:12px;margin:14px 0}
#nc .tabs button[data-t],#nc #tmore{min-height:41px;padding:8px 12px;border-radius:8px;border-bottom:0}
#nc .tabs button[data-t].on,#nc #tmore.on{background:#e8f0ff;color:#174499;box-shadow:inset 0 -2px 0 currentColor}
#nc .nc-view-head{display:flex;align-items:center;gap:10px;margin:0 0 10px;color:var(--ui-heading)}
#nc .nc-view-head h2,#nc .nc-subhead{margin:0;font-size:clamp(17px,2vw,22px);font-weight:700;color:var(--ui-heading)}
#nc .nc-subhead{font-size:16px;margin:16px 0 9px}
#nc .nc-tool-row{padding:10px;margin-bottom:10px;background:var(--ui-surface);border:1px solid var(--ui-border);border-radius:10px;gap:8px}
#nc .nc-tool-row :is(button,input,select){min-height:38px}
#nc #t-dev .nc-tool-row .srch{min-width:min(240px,100%);flex:1}
#nc .table-responsive{max-width:100%;overflow:auto}
#nc .nc-device-table{max-height:min(62vh,650px)}
#nc .dcard{border:1px solid var(--ui-border);border-radius:12px}
#nc .ncgrid thead th,#nc .dcard .ncgrid thead th{background:var(--ui-soft);color:var(--ui-heading);font-size:12px;letter-spacing:0;text-transform:none}
#nc .dcard table.ncgrid thead th{position:sticky;top:0;z-index:2;border-bottom:1px solid var(--ui-border)}
#nc .ncgrid th[data-sort]::after,#nc .ncgrid th.sorted[aria-sort]::after{content:none}
#nc .ncgrid th[data-sort] .nc-table-sort{display:flex;align-items:center;justify-content:inherit;gap:5px;width:100%;min-height:32px;
  padding:0 5px;background:none;border:0;border-radius:5px;color:inherit;font:inherit;text-align:inherit;white-space:normal}
#nc .ncgrid th.num .nc-table-sort{justify-content:flex-end}
#nc .ncgrid th.c .nc-table-sort{justify-content:center}
#nc .nc-sort-arrow{color:#48627f}
#nc .sorted .nc-sort-arrow{color:var(--acc)}
#nc :is(button,input,select,a,[tabindex]):focus-visible{outline:2px solid var(--acc);outline-offset:2px}
#nc :is(.ncgrid,.dvm,.live-detail-head) :is(.num,bdi,.v,.sm){font-variant-numeric:tabular-nums}
#nc table.nc-temporary-table{min-width:0;width:100%;table-layout:auto}
#nc .nc-temporary-card{background:#fff;border:1px solid var(--ui-border);border-radius:10px}
#nc table.ncgrid.nc-temporary-table :is(th,td){padding:12px 16px;text-align:start}
#nc table.ncgrid.nc-temporary-table td.empty{text-align:center}
#nc table.ncgrid.nc-temporary-table bdi{display:inline;white-space:nowrap}
@media(max-width:640px){
  #nc .nc-bar,#nc .nc-tool-row{padding:10px}
  #nc .nc-tool-row :is(button,input,select),#nc .tabs button[data-t],#nc #tmore{min-height:44px}
  #nc .nc-device-table{max-height:72vh}
  #nc table.nc-temporary-table{min-width:470px}
}
#nc .live-grid{--live-actions:162px;--live-metrics:repeat(4,minmax(74px,1fr))}
#nc .live-grid.live-services{--live-metrics:repeat(3,minmax(74px,1fr))}
#nc .live-grid .dvh,#nc .live-head{grid-template-columns:minmax(0,1fr) minmax(320px,36%) var(--live-actions);gap:12px}
#nc .live-head{display:grid;align-items:center;padding:8px 11px;margin-bottom:8px;background:#eaf0f8;border:1px solid var(--line);border-radius:9px;color:#24334b}
#nc .live-grid .dvm{grid-template-columns:var(--live-metrics);width:100%;gap:4px}
#nc .live-head .live-actions{text-align:center;font-weight:600}
#nc .live-sort{background:transparent;border:0;border-radius:6px;padding:8px 5px;color:inherit;font:inherit;font-weight:600}
#nc .live-sort[aria-pressed="true"]{background:#dce8fc;color:#1744a0}
#nc .live-sort:focus-visible,#nc .live-expand:focus-visible{outline:2px solid var(--acc);outline-offset:2px}
#nc .live-expand{background:transparent;border:0;padding:0 3px;color:inherit;font-weight:700;text-align:start}
#nc .live-grid .dvc.open .chev{transform:none}
#nc .live-grid .dva{justify-content:center;flex-wrap:wrap}
#nc .live-grid .svr{grid-template-columns:minmax(130px,1fr) repeat(3,minmax(65px,100px)) minmax(64px,auto)}
#nc .live-grid .svr .sm{text-align:center}
#nc .live-grid .live-detail-head{background:#eaf0f8;color:#24334b}
#nc .live-grid .live-detail-label{display:none}
@media(min-width:921px){#nc .live-grid .dvh .dvm .t{display:none}}
@media(max-width:920px){
  #nc .live-grid .dvh,#nc .live-head{grid-template-columns:minmax(0,1fr);gap:6px}
  #nc .live-head .live-actions{display:none}
  #nc .live-grid .dva{justify-content:flex-start}
}
@media(max-width:580px){
  #nc .live-grid .dvm{grid-template-columns:repeat(2,minmax(0,1fr))}
  #nc .live-grid .svr{grid-template-columns:repeat(2,minmax(0,1fr))}
  #nc .live-grid .svr> :first-child{grid-column:1/-1}
  #nc .live-grid .svr .sm{text-align:start}
  #nc .live-grid .live-detail-label{display:block;color:var(--dim);font-family:inherit}
}
#nc #nc-change-status:not(:empty){margin-bottom:10px;padding:10px;border-radius:8px;background:#e6f4eb;color:#145233}
#nc #nc-change-status.bad{background:#ffefcf;color:#763f05}
#nc .nc-inspector .dgrid{grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}
#nc .nc-inspector .dwrap{padding:0;border:0;background:none}
#nc .nc-inspector .df{padding:10px 12px;border:1px solid #dce3eb;border-radius:9px;background:#fff;min-width:0}
@media(max-width:575px){#nc .nc-inspector .dgrid{grid-template-columns:repeat(2,minmax(0,1fr))}}
#nc[data-layout="studio"] .nc-inspect{display:inline-block;padding:3px 6px;color:#126043;background:none;border:1px solid transparent;border-radius:6px;text-align:start;font:inherit}
#nc[data-layout="studio"] .nc-inspect:hover{background:#d9eee4;text-decoration:underline}
#nc[data-layout="studio"] .nc-inspect:focus-visible{outline:2px solid #126043}
#nc[data-layout="studio"] .nc-classic-name,#nc[data-layout="studio"] #t-dev .exrow{display:none}
#nc[data-layout="studio"] #t-dev table.ncgrid{min-width:575px}
#nc[data-layout="studio"] #t-dev .ncgrid :is(col,th,td):is(:nth-child(4),:nth-child(6),:nth-child(8)){display:none}
#nc[data-layout="studio"] #t-dev .ncgrid col:nth-child(3){width:110px!important}
#nc[data-layout="studio"] #t-dev .ncgrid col:nth-child(5){width:85px!important}
#nc[data-layout="studio"] #t-dev .ncgrid col:nth-child(7){width:85px!important}
#nc[data-layout="studio"]:has(#t-live:not([hidden])) #sp{display:none}
#nc[data-layout="studio"]:has(#t-live:not([hidden])) .nc-monitor{display:grid}
#nc .nc-monitor{display:none;grid-template-columns:minmax(0,3fr) minmax(150px,1fr);gap:24px;
  margin:14px 0;padding:clamp(16px,3vw,30px);border-radius:14px;background:#131b29;color:#f1f4fb}
#nc .nc-monitor h2{color:inherit;font-size:20px;margin:0 0 12px}
#nc .nc-monitor dl{border-inline-start:1px solid #384962;margin:0;padding-inline-start:18px;align-self:center}
#nc .nc-monitor dt,#nc .nc-monitor-caption{color:#b4c1d6}
#nc .nc-monitor dd{margin:2px 0 10px;font-size:19px;font-weight:700}
#nc .nc-plot{display:flex;align-items:end;gap:clamp(3px,1vw,14px);height:135px;
  border-bottom:1px solid #51617a;background:repeating-linear-gradient(to top,transparent 0 calc(25% - 1px),#2a364b calc(25% - 1px) 25%)}
#nc .nc-plot i{flex:1;min-width:0;border-radius:4px 4px 0 0;background:linear-gradient(#88e7cb,#267a70)}
#nc .nc-plot .sample{flex:1;min-width:0;height:100%;display:flex;align-items:end;gap:3px}
#nc .nc-plot i.upload{background:linear-gradient(#9dc7ff,#4776c6)}
#nc .nc-monitor-key{display:flex;flex-wrap:wrap;gap:12px;margin-bottom:8px;color:#e0ebf9}
#nc .nc-monitor-key .down{color:#88e7cb}
#nc .nc-monitor-key .up{color:#9dc7ff}
#nc .nc-monitor-caption{display:flex;justify-content:space-between;gap:8px;padding-top:8px;font-variant-numeric:tabular-nums}
@media(max-width:620px){
  #nc .nc-monitor{grid-template-columns:minmax(0,1fr);gap:12px}
  #nc .nc-monitor dl{display:grid;grid-template-columns:1fr auto;gap:4px 12px;border-inline-start:0;padding-inline-start:0}
  #nc .nc-monitor dd{margin:0}
}
</style>
<div id="nc">
  <!--
    A bar for page-level controls. The language switch mounts into .sp-r;
    without this the switch fell back to the end of #nc, where it rendered
    below the log and was effectively invisible.
  -->
  <div class="nc-bar hd2">
    <div>
      <h1>التحكم في الشبكة</h1>
      <div class="sub" id="live">جاري تحميل حالة الشبكة…</div>
    </div>
    <div class="sp-r">
      <span class="livepill" id="livepill" role="status"><i aria-hidden="true"></i><span>بيحمّل…</span></span>
      <button type="button" class="btn2" id="layout-toggle" aria-pressed="false">التصميم الإضافي</button>
      <button type="button" class="btn2" id="refresh"><i class="fa fa-refresh"></i> تحديث</button>
    </div>
  </div>

  <div id="alerts"></div>
  <div id="nc-change-status" role="status"></div>
  <section id="nc-monitor" class="nc-monitor" aria-labelledby="nc-monitor-title">
    <div>
      <h2 id="nc-monitor-title">حركة الإنترنت على WAN</h2>
      <p id="nc-monitor-empty">لسه مافيش بيانات</p>
      <div id="nc-monitor-series" hidden>
        <div class="nc-monitor-key"><span class="down">↓ التنزيل</span><span class="up">↑ الرفع</span><bdi>Mb/s</bdi></div>
        <div id="nc-monitor-plot" class="nc-plot" dir="ltr" role="img"></div>
        <div class="nc-monitor-caption" dir="ltr">
          <time id="nc-monitor-first"></time><time id="nc-monitor-last"></time>
        </div>
      </div>
    </div>
    <dl>
      <dt>الوارد من الإنترنت</dt><dd><bdi id="nc-monitor-down">—</bdi></dd>
      <dt>الصادر للإنترنت</dt><dd><bdi id="nc-monitor-up">—</bdi></dd>
      <dt>الأجهزة المتصلة</dt><dd id="nc-monitor-devices">—</dd>
    </dl>
  </section>
  <div class="sp" id="sp"></div>

  <div class="glim" id="glim"></div>

  <nav class="tabs" aria-label="أقسام التحكم في الشبكة">
    <button class="on" data-t="live" aria-current="page">المفتوح دلوقتي <span class="c" id="c-live">—</span></button>
    <button data-t="dev">الأجهزة <span class="c" id="c-dev">—</span></button>
    <button data-t="blocked">الموقوفة <span class="c" id="c-blk">—</span></button>
    <button data-t="groups">المجموعات <span class="c" id="c-grp">—</span></button>
    <button data-t="sched">المواعيد <span class="c" id="c-sch">—</span></button>
    <button data-t="sites">حظر مواقع <span class="c" id="c-sites">—</span></button>
    <button data-t="policy">سياسة الجديد <span class="c" id="c-pol">—</span></button>
    <div class="tmore">
      <button type="button" id="tmore" aria-controls="tmenu" aria-expanded="false">المزيد <i class="fa fa-angle-down" aria-hidden="true"></i></button>
      <div class="tmenu" id="tmenu" hidden>
        <button data-t="video">الفيديو</button>
        <button data-t="att">محاولات <span class="c" id="c-att">—</span></button>
        <button data-t="clean">تنظيف MAC <span class="c" id="c-clean">—</span></button>
        <button data-t="hist">الدخول والاستهلاك</button>
        <button data-t="report">التقارير</button>
        <button data-t="log">السجل</button>
      </div>
    </div>
  </nav>

  <section id="t-live" aria-labelledby="live-heading">
    <header class="nc-view-head"><h2 id="live-heading">المفتوح دلوقتي</h2></header>
    <div class="bar nc-tool-row">
      <div class="seg">
        <button class="on" data-lvv="devices">حسب الجهاز</button>
        <button data-lvv="services">حسب الخدمة</button>
      </div>
      <input type="search" id="lvq" placeholder="بحث باسم الجهاز أو IP أو خدمة…">
      <button class="btn btn-xs btn-default" id="lvref">تحديث</button>
    </div>
    <div id="lvbox"><div class="empty">بيحمّل…</div></div>
  </section>

  <section id="t-dev" aria-labelledby="devices-heading" hidden>
    <header class="nc-view-head"><h2 id="devices-heading">الأجهزة</h2></header>
    <div class="tb2 nc-tool-row">
      <button type="button" class="btn2" id="clean-offline" disabled>إزالة كل الأوفلاين</button>
      <label class="srch"><i class="fa fa-search"></i>
        <input type="search" id="q" placeholder="بحث بالاسم أو IP أو MAC…"></label>
      <div class="seg2">
        <button class="on" data-f="all">الكل</button>
        <button data-f="now">شغال دلوقتي</button>
        <button data-f="offline">أوفلاين</button>
        <button data-f="dl">بينزّل</button>
        <button data-f="busy">الأعلى</button>
      </div>
      <div class="dd" id="fdd">
        <button type="button" class="ddb" id="fbtn"><i class="fa fa-filter"></i><b>فلاتر</b><span class="ddh" id="fhint"></span></button>
        <div class="ddm" id="fmenu" hidden>
          <button data-f="lim">محدودة</button>
          <button data-f="blk">موقوفة</button>
          <button data-f="tmp">مؤقتة</button>
          <button data-f="cp">مسجّل دخول</button>
          <button data-f="nocp">مش مسجّل</button>
          <button data-f="rnd">MAC عشوائي</button>
        </div>
      </div>
      <div class="dd" id="gdd">
        <button type="button" class="ddb" id="gbtn"><i class="fa fa-bars"></i><b id="glbl">تجميع: النوع</b><i class="fa fa-angle-down"></i></button>
        <div class="ddm" id="gmenu" hidden>
          <button data-g="1">حسب النوع</button>
          <button data-g="0">من غير تجميع</button>
        </div>
      </div>
    </div>
    <div class="bulk" id="bulk" style="display:none">
      <button type="button" class="btn2" id="clean-offline-selected" disabled>إزالة الأوفلاين المحدد</button>
      <span><b id="bn">0</b> جهاز محدد</span>
      <button class="btn btn-xs btn-danger" data-b="block">إيقاف</button>
      <button class="btn btn-xs btn-default" data-b="lim2">حد 2 ميجا</button>
      <button class="btn btn-xs btn-default" data-b="unl">بلا حد</button>
      <button class="btn btn-xs btn-default" data-b="group">ضمّ لمجموعة</button>
      <button class="btn btn-xs btn-link" data-b="none" style="margin-left:auto">إلغاء التحديد</button>
    </div>
    <div class="dcard">
      <div class="chips" id="chips"></div>
      <div class="table-responsive nc-device-table" tabindex="0" role="region" aria-labelledby="devices-heading">
        <table class="ncgrid"><colgroup>
          <col style="width:44px"><col style="width:auto"><col style="width:126px">
          <col style="width:126px"><col style="width:140px"><col style="width:96px">
          <col style="width:128px"><col style="width:132px"><col style="width:118px">
        </colgroup><thead><tr>
          <th scope="col" class="c"><input type="checkbox" id="all" aria-label="تحديد الكل"></th>
          <th class="s" data-sort="name">الجهاز</th>
          <th class="s" data-sort="ip">عنوان IP</th>
          <th class="s" data-sort="user">مسجّل بـ</th>
          <th class="num" data-sort="rate">السرعة دلوقتي</th>
          <th class="num" data-sort="bytes">الإجمالي</th>
          <th class="c" data-sort="state">الحالة</th>
          <th class="c" data-sort="limit">حد النزول</th>
          <th class="c">إجراء</th>
        </tr></thead><tbody id="tb"></tbody></table>
      </div>
      <div class="more2" id="more2"></div>
    </div>
    <dialog id="nc-inspector" class="nc-inspector" aria-labelledby="nc-inspector-title">
      <div class="nc-inspector-body">
        <div class="nc-inspector-head">
          <h2 id="nc-inspector-title">تفاصيل الجهاز</h2>
          <form method="dialog"><button type="submit" class="btn2" autofocus>إغلاق</button></form>
        </div>
        <div id="nc-inspector-content"></div>
        <div id="nc-copy-status" role="status"></div>
      </div>
    </dialog>
  </section>

  <section id="t-blocked" hidden>
    <div class="panel panel-default">
      <div class="panel-heading" style="display:flex;align-items:center;gap:10px;
        flex-wrap:wrap">
        <h2 class="panel-title">الأجهزة الموقوفة</h2>
        <span style="font-size:11.5px;color:var(--dim)" id="bsum">—</span>
        <div style="margin-inline-start:auto;display:flex;gap:7px;flex-wrap:wrap">
          <input type="search" id="bq" placeholder="بحث…"
            aria-label="بحث في الموقوفة"
            style="min-width:150px;font-size:12px;padding:6px 11px">
          <button class="btn btn-xs btn-default" id="bunall">شغّل الكل</button>
        </div>
      </div>
      <div class="panel-body" style="padding:0"><div id="blist"></div></div>
    </div>
  </section>

  <section id="t-policy" hidden>
    <div id="polbox"><div class="empty">بيحمّل…</div></div>
  </section>

  <section id="t-groups" aria-labelledby="groups-heading" hidden>
    <header class="nc-view-head"><h2 id="groups-heading">المجموعات</h2></header>
    <p style="color:#6b7785;font-size:12.5px;margin:11px 0">
      طبّق قاعدة على مجموعة كاملة. الضم بيتم من تبويب الأجهزة (حدد ثم «ضمّ لمجموعة»).</p>
    <div class="grid" id="grps"></div>
  </section>

  <section id="t-sched" aria-labelledby="schedules-heading" hidden>
    <header class="nc-view-head"><h2 id="schedules-heading">المواعيد</h2></header>
    <h3 class="nc-subhead" id="temporary-limits-heading">حدود السرعة المؤقتة</h3>
    <div class="table-responsive nc-temporary-card" tabindex="0" role="region" aria-labelledby="temporary-limits-heading">
      <table class="ncgrid nc-temporary-table">
        <thead><tr>
          <th scope="col">الجهاز</th><th scope="col">عنوان IP</th>
          <th scope="col">حد النزول</th><th scope="col">حد الرفع</th><th scope="col">ينتهي في</th>
        </tr></thead>
        <tbody id="temporary-limits"><tr><td colspan="5">بيحمّل…</td></tr></tbody>
      </table>
    </div>
    <h3 class="nc-subhead">القواعد المجدولة</h3>
    <p style="color:#6b7785;font-size:12.5px;margin:11px 0">
      قواعد بتشتغل وتقف لوحدها على السيرفر — مش محتاج تفتح الصفحة.</p>
    <div id="schedule-timeline" hidden>
      <div class="tl" id="tl"></div>
      <div class="tlx"><span>12 ص</span><span>6 ص</span><span>12 ظ</span><span>6 م</span><span>12 ص</span></div>
    </div>
    <div class="sched" id="sched"></div>
  </section>

  <section id="t-sites" aria-labelledby="sites-heading" hidden>
    <header class="nc-view-head"><h2 id="sites-heading">حظر مواقع</h2></header>
    <div class="bar nc-tool-row">
      <input type="text" id="dom" aria-label="عنوان الموقع" placeholder="مثال: facebook.com">
      <div class="seg">
        <button class="on" data-sc="all">الكل</button>
        <button data-sc="sel">أجهزة محددة</button>
      </div>
      <button class="btn btn-xs btn-primary" id="adddom">إضافة</button>
    </div>
    <div class="rules" id="rules"></div>
  </section>

  <section id="t-video" hidden>
    <div class="panel panel-default" style="margin-bottom:16px">
      <div class="panel-heading" style="display:flex;align-items:center;gap:10px;
        flex-wrap:wrap">
        <h2 class="panel-title">الفيديو المفتوح دلوقتي</h2>
        <span style="font-size:11.5px;color:var(--dim)" id="vcount">بيحمّل…</span>
        <button class="btn btn-xs btn-default" id="vref"
          style="margin-inline-start:auto">⟳ تحديث</button>
      </div>
      <div class="panel-body" style="padding:0"><div id="vlist"></div></div>
    </div>

    <p style="color:#6b7785;font-size:12.5px;margin:11px 0">
      فيديو يوتيوب وتيك توك بياكل معظم الخط. امنعه أو حدده — على الكل أو على أجهزة محددة.</p>
    <div class="grid" id="vid"></div>
  </section>

  <section id="t-att" hidden>
    <p style="color:#6b7785;font-size:12.5px;margin:11px 0">
      مين حاول يفتح موقع محظور وإمتى.</p>
    <div class="att" id="att"></div>
  </section>

  <section id="t-hist" hidden>
    <div class="seg" id="hsw" style="margin-bottom:14px">
      <button data-h="users" class="on">المستخدمين</button>
      <button data-h="logins">سجل الدخول</button>
      <button data-h="usage">الاستهلاك اليومي</button>
    </div>
    <div class="panel panel-default">
      <div class="panel-heading" style="display:flex;align-items:center;gap:10px;
        flex-wrap:wrap">
        <h2 class="panel-title" id="htitle">المستخدمين</h2>
        <span style="font-size:11.5px;color:var(--dim)" id="hsub"></span>
        <div style="margin-inline-start:auto;display:flex;gap:7px;flex-wrap:wrap">
          <input type="search" id="hq" placeholder="بحث…"
            aria-label="بحث" style="min-width:150px;font-size:12px;padding:6px 11px">
          <button class="btn btn-xs btn-default" id="href">⟳ تحديث</button>
        </div>
      </div>
      <div class="panel-body" style="padding:0"><div id="hbox"></div></div>
    </div>
  </section>

  <section id="t-report" hidden>
    <div class="bar">
      <label style="font-size:12px;color:var(--dim)">اليوم</label>
      <select id="rday" style="font-size:12.5px"></select>
      <button class="btn btn-xs btn-default" id="rref">⟳ تحديث</button>
      <a class="btn btn-xs btn-primary" id="rexp" href="#" target="_blank"
         rel="noopener">⤓ تنزيل HTML</a>
      <a class="btn btn-xs btn-default" id="rweek" href="#" target="_blank"
         rel="noopener">⤓ ملف الأسبوع</a>
    </div>
    <div id="rbox"><div class="empty" style="padding:24px">بيحمّل…</div></div>
  </section>

  <section id="t-log" hidden>
    <div class="panel panel-default">
      <div class="panel-heading" style="display:flex;align-items:center;gap:10px;
        flex-wrap:wrap">
        <h2 class="panel-title">سجل الإجراءات</h2>
        <span style="font-size:11.5px;color:var(--dim)">كل إجراء اتعمل على الشبكة، الأحدث فوق</span>
        <div style="margin-inline-start:auto;display:flex;gap:7px;flex-wrap:wrap">
          <input type="search" id="logq" placeholder="فلترة السجل…"
            aria-label="فلترة السجل"
            style="min-width:170px;font-size:12px;padding:6px 11px">
          <button class="btn btn-xs btn-default" id="logref">⟳ تحديث</button>
        </div>
      </div>
      <div class="panel-body" style="padding:0">
        <div id="logbox" class="logbox">—</div>
      </div>
    </div>
  </section>

  <section id="t-clean" hidden>
    <div class="bar">
      <div class="seg">
        <button data-age="7">7 أيام</button>
        <button class="on" data-age="30">30 يوم</button>
        <button data-age="90">90 يوم</button>
      </div>
      <button class="btn btn-xs btn-default" id="selall" style="margin-left:auto">تحديد الكل</button>
      <button class="btn btn-xs btn-danger" id="delstale" disabled>مسح المحدد</button>
    </div>
    <div class="table-responsive">
      <table class="ncgrid"><colgroup>
        <col style="width:30px"><col style="width:170px"><col style="width:130px">
        <col style="width:auto"><col style="width:110px">
      </colgroup><thead><tr>
        <th class="c"><input type="checkbox" id="allS"></th>
        <th class="s">MAC</th><th class="s">IP محجوز</th>
        <th class="s">الوصف</th><th class="s">النوع</th>
      </tr></thead><tbody id="tbS"></tbody></table>
    </div>
  </section>

</div>

<script>
(function(){
  const root = document.getElementById('nc');
  const button = document.getElementById('layout-toggle');
  function setLayout(alternate){
    root.dataset.layout = alternate ? 'studio' : 'standard';
    button.setAttribute('aria-pressed', String(alternate));
  }
  try{ setLayout(localStorage.getItem('nc_layout') === 'studio'); }catch(e){}
  button.addEventListener('click', ()=>{
    setLayout(root.dataset.layout !== 'studio');
    try{ localStorage.setItem('nc_layout', root.dataset.layout); }catch(e){}
  });
})();
</script>

<script>
(function(){
const CSRF = <?= json_encode(csrf_get_tokens()) ?>;
const API  = 'netcontrol_api.php';
const $  = s => document.querySelector(s);
const $$ = s => [...document.querySelectorAll(s)];
/*
 * Times, in one numeral system.
 *
 * toLocaleTimeString('ar-EG') renders Arabic-Indic digits and ص/م, which
 * appeared on the English page and sat next to Latin digits on the Arabic
 * one. A fixed 24-hour clock reads the same in both and sorts correctly.
 */
/*
 * Which section a device belongs in.
 *
 * Ordered so the things an admin needs to find fast - the firewall, the
 * switches, the servers - are at the top instead of scattered by traffic
 * through ninety phones.
 */
const GRP = [
  ['infra', '\u0623\u062c\u0647\u0632\u0629 \u0627\u0644\u0634\u0628\u0643\u0629'],
  ['srv',   '\u0633\u064a\u0631\u0641\u0631\u0627\u062a \u0648\u062a\u062e\u0632\u064a\u0646'],
  ['fixed', '\u0639\u0646\u0627\u0648\u064a\u0646 \u062b\u0627\u0628\u062a\u0629'],
  ['pc',    '\u0643\u0645\u0628\u064a\u0648\u062a\u0631'],
  ['phone', '\u0645\u0648\u0628\u0627\u064a\u0644'],
  ['anon',  '\u0645\u0634 \u0645\u062a\u0639\u0631\u0641 \u0639\u0644\u064a\u0647']
];
function grpOf(d){
  const t = d.type || '';
  if (d.protected || ['router','ap','switch'].includes(t)) return 'infra';
  if (['server','vm','nas','mgmt'].includes(t))            return 'srv';
  if (d.reserved)                                          return 'fixed';
  if (['pc','apple'].includes(t))                          return 'pc';
  if (['phone','camera','printer','iot'].includes(t))      return 'phone';
  return 'anon';
}

/* sections on by default; the flat list is one click away */
let grouping = true;

/*
 * Which sections are open. Folded by default: the point of grouping is to
 * turn ninety rows into a short index, and that only works if the sections
 * start closed. Remembered per browser so the page returns as it was left.
 */
let openGrp = new Set();
try {
  const raw = localStorage.getItem('nc_open_grp');
  if (raw) openGrp = new Set(JSON.parse(raw));
}catch(e){}
function saveGrp(){
  try{ localStorage.setItem('nc_open_grp', JSON.stringify([...openGrp])); }catch(e){}
}
try{ grouping = localStorage.getItem('nc_group') !== '0'; }catch(e){}

const p2 = n => String(n).padStart(2,'0');
const hhmm = t => { const d=new Date(t*1000);
  return p2(d.getHours())+':'+p2(d.getMinutes()); };
const dmhm = t => { const d=new Date(t*1000);
  return p2(d.getDate())+'/'+p2(d.getMonth()+1)+' '+
         p2(d.getHours())+':'+p2(d.getMinutes()); };

const esc = s => String(s??'').replace(/[&<>"']/g, c =>
  ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

let S = {devices:[],rates:{},system:{},sites:[],video:[],schedule:[],groups:{},attempts:[]};
let filt='all', tab='live', age=30, dur='perm', pend=null, newScope='all', activeGrp=null;
const picked = new Set();
const DUR = {perm:'دائم','60':'ساعة','180':'٣ ساعات',eod:'لآخر اليوم'};
const GRPN = {infra:'البنية الأساسية',office:'المكاتب',guest:'الضيوف',
              cam:'الكاميرات',admin:'الإدارة'};
let histD=[], histU=[];
const monitorSamples = [];

const mb = b => b>=1073741824 ? (b/1073741824).toFixed(1)+' GB'
               : b>=1048576   ? (b/1048576).toFixed(1)+' MB'
               : b>=1024      ? (b/1024).toFixed(0)+' KB' : b+' B';
const fmtL = v => !v ? 'بلا حد' : v+' Mb';

/* Mbit/s, with enough precision to stay useful at both ends of the range */
function spd(v){
  v = +v || 0;
  if (v <= 0)  return '<span class="z">—</span>';
  if (v < 0.1) return '<b>&lt;0.1</b><i>Mb/s</i>';
  if (v < 10)  return '<b>'+v.toFixed(1)+'</b><i>Mb/s</i>';
  return '<b>'+Math.round(v)+'</b><i>Mb/s</i>';
}
/* this device's share of the line, so a hog is visible without reading digits */
function spdBar(v){
  const cap = (S && S.rates && S.rates.src_down) || 31;
  if (!(+v > 0)) return '';
  const pct = Math.max(0, Math.min(100, (+v) / cap * 100));
  const hot = pct > 45 ? ' hot' : (pct > 20 ? ' warm' : '');
  return '<span class="sbar'+hot+'"><i style="width:'+pct.toFixed(1)+'%"></i></span>';
}
const sel = () => S.devices.filter(d=>picked.has(d.mac));

/* ---------------- progress ----------------
 *
 * An action is not finished when its POST returns: load(1) and loadLog()
 * run afterwards, and that is most of the wait. So the bar is driven by a
 * counter around whatever the caller considers the whole job - nested or
 * overlapping calls keep it up until the last one is done.
 */
let busyN = 0;

function progOn(){
  busyN++;
  let el = $('#prog');
  if (!el){
    el = document.createElement('div');
    el.className = 'prog'; el.id = 'prog';
    el.setAttribute('role','progressbar');
    el.setAttribute('aria-label','\u0628\u064a\u062d\u0645\u0651\u0644\u2026');
    el.innerHTML = '<i></i>';
    (document.getElementById('nc')||document.body).appendChild(el);
  }
  el.classList.add('on');
}

function progOff(){
  busyN = Math.max(0, busyN - 1);
  if (busyN === 0){
    const el = $('#prog');
    if (el) el.classList.remove('on');
  }
}

/*
 * Mark the control that was clicked, so the feedback is where the eye
 * already is. Returns a function that puts it back exactly as it was -
 * including a label we replaced - so a failed action leaves no trace.
 */
function working(btn){
  const row = btn && btn.closest ? btn.closest('tr') : null;
  let undo = [];
  progOn();
  if (btn){
    const had = btn.classList.contains('working');
    if (!had){
      btn.classList.add('working');
      const sp = document.createElement('span');
      sp.className = 'spin'; sp.setAttribute('aria-hidden','true');
      btn.prepend(sp);
      undo.push(()=>{ btn.classList.remove('working');
                      try{ sp.remove(); }catch(e){} });
    }
    if (btn.tagName === 'BUTTON' && !btn.disabled){
      btn.disabled = true;
      undo.push(()=>{ btn.disabled = false; });
    }
  }
  if (row && !row.classList.contains('working')){
    row.classList.add('working');
    undo.push(()=>row.classList.remove('working'));
  }
  let done = false;
  return function(){
    if (done) return;            /* idempotent: a caller may finish twice */
    done = true;
    undo.forEach(f=>{ try{ f(); }catch(e){} });
    progOff();
  };
}

/*
 * Run an async job with the progress showing, and always clear it.
 * Errors are re-thrown: the caller still decides what to tell the user.
 */
async function withProgress(btn, job){
  const stop = working(btn);
  try{ return await job(); }
  finally{ stop(); }
}

async function api(action, body){
  const o = {};
  if (body){
    o.method = 'POST';
    const f = new FormData();
    f.append('__csrf_magic', CSRF);
    f.append('action', action);
    for (const [k,v] of Object.entries(body)){
      if (Array.isArray(v)) v.forEach(x=>f.append(k+'[]', x));
      else f.append(k, v);
    }
    o.body = f;
  }
  return requestJSON(body ? API : API+'?action='+action, o);
}

async function requestJSON(url, options, read = false){
  let r, txt;
  try{
    r = await fetch(url, options);
    txt = await r.text();
  }catch(e){
    /* the network, not the server: the firewall may be reloading rules */
    apiFail('الاتصال بالسيرفر انقطع — حاول تاني بعد لحظة');
    throw e;
  }

  /*
   * Read the body as text first. A failing pfSense returns an HTML error
   * page, and calling r.json() on that throws a parse error that says
   * nothing useful - which is how a refused action became a button that
   * silently does nothing.
   */
  let d = null;
  try{ d = JSON.parse(txt); }catch(e){}

  if (!d || (read && !r.ok)){
    apiFail(r.status === 403
      ? 'انتهت صلاحية الجلسة — اعمل تحديث للصفحة (F5)'
      : (d && d.error) || 'السيرفر رجّع رد غير متوقع (' + r.status + ')');
    throw new Error('bad response ' + r.status);
  }
  if (!read && d.ok === false){
    apiFail(d.error || 'الطلب اترفض');
    /* returned, not thrown: callers that do check ok still see the object */
  }
  if (!read && options?.method === 'POST'){
    const status = $('#nc-change-status');
    status.classList.toggle('bad', d.ok === false);
    status.textContent = d.ok === false ? d.error || 'الطلب اترفض'
      : d.change_status === 'applied' ? 'تم تطبيق التغيير.'
      : d.change_status === 'saved' ? 'تم حفظ التغيير.' : '';
  }
  return d;
}

/* One place to report a failed call, so no caller can forget to. */
function apiFail(msg){
  try{ console.warn('netcontrol:', msg); }catch(e){}
  const box = $('#alerts');
  if (!box) { alert(msg); return; }
  const el = document.createElement('div');
  el.className = 'al crit';
  el.innerHTML = '<span style="font-size:15px">⚠</span>' +
                 '<div class="grow">' + esc(msg) + '</div>';
  box.prepend(el);
  setTimeout(()=>{ try{ el.remove(); }catch(e){} }, 9000);
}
async function get(action, qs){
  return requestJSON(API+'?action='+action+(qs?'&'+qs:''), undefined, true);
}

/* ---------------- load ---------------- */
let stateRequest = 0;
let statePending = 0;
let lastStateAt = null;
async function load(sample){
  const request = ++stateRequest;
  statePending++;
  try{
    const d = await get('state','sample='+(sample||1));
    if (request !== stateRequest) return;
    if(!d.ok) throw new Error(d.error||'fail');
    S = d;
    histD.push(d.rates.wan_in); histU.push(d.rates.wan_out);
    if(histD.length>12){histD.shift();histU.shift();}
    {
      const on=S.devices.filter(x=>x.online).length;
      const rnd=S.devices.filter(x=>x.online&&x.random).length;
      $('#live').innerHTML = (location.hostname?esc(location.hostname)+' · ':'')+'<b>'+on+'</b> جهاز أونلاين دلوقتي'+
        (rnd?' · '+rnd+' بـ MAC عشوائي':'');
      const lp=$('#livepill');
      lastStateAt = Number(d.now) || Date.now()/1000;
      monitorSamples.push({at:lastStateAt, down:Math.max(0, Number(d.rates.wan_in)||0),
        up:Math.max(0, Number(d.rates.wan_out)||0)});
      if (monitorSamples.length > 12) monitorSamples.shift();
      if(lp){ lp.className='livepill';
        lp.querySelector('span').textContent='مباشر · اتحدّث '+hhmm(lastStateAt); }
    }
    paint();
  }catch(e){
    if (request !== stateRequest) return;
    $('#live').innerHTML = '<strong style="color:#b3261e">فشل التحميل:</strong> '+esc(e.message);
    const lp=$('#livepill');
    if(lp){ lp.className='livepill bad'; lp.querySelector('span').textContent=lastStateAt
      ? 'بيانات قديمة · آخر تحديث '+hhmm(lastStateAt) : 'فشل التحميل'; }
  }finally{
    statePending--;
  }
}
async function loadStale(){
  const d = await get('stale','days='+age);
  drawStale(d.items||[]);
}
let LOGL=[];
/*
 * nc_log() writes a fixed-width line: timestamp, user, IP, then the message.
 * Splitting it into real columns makes the log scannable, and the message's
 * first word carries a colour so a block stands out from a rename.
 */
function logParse(line){
  const m = line.match(/^(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2}:\d{2})\s+(\S+)\s+(\S+)\s\s+([\s\S]*)$/);
  if(!m) return {raw:line};
  return {date:m[1], time:m[2], who:m[3], from:m[4], msg:m[5]};
}
const LOGK={
  BLOCK:'blk', POLICY:'blk', 'MAC-CHANGED':'wrn', 'REATTACH-UNDO':'wrn',
  UNBLOCK:'unb', 'PORTAL-RELEASE':'unb', EXPIRED:'unb',
  'IP-MOVED':'sys', RENAME:'sys', LIMIT:'sys', CLEAN:'sys', SITE:'sys',
  VIDEO:'sys', GLOBAL:'sys', GROUP:'sys', 'TICK-ERROR':'wrn'
};
function logRow(r){
  if(r.raw) return '<div class="lg"><div class="m" style="grid-column:1/-1">'+
    esc(r.raw)+'</div></div>';
  const first=(r.msg.split(/\s+/)[0]||'').toUpperCase();
  const kind=LOGK[first]||'';
  /* set MACs and IPs in mono so they read as identifiers, not as prose */
  const body=esc(r.msg)
    .replace(/\b([0-9a-f]{2}(?::[0-9a-f]{2}){5})\b/gi,'<code>$1</code>')
    .replace(/\b(\d{1,3}(?:\.\d{1,3}){3})\b/g,'<code>$1</code>');
  return '<div class="lg '+kind+'">'+
    '<div class="t">'+esc(r.date)+' '+esc(r.time)+'</div>'+
    '<div class="w">'+esc(r.who)+'</div>'+
    '<div class="f">'+(r.from==='-'?'':esc(r.from))+'</div>'+
    '<div class="m"><span class="k">'+esc(first)+'</span>'+body+'</div>'+
  '</div>';
}
function logRender(){
  const box=$('#logbox'); if(!box) return;
  const q=($('#logq')&&$('#logq').value.trim().toLowerCase())||'';
  const rows=LOGL.filter(r=>!q||(r.raw||(r.who+' '+r.from+' '+r.msg))
    .toLowerCase().includes(q));
  box.innerHTML = rows.length ? rows.map(logRow).join('')
    : '<div class="empty">'+(q?'مفيش نتايج للبحث ده':'مفيش إجراءات لسه')+'</div>';
}
let HIST = null, hview = 'users';

/*
 * Login and usage history.
 *
 * Logins come from the portal's own auth log, which records every accept and
 * every session it terminated. Usage comes from a daily tally the tick keeps,
 * because the live byte counters reset whenever a pipe is reconfigured and so
 * cannot answer "how much did this person use today".
 */
async function loadHist(){
  const box=$('#hbox'); if(!box) return;
  box.innerHTML='<div class="empty" style="padding:24px">\u0628\u064a\u062d\u0645\u0651\u0644\u2026</div>';
  try{
    HIST = await get('history');
    histRender();
  }catch(e){
    box.innerHTML='<div class="empty" style="padding:24px">\u0645\u0627\u0642\u062f\u0631\u0634 \u064a\u0642\u0631\u0627 \u0627\u0644\u0633\u062c\u0644</div>';
  }
}

function histRender(){
  const box=$('#hbox'); if(!box||!HIST) return;
  const q=($('#hq')&&$('#hq').value.trim().toLowerCase())||'';
  const T={users:'\u0627\u0644\u0645\u0633\u062a\u062e\u062f\u0645\u064a\u0646',
           logins:'\u0633\u062c\u0644 \u0627\u0644\u062f\u062e\u0648\u0644',
           usage:'\u0627\u0644\u0627\u0633\u062a\u0647\u0644\u0627\u0643 \u0627\u0644\u064a\u0648\u0645\u064a'};
  $('#htitle').textContent=T[hview]||'';

  if(hview==='users'){
    const rows=(HIST.users||[]).filter(u=>!q||u.user.toLowerCase().includes(q));
    $('#hsub').textContent=rows.length+' \u0645\u0633\u062a\u062e\u062f\u0645';
    box.innerHTML = rows.length ? rows.map(u=>`
      <div class="hrow">
        <div class="hmeta">
          <div class="hn">${esc(u.user)}
            ${u.devices>3?`<span class="tg hot">${u.devices} \u0623\u062c\u0647\u0632\u0629</span>`
                         :`<span class="tg">${u.devices} \u062c\u0647\u0627\u0632</span>`}
            ${u.rotating?`<span class="tg tmp" title="\u0627\u0644\u062c\u0647\u0627\u0632 \u0628\u064a\u063a\u064a\u0631 \u0627\u0644\u0640 MAC \u0643\u0644 \u0645\u0631\u0629 \u2014 \u0645\u0634 \u0645\u0634\u0627\u0631\u0643\u0629 \u062d\u0633\u0627\u0628">MAC \u0645\u062a\u063a\u064a\u0631 (${u.addresses})</span>`:''}
            ${u.kicked>5?`<span class="tg tmp">\u0627\u062a\u0642\u0637\u0639 ${u.kicked}\u00d7</span>`:''}
          </div>
          <div class="hw">${u.ips.slice(0,4).map(i=>`<span class="m">${esc(i)}</span>`).join(' ')}</div>
        </div>
        <div class="hb">${u.logins}<i>\u062f\u062e\u0648\u0644</i></div>
        <div class="ht">${u.last?dmhm(u.last):'\u2014'}</div>
      </div>`).join('')
      : '<div class="empty" style="padding:24px">\u0645\u0641\u064a\u0634 \u0646\u062a\u0627\u064a\u062c</div>';
    return;
  }

  if(hview==='logins'){
    const rows=(HIST.logins||[]).filter(e=>!q||
      ((e.user||'')+' '+(e.ip||'')+' '+(e.mac||'')).toLowerCase().includes(q));
    $('#hsub').textContent=rows.length+' \u062d\u062f\u062b';
    box.innerHTML = rows.length ? rows.map(e=>{
      const kick=/TERMINAT|CONCURRENT/i.test(e.kind||'');
      return `<div class="hrow${kick?' kick':''}">
        <div class="ht">${e.at?dmhm(e.at):'\u2014'}</div>
        <div class="hmeta">
          <div class="hn">${esc(e.user||'\u2014')}
            <span class="tg ${kick?'hot':'cpu'}">${
              kick?'\u0627\u062a\u0642\u0637\u0639':'\u062f\u062e\u0644'}</span></div>
          <div class="hw"><span class="m">${esc(e.ip||'-')}</span>
            <span class="m">${esc(e.mac||'-')}</span></div>
        </div>
      </div>`;}).join('')
      : '<div class="empty" style="padding:24px">\u0645\u0641\u064a\u0634 \u0646\u062a\u0627\u064a\u062c</div>';
    return;
  }

  const days=HIST.usage||[];
  $('#hsub').textContent=days.length+' \u064a\u0648\u0645';
  box.innerHTML = days.length ? days.map(d=>`
    <div class="hday">
      <div class="hdh"><b>${esc(d.day)}</b>
        <span>${d.devices} \u062c\u0647\u0627\u0632 \u00b7 ${mb(d.total)}</span></div>
      ${d.items.filter(x=>!q||((x.name||'')+' '+(x.ip||'')+' '+(x.mac||''))
        .toLowerCase().includes(q))
        .map(x=>`<div class="hrow">
          <div class="hmeta">
            <div class="hn">${esc(x.name||x.mac||x.ip)}</div>
            <div class="hw">${x.ip?`<span class="m">${esc(x.ip)}</span>`:''}
              <span class="m">${esc(x.mac||'')}</span></div>
          </div>
          <div class="hb">${mb(x.bytes)}</div>
        </div>`).join('')}
    </div>`).join('')
    : '<div class="empty" style="padding:24px">'+
      '\u0644\u0633\u0647 \u0645\u0627\u0641\u064a\u0634 \u0628\u064a\u0627\u0646\u0627\u062a \u2014 \u0628\u062a\u062a\u062c\u0645\u0639 \u0643\u0644 \u062f\u0642\u064a\u0642\u0629</div>';
}

async function loadLog(){
  const d = await get('log');
  /* newest first: the file appends, so the last line is the most recent */
  LOGL = (d.lines||[]).map(logParse).reverse();
  logRender();
}


/* ---------------- paint ---------------- */
function paint(){ glim(); speed(); alerts(); devices(); groups(); sched(); sites(); video(); att(); renderMonitor(); }

/* ---- network-wide per-device ceiling ---- */
let gD=null, gU=null, gEdit=false;
function glim(){
  const g=S.global||{}, r=S.rates||{};
  if(gD===null){ gD=g.down||5; gU=g.up||2; }
  const cap=r.src_down||31, capU=r.src_up||5;
  const nD=g.devices_down||0, nU=g.devices_up||0;
  /* how the ceiling relates to what the line can actually carry */
  const hint=(mb,n,c)=>{
    if(!n) return ['','مفيش أجهزة على الحد ده دلوقتي'];
    const want=mb*n;
    if(want<=c)      return ['',      `${n} جهاز × ${mb} = ${want.toFixed(0)} من ${c.toFixed(0)} — الخط يستحمل`];
    if(want<=c*2.5)  return ['warn',  `${n} جهاز × ${mb} = ${want.toFixed(0)} من ${c.toFixed(0)} — تنافس عادي`];
    return ['crit', `${n} جهاز × ${mb} = ${want.toFixed(0)} من ${c.toFixed(0)} — الحد أعلى من الخط بكتير`];
  };
  const [cD,tD]=hint(gD,nD,cap), [cU,tU]=hint(gU,nU,capU);
  const dirty = (gD!==(g.down||5) || gU!==(g.up||2));
  const openEd = gEdit || dirty;
  $('#glim').innerHTML=`
    <div class="gl2">
      <span class="gic"><i class="fa fa-sliders"></i></span>
      <div class="gtx"><h4>الحد العام لكل جهاز</h4>
        <span class="sub">بيتطبق على كل الأجهزة اللي مالهاش حد خاص · ${nD} جهاز على الحد دلوقتي</span></div>
      <span class="gchip dn"><b>↓</b> ${g.down?g.down+' Mb':'بلا حد'}</span>
      <span class="gchip up"><b>↑</b> ${g.up?g.up+' Mb':'بلا حد'}</span>
      <button type="button" class="btn2" id="gedit">${openEd?'إغلاق':'تعديل'}</button>
    </div>
    <div class="gr"${openEd?'':' hidden'}>
      <div class="gf">
        <label>النزول (تصفح وتنزيل)</label>
        <div class="gin">
          <input type="range" id="grD" min="1" max="30" step="1" value="${gD}">
          <span class="gv" id="gvD">${gD}<small> Mb</small></span>
        </div>
        <span class="ghint ${cD}" id="ghD">${tD}</span>
      </div>
      <div class="gf">
        <label>الرفع</label>
        <div class="gin">
          <input type="range" id="grU" min="1" max="20" step="1" value="${gU}">
          <span class="gv" id="gvU">${gU}<small> Mb</small></span>
        </div>
        <span class="ghint ${cU}" id="ghU">${tU}</span>
      </div>
      <div style="display:flex;gap:7px;align-items:center">
        <button class="btn btn-sm btn-primary" id="gsave" ${dirty?'':'disabled'}>حفظ</button>
        ${dirty?'<button class="btn btn-sm btn-default" id="greset">رجوع</button>':''}
      </div>
    </div>`;
  const upd=(which)=>{
    const v=+$('#gr'+which).value;
    if(which==='D') gD=v; else gU=v;
    $('#gv'+which).innerHTML=v+'<small> Mb</small>';
    const [c,t]=hint(v, which==='D'?nD:nU, which==='D'?cap:capU);
    const h=$('#gh'+which); h.className='ghint '+c; h.textContent=t;
    $('#gsave').disabled = (gD===(g.down||5) && gU===(g.up||2));
  };
  $('#gedit').onclick=()=>{
    gEdit=!openEd;
    if(!gEdit){ gD=g.down||5; gU=g.up||2; }
    glim();
  };
  $('#grD').oninput=()=>upd('D');
  $('#grU').oninput=()=>upd('U');
  $('#gsave').onclick=()=>{
    pend={kind:'global',down:gD,up:gU};
    sheet('تغيير الحد العام', `${nD} جهاز متأثر`,
      `كل جهاز هياخد لحد <b>${gD} ميجا</b> نزول و <b>${gU} ميجا</b> رفع.<br>
       الأجهزة اللي ليها حد خاص مش هتتأثر.`,
      '',
      `dnctl pipe 22 config bw ${gD}Mbit/s mask dst-ip 0xffffffff
`+
      `dnctl pipe 23 config bw ${gU}Mbit/s mask src-ip 0xffffffff`,
      'تطبيق');
  };
  const gr=$('#greset'); if(gr) gr.onclick=()=>{ gD=g.down||5; gU=g.up||2; glim(); };
}

function speed(){
  const r=S.rates||{}, sd=r.src_down||31, su=r.src_up||5;
  const win=+r.wan_in||0, wout=+r.wan_out||0;
  const pd=Math.min(100,win/sd*100), pu=Math.min(100,wout/su*100);
  const col=p=>p>=88?'#dc2626':p>=60?'#c2410c':'#15803d';
  const bar=p=>'<div class="br"><div class="tr2"><i style="width:'+Math.max(p,1).toFixed(1)+
    '%;background:'+col(p)+'"></i></div><span class="pc" style="color:'+col(p)+'">'+
    p.toFixed(0)+'%</span></div>';
  const nb=S.devices.filter(d=>d.blocked).length;
  const nl=S.devices.filter(d=>d.limit&&!d.blocked).length;
  const nt=S.devices.filter(d=>d.expires).length;
  const st=S.system||{};
  $('#sp').innerHTML=`
   <div class="k"><div class="l">سرعة الخط</div>
     <div class="v">${sd.toFixed(0)}<small>/ ${su.toFixed(0)} Mb</small></div>
     <div class="s">سعة النزول / الرفع</div></div>
   <div class="k"><div class="l">الوارد من الإنترنت</div>
     <div class="v">${win.toFixed(1)}<small>Mb</small></div>${bar(pd)}
     <div class="s">${Math.max(0,sd-win).toFixed(1)} Mb متاح</div></div>
   <div class="k"><div class="l">الصادر للإنترنت</div>
     <div class="v">${wout.toFixed(1)}<small>Mb</small></div>${bar(pu)}
     <div class="s">${Math.max(0,su-wout).toFixed(1)} Mb متاح</div></div>
   <div class="k"><div class="l">الشبكة الداخلية</div>
     <div class="v">${(+r.lan_out||0).toFixed(1)}<small>Mb</small></div>
     <div class="s">${(+r.lan_in||0).toFixed(1)} Mb منها رايح للأجهزة</div></div>
   <div class="k"><div class="l">موقوف / محدود</div>
     <div class="v">${nb} <span class="sl">/</span> ${nl}</div>
     <div class="s">${nt} قواعد مؤقتة</div></div>
   <div class="k"><div class="l">جلسات (PF)</div>
     <div class="v">${((st.states||0)/1000).toFixed(1)}<small>k</small></div>
     <div class="s">من ${((st.states_limit||98000)/1000).toFixed(0)}k${
       st.rtt!=null?' · '+(+st.rtt).toFixed(1)+' ms':''}</div></div>`;
}

function liveLoad(d){
  const downCapacity = Number(S.rates?.src_down);
  const upCapacity = Number(S.rates?.src_up);
  return Math.max(downCapacity > 0 ? dnOf(d) / downCapacity : 0,
    upCapacity > 0 ? (+d.rate_up || 0) / upCapacity : 0);
}

function isHeavyNow(d){
  return d.online && !d.blocked && !d.protected && liveLoad(d) >= 0.5;
}

function alerts(){
  const box=$('#alerts'); box.innerHTML='';
  const heavy=S.devices.filter(isHeavyNow).sort((a,b)=>liveLoad(b)-liveLoad(a));
  heavy.slice(0,2).forEach(d=>{
    const el=document.createElement('div'); el.className='al';
    el.innerHTML=`<span style="font-size:15px">⬇</span>
      <div class="grow">
        <div class="al-t">استخدام لحظي مرتفع — <bdi>↓ ${r1(dnOf(d))} · ↑ ${r1(+d.rate_up||0)} Mb/s</bdi></div>
        <div class="al-id"><span class="al-n">${esc(d.name||d.ip)}</span>
          <span>·</span><span>${esc(d.ip)}</span>
          <span>·</span><span class="${d.random?'r':''}">${esc(d.mac)}</span>
          ${d.random?'<span class="tg ipb">MAC عشوائي</span>':''}
          <span class="tg">${esc(d.type_name||'')}</span>
          <span class="tg">الحد: ${fmtL(d.limit)}</span></div>
      </div>
      <button class="btn btn-xs btn-default" data-a="goto" data-m="${esc(d.mac)}">اعرضه</button>
      <button class="btn btn-xs btn-default" data-a="lim1" data-m="${esc(d.mac)}">حدّه 1 ميجا</button>
      <button class="btn btn-xs btn-danger" data-a="blk" data-m="${esc(d.mac)}">أوقفه</button>`;
    box.appendChild(el);
  });
  const tmp=S.devices.filter(d=>d.expires);
  if(tmp.length){
    const el=document.createElement('div'); el.className='al info';
    /*
     * The count and the soonest expiry, not every rule inline: listing all
     * of them put a paragraph of twenty-three entries across the top of the
     * page. The detail is one click away in the Blocked tab.
     */
    const soon=Math.min.apply(null,tmp.map(d=>d.expires));
    el.innerHTML=`<span style="font-size:15px">⏱</span><div class="grow">
      <b>${tmp.length}</b> قاعدة مؤقتة شغالة — أقربها بتنتهي ${hhmm(soon)}</div>
      <button class="btn btn-xs btn-default" data-gotab="blocked">التفاصيل</button>`;
    box.appendChild(el);
  }
  const moved=S.devices.filter(d=>d.moved);
  if(moved.length){
    const el=document.createElement('div'); el.className='al info';
    el.innerHTML=`<span style="font-size:15px">⇄</span><div class="grow">
      <b>${moved.length}</b> جهاز الـ IP بتاعه اتغير — القواعد اتنقلت تلقائيًا معاه بالـ MAC</div>`;
    box.appendChild(el);
  }
  /*
   * Only the devices we genuinely cannot follow are worth a warning. One
   * with a fingerprint is re-blocked automatically when its MAC rotates, so
   * counting it here overstated the problem and the banner never changed,
   * which is how a warning stops being read.
   */
  /*
   * A blocked device that is still moving data means the rule is not being
   * enforced. The page saying "blocked" while the traffic flows is exactly
   * the failure that goes unnoticed, so it gets its own alert instead of a
   * flag somebody has to spot in a column.
   */
  const leak=S.devices.filter(d=>d.blocked && +d.rate>0.05);
  if(leak.length){
    const el=document.createElement('div'); el.className='al crit';
    el.innerHTML=`<span style="font-size:15px">⛔</span><div class="grow">
      <b>${leak.length}</b> جهاز موقوف ولسه بيسحب داتا — الحظر مش بيتنفّذ عليه</div>
      <button class="btn btn-xs btn-default" data-a="leak">عرضهم</button>`;
    box.appendChild(el);
  }

  const weak=S.devices.filter(d=>d.blocked&&d.random&&!d.fp);
  if(weak.length){
    const el=document.createElement('div'); el.className='al crit';
    el.innerHTML=`<span style="font-size:15px">⚠</span><div class="grow">
      <b>${weak.length}</b> جهاز محظور ممكن يفلت — MAC عشوائي ومافيش اسم ثابت نتابعه بيه</div>
      <button class="btn btn-xs btn-default" data-a="weak">عرضهم</button>`;
    box.appendChild(el);
  }
}
$('#alerts').addEventListener('click',e=>{
  const b=e.target.closest('[data-a]'); if(!b) return;
  if(b.dataset.a==='leak'){
    showTab('dev'); filt='leak'; $('#q').value='';
    $$('#t-dev [data-f]').forEach(x=>x.classList.remove('on'));
    devices();
    return;
  }
  if(b.dataset.a==='weak'){
    showTab('dev'); filt='weak'; $('#q').value='';
    $$('#t-dev [data-f]').forEach(x=>x.classList.remove('on'));
    devices();
    return;
  }
  const d=S.devices.find(x=>x.mac===b.dataset.m); if(!d) return;
  if(b.dataset.a==='goto'){
    showTab('dev'); filt='all'; $('#q').value='';
    if(grouping) curG=grpOf(d);
    pageAll.add(grouping?curG:'*');
    $$('#t-dev [data-f]').forEach(x=>x.classList.toggle('on',x.dataset.f==='all'));
    devices();
    const tr=$('#tb').querySelector('[data-mac="'+CSS.escape(d.mac)+'"]');
    if(tr){tr.scrollIntoView({block:'center',behavior:'smooth'});
      tr.classList.add('flash'); setTimeout(()=>tr.classList.remove('flash'),1700);}
    return;
  }
  if(b.dataset.a==='lim1') askLimit(d,1,true); else askBlock(d);
});

function rows(){
  const q=$('#q').value.trim().toLowerCase();
  return S.devices.filter(d=>{
    if(d.hidden_offline) return false;
    if(activeGrp && d.group!==activeGrp) return false;
    if(q && !((d.name||'').toLowerCase().includes(q)||(d.hostname||'').toLowerCase().includes(q)||(d.vendor||'').toLowerCase().includes(q)||(d.type_name||'').includes(q)||d.ip.includes(q)||d.mac.includes(q))) return false;
    if(filt==='now')  return +d.rate > 0.1;
    if(filt==='offline') return !d.online;
    if(filt==='dl')   return d.online && !d.blocked && dnOf(d)>=1;
    if(filt==='busy') return isHeavyNow(d);
    if(filt==='lim')  return d.limit && !d.blocked;
    if(filt==='blk')  return d.blocked;
    if(filt==='tmp')  return !!d.expires;
    if(filt==='cp')   return !!d.cp_user;
    if(filt==='nocp') return !d.cp_user && !d.protected;
    if(filt==='leak') return d.blocked && +d.rate>0.05;
    if(filt==='weak') return d.blocked && d.random && !d.fp;
    if(filt==='rnd')  return d.random;
    return true;
  });
}
/*
 * What goes on a device's first line.
 *
 * A DHCP hostname like "desktop-v0kjb9l" names a machine, not a person, and
 * tells an admin nothing when they are deciding whether to cut someone off.
 * The portal login is a real person, so it wins over a generic hostname. The
 * hostname still shows underneath, so promoting the person loses nothing.
 *
 * Order: a name the admin typed, else the portal login when the hostname is
 * generic, else the hostname, else the IP.
 */
const GENERIC=/^(desktop|laptop|pc|localhost|android|iphone|ipad|win|user)[-_]?[a-z0-9]*$/i;
function devLabel(d){
  const h=d.hostname||'';
  /*
   * The backend copies the hostname into 'name' when nobody has set one, so
   * a non-empty 'name' does not by itself mean an admin typed it. Only a
   * name that differs from the hostname is a real custom name; otherwise
   * this check would always win and the portal login would never show.
   */
  if(d.name && d.name!==h) return d.name;
  if(d.cp_user && (!h || GENERIC.test(h))) return d.cp_user;
  return h || d.cp_user || d.ip;
}
/* the quieter second line: the hostname, unless it is already on the first */
function devSub(d){
  const h=d.hostname||'';
  return (h && h!==devLabel(d)) ? h : '';
}
const ICO={
  phone:'<i class="fa fa-mobile"></i>', mobile:'<i class="fa fa-mobile"></i>',
  pc:'<i class="fa fa-desktop"></i>',   laptop:'<i class="fa fa-laptop"></i>',
  tv:'<i class="fa fa-tv"></i>',        cam:'<i class="fa fa-video-camera"></i>',
  vm:'<i class="fa fa-clone"></i>',     router:'<i class="fa fa-wifi"></i>',
  printer:'<i class="fa fa-print"></i>',iot:'<i class="fa fa-microchip"></i>'};
/*
 * Sort and column width.
 *
 * Both are per-admin preferences, not shared state, so they live in
 * localStorage. Every read is wrapped: a private window or blocked site data
 * makes these throw, and the table must still render.
 */
let sortBy='bytes', sortDir=-1;
try{
  const sv=localStorage.getItem('nc_sort');
  if(sv){ const o=JSON.parse(sv); sortBy=o.by||sortBy; sortDir=o.dir||sortDir; }
}catch(e){}

const SORTV={
  name:  d=>(devLabel(d)||'').toLowerCase(),
  ip:    d=>(d.ip||'').split('.').reduce((a,x)=>a*256+(+x||0),0),
  user:  d=>(d.cp_user||'').toLowerCase(),
  rate:  d=>+(d.rate_dn!=null?d.rate_dn:d.rate)||0,
  bytes: d=>+d.bytes||0,
  limit: d=>d.limit?+d.limit:999,   /* "no limit" sorts last, not as zero */
  state: d=>d.blocked?2:(d.online?0:1)
};

function sortRows(list){
  const f=SORTV[sortBy]; if(!f) return list;
  /* slice() so the filter's array is not reordered underneath us */
  return list.slice().sort((a,b)=>{
    const x=f(a), y=f(b);
    if(x<y) return -sortDir;
    if(x>y) return sortDir;
    return 0;
  });
}

function markSort(){
  $$('#t-dev th[data-sort]').forEach(th=>{
    const on = th.dataset.sort===sortBy;
    th.classList.toggle('sorted', on);
    th.setAttribute('aria-sort', on?(sortDir>0?'ascending':'descending'):'none');
    const arrow=th.querySelector('.nc-sort-arrow');
    if(arrow) arrow.textContent=on?(sortDir>0?'↑':'↓'):'↕';
  });
}

function bindSort(){
  $$('#t-dev th[data-sort]').forEach(th=>{
    const button=document.createElement('button');
    button.type='button';
    button.className='nc-table-sort';
    button.append(...th.childNodes);
    const arrow=document.createElement('span');
    arrow.className='nc-sort-arrow';
    arrow.setAttribute('aria-hidden','true');
    button.append(arrow);
    th.append(button);
    th.setAttribute('scope','col');
    th.addEventListener('click', e=>{
      if(e.target.classList.contains('rsz')) return;   /* that is a drag */
      const k=th.dataset.sort;
      /* text starts A-Z; numbers start biggest-first, which is what you want */
      if(sortBy===k){ sortDir=-sortDir; }
      else{ sortBy=k; sortDir=(k==='name'||k==='ip'||k==='user')?1:-1; }
      try{ localStorage.setItem('nc_sort',JSON.stringify({by:sortBy,dir:sortDir})); }
      catch(e){}
      devices();
    });
  });
}

/* ---- column resizing ------------------------------------------- */
function applyWidths(){
  let w={}; try{ w=JSON.parse(localStorage.getItem('nc_cols2')||'{}'); }catch(e){}
  const cols=$$('#t-dev colgroup col');
  Object.entries(w).forEach(([i,px])=>{
    if(cols[i]) cols[i].style.width=px+'px';
  });
}

function bindResize(){
  const ths=$$('#t-dev thead th');
  const cols=$$('#t-dev colgroup col');
  ths.forEach((th,i)=>{
    /* the last column has nothing to its side to give or take space */
    if(i>=ths.length-1 || th.querySelector('.rsz')) return;
    const grip=document.createElement('span');
    grip.className='rsz'; grip.title='اسحب لتغيير عرض العمود';
    th.appendChild(grip);
    grip.addEventListener('mousedown', ev=>{
      ev.preventDefault(); ev.stopPropagation();
      const x0=ev.clientX, w0=th.getBoundingClientRect().width;
      document.body.classList.add('rszing');
      const move=e2=>{
        /* RTL: dragging right narrows the column, so the delta is inverted */
        const w=Math.max(44, w0 + (x0 - e2.clientX));
        if(cols[i]) cols[i].style.width=w+'px';
      };
      const up=()=>{
        document.removeEventListener('mousemove',move);
        document.removeEventListener('mouseup',up);
        document.body.classList.remove('rszing');
        let w={}; try{ w=JSON.parse(localStorage.getItem('nc_cols2')||'{}'); }catch(e){}
        if(cols[i]) w[i]=parseInt(cols[i].style.width,10);
        try{ localStorage.setItem('nc_cols2',JSON.stringify(w)); }catch(e){}
      };
      document.addEventListener('mousemove',move);
      document.addEventListener('mouseup',up);
    });
    grip.addEventListener('dblclick', ev=>{
      ev.stopPropagation();
      if(cols[i]) cols[i].style.width='';
      let w={}; try{ w=JSON.parse(localStorage.getItem('nc_cols2')||'{}'); }catch(e){}
      delete w[i];
      try{ localStorage.setItem('nc_cols2',JSON.stringify(w)); }catch(e){}
    });
  });
}

/*
 * The blocked devices, on their own.
 *
 * They are grouped by why they are blocked: a policy block is routine and
 * lifts itself when the user signs in, while an admin block is a decision
 * somebody made and nothing undoes it automatically. Mixing the two made it
 * impossible to see which was which.
 */
function blockedRows(){
  const q=($('#bq')&&$('#bq').value.trim().toLowerCase())||'';
  return S.devices.filter(d=>{
    if(!d.blocked) return false;
    if(!q) return true;
    return ((devLabel(d)||'')+' '+d.ip+' '+d.mac+' '+(d.cp_user||''))
      .toLowerCase().includes(q);
  });
}

function blocked(){
  const box=$('#blist'); if(!box) return;
  const list=blockedRows();
  const byPol=list.filter(d=>d.by_policy).length;
  const weak =list.filter(d=>d.random&&!d.fp).length;
  const sum=$('#bsum');
  if(sum) sum.innerHTML = list.length
    ? `<b>${list.length}</b> موقوف · ${byPol} بالسياسة · `+
      (weak?`<span style="color:var(--crit)">${weak} ممكن يفلت</span>`
           :'كلهم متابَعين')
    : 'مفيش أجهزة موقوفة';
  if(!list.length){
    box.innerHTML='<div class="empty" style="padding:30px">'+
      (($('#bq')&&$('#bq').value.trim())?'مفيش نتايج':'مفيش أجهزة موقوفة')+'</div>';
    $('#c-blk').textContent='0';
    return;
  }
  box.innerHTML = list.map(d=>`
    <div class="brow">
      <span class="dvbadge">${ICO[d.type]||'<i class="fa fa-desktop"></i>'}</span>
      <div class="bmeta">
        <div class="bn">${esc(devLabel(d))}
          ${d.by_policy?'<span class="tg">بالسياسة</span>'
                       :'<span class="tg prot">يدوي</span>'}
          ${d.random?(d.fp
            ?'<span class="tg">متابَع · '+esc(d.fp)+'</span>'
            :'<span class="tg hot">ممكن يفلت</span>'):''}
          ${d.expires?'<span class="tg tmp">⏱ حتى '+
            hhmm(d.expires)+'</span>':''}</div>
        <div class="bw"><span class="m">${esc(d.ip)}</span>
          <span class="m">${esc(d.mac)}</span>
          ${d.cp_user?`<span class="tg cpu">${esc(d.cp_user)}</span>`:''}</div>
      </div>
      <div class="bacts">
        <button class="btn btn-xs btn-success" data-bun="${esc(d.mac)}">تشغيل</button>
        <button class="btn btn-xs btn-default" data-bpr="${esc(d.mac)}">التفاصيل</button>
      </div>
    </div>`).join('');
  $('#c-blk').textContent=S.devices.filter(d=>d.blocked).length;
}

/*
 * The device list, laid out like a dashboard: one category at a time, picked
 * from a row of chips, and only the first PAGE rows of it until asked for
 * more. Ninety rows on one screen was the complaint; a category is a short
 * list, and the chip counts still say where everything else is.
 */
const PAGE = 10;
let curG = null, effG = null, lastShown = [];
const pageAll = new Set();
try{ curG = localStorage.getItem('nc_cur_grp'); }catch(e){}
const FMENU = {lim:'محدودة', blk:'موقوفة', tmp:'مؤقتة', cp:'مسجّل دخول',
               nocp:'مش مسجّل', rnd:'MAC عشوائي'};
function filtUI(){
  $$('#t-dev [data-f]').forEach(x=>x.classList.toggle('on', x.dataset.f===filt));
  const fb=$('#fbtn'); if(!fb) return;
  const m=FMENU[filt];
  fb.classList.toggle('on', !!m);
  $('#fhint').textContent = m ? m : Object.values(FMENU).join(' · ');
  $('#glbl').textContent = grouping ? 'تجميع: النوع' : 'من غير تجميع';
  $$('#gmenu [data-g]').forEach(x=>x.classList.toggle('on', (x.dataset.g==='1')===grouping));
}
const r1 = v => (+v||0).toFixed(1);
const dnOf = d => d.rate_dn!=null ? +d.rate_dn : +d.rate||0;
function stPill(d){
  if(d.blocked)
    return '<span class="pl blk">موقوف</span>'+((+d.rate>0.05)
      ?' <span class="tg hot" title="موقوف ومع ذلك بيمرر داتا">⚠ بيعدّي</span>':'');
  if(d.ip_mac_conflict)  return '<span class="pl mac-conflict">IP على MAC مختلف</span>';
  if(!d.online)          return '<span class="pl off">أوفلاين</span>';
  if(d.limit)            return '<span class="pl lim">محدود</span>';
  if(dnOf(d)>=1)         return '<span class="pl dl">بينزّل</span>';
  return '<span class="pl on">أونلاين</span>';
}
function speedCell(d){
  if(!d.online) return '<span class="faint">—</span>';
  const dn=dnOf(d), up=+d.rate_up||0;
  return '<div class="sp2"><span class="dn'+(dn>=1?' on':'')+'">↓ '+r1(dn)+' Mb</span>'+
         '<span class="up">↑ '+r1(up)+' Mb</span></div>';
}
function limCell(d){
  if(d.protected) return '<span class="faint">معفى</span>';
  if(d.blocked)   return '<span class="faint">—</span>';
  if(d.limit)     return '<bdi>↓ '+d.limit+' · ↑ '+(d.limit_up ?? Math.max(1, Math.round(d.limit/2)))+' Mb/s</bdi>';
  const g=(S.global||{}).down;
  return g ? '<span class="gl3">'+g+' Mb <span class="faint">(عام)</span></span>'
           : '<span class="faint">بلا حد</span>';
}
function badges(d){
  let h='';
  if(d.protected) h+='<span class="tg prot">نظام</span>';
  if(d.reserved) h+=(d.res_ip&&d.ip&&d.res_ip!==d.ip)
    ? '<span class="tg ipb" title="المحجوز '+esc(d.res_ip)+
      ' لكن الجهاز على '+esc(d.ip)+
      '">ثابت ≠ '+esc(d.res_ip)+'</span>'
    : '<span class="tg fix" title="عنوان محجوز للجهاز ده">'+
      'ثابت</span>';
  if(d.random) h+=d.fp
    ? '<span class="tg rnd" title="بنتابعه باسم '+esc(d.fp)+'">عشوائي · متابَع</span>'
    : '<span class="tg rnd">MAC عشوائي</span>';
  if(d.expires) h+='<span class="tg tmp">⏱ '+hhmm(d.expires)+'</span>';
  if(d.moved)   h+='<span class="tg ipb">IP اتغير</span>';
  return h;
}
function grpStats(arr, inGroup){
  const on=arr.filter(d=>d.online).length;
  const can=arr.some(d=>!d.protected);
  return '<div class="cs"><span><b>'+on+' أونلاين</b> · '+arr.length+' '+
    (inGroup?'جهاز في المجموعة دي':'جهاز')+'</span>'+
    (inGroup&&can?'<button type="button" class="btn2 pri" id="glimbtn">حد للمجموعة</button>':'')+
    '</div>';
}

let inspectedMac = null;
let inspectedField = null;
const inspector = $('#nc-inspector');

function inspectButton(mac, field, value){
  if (!value) return '—';
  return '<span class="nc-classic-name">'+esc(value)+'</span>'+
    '<button type="button" class="nc-inspect" data-inspect="'+esc(mac)+'" data-inspect-field="'+field+
    '" aria-haspopup="dialog" aria-controls="nc-inspector">'+esc(value)+'</button>';
}

function renderInspector(){
  const device = S.devices.find(d=>d.mac===inspectedMac);
  if (!device){
    if (inspector.open) inspector.close();
    return false;
  }
  const address = value => value ? '<bdi class="m">'+esc(value)+'</bdi>'+
    '<button type="button" class="nc-copy" data-copy="'+esc(value)+'">نسخ</button>' : '';
  const rule = device.blocked || device.limit !== null && device.limit !== undefined;
  const blockStatus = device.block_applied === true ? 'الحظر موجود في جدول الفايروول.'
    : device.block_applied === false ? 'الحظر محفوظ لكن عنوان الجهاز مش في جدول الفايروول.'
    : 'حالة تطبيق الحظر غير مؤكدة.';
  const expiry = device.expires ? '<bdi>'+esc(dmhm(device.expires))+'</bdi>' : 'دائم';
  $('#nc-inspector-content').innerHTML =
    '<div class="nc-device-summary"><div class="nc-device-identity"><h3 class="nm">'+esc(devLabel(device))+
    '</h3>'+stPill(device)+'</div><div class="nc-device-addresses">'+
    address(device.ip)+address(device.mac.toUpperCase())+'</div></div>'+
    (rule ? '<section class="nc-rule-state"><strong>حالة القاعدة</strong>'+
        (device.blocked ? '<p>'+blockStatus+'</p><p>'+
          (device.by_policy ? 'المصدر: سياسة الأجهزة الجديدة' : 'المصدر: إجراء يدوي')+'</p>' : '')+
        '<p>انتهاء القاعدة <span>'+expiry+'</span></p></section>' : '')+detailHTML(device);
  return true;
}

$('#tb').addEventListener('click', e=>{
  const button = e.target.closest('[data-inspect]');
  if (!button) return;
  inspectedMac = button.dataset.inspect;
  inspectedField = button.dataset.inspectField;
  $('#nc-copy-status').textContent = '';
  if (renderInspector()) inspector.showModal();
});
inspector.addEventListener('click', async e=>{
  if (e.target===inspector) inspector.close();
  const button = e.target.closest('[data-copy]');
  if (!button) return;
  let copied = false;
  try {
    await navigator.clipboard.writeText(button.dataset.copy);
    copied = true;
  } catch(e) {
    const range = document.createRange();
    range.selectNodeContents(button.previousElementSibling);
    const selection = window.getSelection();
    selection.removeAllRanges();
    selection.addRange(range);
    copied = document.execCommand('copy');
    if (copied) selection.removeAllRanges();
  }
  $('#nc-copy-status').textContent = copied ? 'تم النسخ.' : 'العنوان متحدد للنسخ اليدوي.';
});
inspector.addEventListener('close', ()=>{
  const trigger = $$('#tb [data-inspect]').find(button=>
    button.dataset.inspect===inspectedMac && button.dataset.inspectField===inspectedField);
  inspectedMac = null;
  inspectedField = null;
  (trigger || $('#q')).focus();
});

const canCleanOffline = d=>!d.online && !d.protected && !d.ip_mac_conflict && !d.hidden_offline;

function devices(){
  $('#clean-offline').disabled = !S.devices.some(canCleanOffline);
  $('#clean-offline-selected').disabled = !S.devices.some(d=>canCleanOffline(d) && picked.has(d.mac));
  const tb=$('#tb'); tb.innerHTML='';
  filtUI();
  const list=sortRows(rows());
  markSort();

  let shown=list, gkey='*', glabel='';
  const chips=$('#chips');
  if(grouping){
    const cnt={};
    list.forEach(d=>{ const k=grpOf(d); cnt[k]=(cnt[k]||0)+1; });
    const present=GRP.filter(([k])=>cnt[k]);
    /*
     * The chosen category may be empty under this search or filter: show the
     * first one that is not, but keep the choice, so clearing the search
     * comes back to where the admin was.
     */
    effG = present.some(([k])=>k===curG) ? curG : (present.length ? present[0][0] : null);
    gkey = effG || '*';
    glabel = (GRP.find(g=>g[0]===effG)||[])[1] || '';
    shown = effG ? list.filter(d=>grpOf(d)===effG) : [];
    chips.innerHTML = present.map(([k,l])=>
      '<button type="button" class="chip'+(k===effG?' on':'')+'" data-chip="'+k+'">'+
      esc(l)+' <i>'+cnt[k]+'</i></button>').join('') + grpStats(shown, true);
  } else {
    chips.innerHTML = grpStats(shown, false);
  }
  lastShown = shown;

  const all = pageAll.has(gkey);
  const view = all ? shown : shown.slice(0, PAGE);
  view.forEach(d=>{
    const heavy=isHeavyNow(d);
    const m=esc(d.mac);
    const tr=document.createElement('tr');
    tr.dataset.mac=d.mac;
    tr.className=(d.blocked?'blk ':'')+(picked.has(d.mac)?'sel ':'')+(heavy?'heavy':'');
    tr.innerHTML=`
      <td class="c">${d.protected?'':`<input type="checkbox" data-s="${m}" ${
        picked.has(d.mac)?'checked':''}>`}</td>
      <td class="s"><div class="dv"${devSub(d)?` title="${esc(devSub(d))}"`:''}>
        <span class="nm"><span class="t">${inspectButton(d.mac,'name',devLabel(d))}</span>${d.protected?'':
          `<button class="ren" data-ren="${m}" title="سمّي الجهاز" aria-label="سمّي الجهاز">✎</button>`}</span>
        <div class="mac2"><span class="${d.random?'r':''}">${inspectButton(d.mac,'mac',(d.mac||'').toUpperCase())}</span>${badges(d)}</div>
      </div></td>
      <td class="s"><span class="m">${inspectButton(d.mac,'ip',d.ip)}</span></td>
      <td class="s">${d.cp_user?esc(d.cp_user):'<span class="faint">مش مسجّل</span>'}</td>
      <td class="num${heavy?' hot':''}">${speedCell(d)}</td>
      <td class="num">${mb(d.bytes)}</td>
      <td class="c">${stPill(d)}</td>
      <td class="c">${limCell(d)}</td>
      <td class="c act">${d.protected
        ? '<span class="faint" style="font-size:12px">محمي</span>'+
          `<button class="ib" data-act="prof" data-m="${m}" title="التفاصيل"><i class="fa fa-ellipsis-h"></i></button>`
        : `<button class="ib" data-act="lim" data-m="${m}" title="حد السرعة" ${d.blocked?'disabled':''}><i class="fa fa-sliders"></i></button>`+
          `<button class="ib ${d.blocked?'grn':'red'}" data-act="blk" data-m="${m}" title="${d.blocked?'تشغيل':'إيقاف'}"><i class="fa ${d.blocked?'fa-check-circle':'fa-ban'}"></i></button>`+
          `<button class="ib" data-act="prof" data-m="${m}" title="التفاصيل"><i class="fa fa-ellipsis-h"></i></button>`}</td>`;
    tb.appendChild(tr);
    if(opened.has(d.mac)){
      const dr=document.createElement('tr');
      dr.className='exrow'+(d.blocked?' blk':'');
      dr.innerHTML='<td colspan="9">'+detailHTML(d)+'</td>';
      tb.appendChild(dr);
    }
  });
  if(!shown.length) tb.innerHTML='<tr><td colspan="9" class="empty">مفيش أجهزة مطابقة</td></tr>';

  const rest = shown.length - view.length;
  $('#more2').innerHTML = rest>0
    ? '<button type="button" data-more="1">إظهار '+rest+' كمان في '+esc(glabel||'القائمة')+'</button>'
    : (all && shown.length>PAGE ? '<button type="button" data-more="0">عرض أقل</button>' : '');

  $('#c-dev').textContent=S.devices.filter(d=>!d.hidden_offline).length;
  $('#c-blk').textContent=S.devices.filter(d=>d.blocked).length;
  if(tab==='blocked') blocked();
  $('#bulk').style.display=picked.size?'flex':'none';
  $('#bn').textContent=picked.size;
  if (inspector.open) renderInspector();
}

async function cleanOffline(button, selected){
  const macs = S.devices.filter(d=>canCleanOffline(d) && (!selected || picked.has(d.mac))).map(d=>d.mac);
  if (!macs.length) return;
  const en = $('#nc').dataset.lang === 'en';
  const message = en
    ? 'Remove '+macs.length+' offline device(s) from Devices? DHCP reservations and rules will be kept.'
    : 'تشيل '+macs.length+' جهاز أوفلاين من قائمة الأجهزة؟ حجوزات DHCP والقواعد هتفضل محفوظة.';
  if (!confirm(message)) return;
  await withProgress(button, async ()=>{
    try{
      const result = await api('cleanoffline', {macs});
      if (result.ok) {
        await load(1);
        S.devices.filter(d=>d.hidden_offline).forEach(d=>picked.delete(d.mac));
        $('#nc-change-status').textContent = en ? 'Offline devices removed: '+result.removed
          : 'تمت إزالة الأجهزة الأوفلاين: '+result.removed;
      }
    }catch(e){}
  });
  devices();
}
$('#clean-offline').onclick = e=>cleanOffline(e.currentTarget, false);
$('#clean-offline-selected').onclick = e=>cleanOffline(e.currentTarget, true);

/* a small menu of speeds, used by a row and by the group button */
function closePop(){ const p=$('#lpop'); if(p) p.remove(); }
function limPop(anchor, cur, pick){
  closePop();
  const p=document.createElement('div'); p.className='lpop'; p.id='lpop';
  const opts=[0,1,2,3,5,10,15,20,30];
  p.innerHTML='<div class="lph">حد النزول</div><div class="lpo">'+
    opts.map(v=>'<button type="button" data-v="'+v+'"'+(cur===v?' class="on"':'')+'>'+
      (v?v+' Mb':'بلا حد')+'</button>').join('')+
    '</div><div class="lpc"><input type="number" min="1" max="50" placeholder="رقم تاني">'+
    '<button type="button" data-v="c">تمام</button></div>';
  $('#nc').appendChild(p);
  const r=anchor.getBoundingClientRect(), w=p.offsetWidth, h=p.offsetHeight;
  const left=Math.min(Math.max(8, r.right-w), innerWidth-w-8);
  let top=r.bottom+6; if(top+h>innerHeight-8) top=Math.max(8, r.top-h-6);
  p.style.left=left+'px'; p.style.top=top+'px';
  const inp=p.querySelector('input');
  p.addEventListener('click',e=>{
    const b=e.target.closest('[data-v]'); if(!b) return;
    const custom=b.dataset.v==='c';
    const v=custom ? parseInt(inp.value,10) : +b.dataset.v;
    if(custom && !(v>=1 && v<=50)){ inp.focus(); return; }
    done(); pick(v);
  });
  inp.addEventListener('keydown',e=>{
    if(e.key==='Enter'){ e.preventDefault(); p.querySelector('[data-v="c"]').click(); }
    if(e.key==='Escape') done();
  });
  const outside=e=>{ if(!p.contains(e.target)) done(); };
  function done(){ closePop(); document.removeEventListener('mousedown',outside); }
  setTimeout(()=>document.addEventListener('mousedown',outside),0);
}

$('#chips').addEventListener('click',e=>{
  const c=e.target.closest('[data-chip]');
  if(c){
    curG=c.dataset.chip;
    try{ localStorage.setItem('nc_cur_grp',curG); }catch(_){}
    devices(); return;
  }
  const g=e.target.closest('#glimbtn'); if(!g) return;
  const items=lastShown.filter(d=>!d.protected);
  if(!items.length) return;
  const label=(GRP.find(x=>x[0]===effG)||[])[1]||'';
  limPop(g, null, v=>{
    pend={kind:'bulk', op:v?'lim':'unl', mb:v, macs:items.map(d=>d.mac)};
    sheet('حد للمجموعة: '+label, items.length+' جهاز',
      v ? limitFields(v)
        : 'الحد الخاص هيتشال وكل جهاز يرجع للحد العام.',
      devList(items), '', 'تطبيق', true);
  });
});
$('#more2').addEventListener('click',e=>{
  const b=e.target.closest('[data-more]'); if(!b) return;
  const k=grouping?(effG||'*'):'*';
  if(b.dataset.more==='1') pageAll.add(k); else pageAll.delete(k);
  devices();
});

/*
 * Rename in place. The row re-renders from the server's answer rather than
 * from what was typed, so a name the backend trimmed or rejected is never
 * left showing as if it had been saved.
 */
if($('#bq')) $('#bq').addEventListener('input',blocked);
if($('#blist')) $('#blist').addEventListener('click',e=>{
  const un=e.target.closest('[data-bun]'), pr=e.target.closest('[data-bpr]');
  const mac=(un&&un.dataset.bun)||(pr&&pr.dataset.bpr);
  if(!mac) return;
  const d=S.devices.find(x=>x.mac===mac); if(!d) return;
  if(un) askBlock(d); else profile(d);
});
if($('#bunall')) $('#bunall').addEventListener('click',()=>{
  const list=blockedRows();
  if(!list.length) return;
  /* route through the same confirm sheet the table uses, so one path only */
  pend={kind:'bulk',op:'unblock',macs:list.map(d=>d.mac)};
  sheet('تشغيل '+list.length+' جهاز',
    'هيترفع الحظر عنهم كلهم',
    'الأجهزة دي هترجع تتصل بالنت فورًا.',
    devList(list,10),'','تشغيلهم',false);
});

if($('#vref')) $('#vref').addEventListener('click',loadVideo);
if($('#vlist')) $('#vlist').addEventListener('click',async e=>{
  const gh=e.target.closest('[data-vg]');
  if(gh && !e.target.closest('button')){
    const k=gh.dataset.vg;
    if(vOpen.has(k)) vOpen.delete(k); else vOpen.add(k);
    loadVideo();
    return;
  }
  const gb=e.target.closest('[data-vgblk]');
  if(gb){
    const k=gb.dataset.vgblk;
    pend={kind:'blockservice',service:k};
    sheet('حظر '+(VNAME[k]||k), VNAME[k]||k,
      'هيتحظر على <b>كل الشبكة</b> — كل نطاقات الخدمة مش واحد بس.',
      '', 'local-zone: "'+k+'" refuse','حظر',true);
    return;
  }
  const lim=e.target.closest('[data-vlim]'), blk=e.target.closest('[data-vblk]');
  const mac=(lim&&lim.dataset.vlim)||(blk&&blk.dataset.vblk);
  if(!mac) return;
  const d=S.devices.find(x=>x.mac===mac);
  if(!d){ alert('الجهاز ده مابقاش ظاهر — اعمل تحديث'); return; }
  if(lim) askLimit(d,1); else askBlock(d);
});

if($('#hq'))   $('#hq').addEventListener('input',histRender);
if($('#href')) $('#href').addEventListener('click',loadHist);
if($('#hsw'))  $('#hsw').addEventListener('click',e=>{
  const b=e.target.closest('[data-h]'); if(!b) return;
  $$('#hsw [data-h]').forEach(x=>x.classList.remove('on'));
  b.classList.add('on'); hview=b.dataset.h; histRender();
});

if($('#logq')) $('#logq').addEventListener('input',logRender);
if($('#logref')) $('#logref').addEventListener('click',loadLog);

/* a section header folds and unfolds its section */
$('#tb').addEventListener('click',e=>{
  const h=e.target.closest('tr.ghead'); if(!h) return;
  const k=h.dataset.grp; if(!k) return;
  if(openGrp.has(k)) openGrp.delete(k); else openGrp.add(k);
  saveGrp();
  devices();
});

$('#tb').addEventListener('click',e=>{
  if (e.target.closest('tr.ghead')) return;      /* handled above */
  /*
   * The whole row opens, not just the small arrow. Clicking a row and
   * having nothing happen reads as broken, and the arrow is a hard target.
   * Anything interactive inside the row keeps its own behaviour.
   */
  if(e.target.closest('button,a,input,select,label,[data-ren]')
     && !e.target.closest('[data-exp]')) return;
  const row=e.target.closest('tr[data-mac]');
  const x=e.target.closest('[data-exp]');
  const mac = x ? x.dataset.exp : (row ? row.dataset.mac : null);
  if(!mac) return;
  if(opened.has(mac)) opened.delete(mac); else opened.add(mac);
  devices();
});

$('#tb').addEventListener('click',e=>{
  const b=e.target.closest('[data-ren]'); if(!b) return;
  e.stopPropagation();
  const mac=b.dataset.ren;
  const d=S.devices.find(x=>x.mac===mac); if(!d) return;
  const nm=b.parentElement;
  const prev=nm.innerHTML;
  nm.innerHTML='<input type="text" maxlength="48" placeholder="اكتب اسم الجهاز">';
  const inp=nm.querySelector('input');
  inp.value=(d.name&&d.name!==(d.hostname||''))?d.name:''; inp.focus(); inp.select();
  let closed=false;
  const finish=async save=>{
    if(closed) return; closed=true;
    if(!save){ nm.innerHTML=prev; return; }
    const v=inp.value.trim();
    const curName=(d.name&&d.name!==(d.hostname||''))?d.name:'';
    if(v===curName){ nm.innerHTML=prev; return; }
    try{
      const r=await withProgress(inp, ()=>
        api('rename',{mac:mac,name:v}));
      /* api() resolves on ok:false too, so check the payload, not just throws */
      if(!r||!r.ok) throw new Error(r&&r.error?r.error:'الحفظ فشل');
      await load();
    }catch(err){
      nm.innerHTML=prev;
      alert('ماقدرش يحفظ الاسم: '+(err&&err.message?err.message:err));
    }
  };
  inp.addEventListener('keydown',ev=>{
    if(ev.key==='Enter'){ev.preventDefault(); finish(true);}
    if(ev.key==='Escape'){ev.preventDefault(); finish(false);}
  });
  inp.addEventListener('blur',()=>finish(true));
});

$('#tb').addEventListener('input',e=>{
  if(e.target.type!=='range') return;
  const v=+e.target.value, w=e.target.closest('.spd');
  w.querySelector('.v').textContent=fmtL(v); w.classList.toggle('unl',!v);
});
$('#tb').addEventListener('change',e=>{
  if(e.target.type==='range'){
    const d=S.devices.find(x=>x.mac===e.target.dataset.r);
    if(d) askLimit(d,+e.target.value);
    return;
  }
  if(e.target.dataset.s!==undefined){
    e.target.checked?picked.add(e.target.dataset.s):picked.delete(e.target.dataset.s);
    devices();
  }
});
$('#tb').addEventListener('click',e=>{
  const b=e.target.closest('[data-act]'); if(!b) return;
  const d=S.devices.find(x=>x.mac===b.dataset.m); if(!d) return;
  if(b.dataset.act==='blk') askBlock(d);
  else if(b.dataset.act==='lim')
    limPop(b, d.limit||0, v=>askLimit(d,v));
  else profile(d);
});
$('#all').onchange=e=>{
  picked.clear();
  if(e.target.checked) lastShown.filter(d=>!d.protected).forEach(d=>picked.add(d.mac));
  devices();
};
$('#q').addEventListener('input',devices);
$('#refresh').onclick=e=>withProgress(e.currentTarget, async()=>{
  await load(2); loadLog();
});
$$('#t-dev [data-f]').forEach(b=>b.onclick=()=>{
  /* a second click on the active menu filter clears it */
  filt = (b.closest('#fmenu') && filt===b.dataset.f) ? 'all' : b.dataset.f;
  $('#fmenu').hidden=true;
  devices();
});
$('#fbtn').onclick=e=>{ e.stopPropagation(); $('#gmenu').hidden=true; $('#fmenu').hidden=!$('#fmenu').hidden; };
$('#gbtn').onclick=e=>{ e.stopPropagation(); $('#fmenu').hidden=true; $('#gmenu').hidden=!$('#gmenu').hidden; };
$$('#gmenu [data-g]').forEach(b=>b.onclick=()=>{
  grouping = b.dataset.g==='1';
  try{ localStorage.setItem('nc_group', grouping?'1':'0'); }catch(e){}
  $('#gmenu').hidden=true;
  devices();
});
/* any click elsewhere closes whichever menu is open */
document.addEventListener('click',e=>{
  if(!e.target.closest('#fdd'))  $('#fmenu').hidden=true;
  if(!e.target.closest('#gdd'))  $('#gmenu').hidden=true;
  if(!e.target.closest('.tmore')) toggleMore(false);
});
$('#bulk').addEventListener('click',e=>{
  const b=e.target.closest('[data-b]'); if(!b) return;
  const k=b.dataset.b;
  if(k==='none'){picked.clear();devices();return;}
  if(k==='group'){askGroup();return;}
  askBulk(k);
});

function groups(){
  const box=$('#grps'); box.innerHTML='';
  const keys=[...new Set(S.devices.map(d=>d.group).filter(Boolean))];
  Object.keys(GRPN).forEach(k=>{if(!keys.includes(k))keys.push(k);});
  keys.forEach(k=>{
    const mem=S.devices.filter(d=>d.group===k);
    const lock=(k==='infra');
    const el=document.createElement('div');
    el.className='card'+(activeGrp===k?' on':'');
    el.innerHTML=`<h5>${esc(GRPN[k]||k)} <span class="c">${mem.length}</span></h5>
      <div class="mem">${mem.slice(0,3).map(d=>esc(d.name||d.ip)).join('، ')||'—'}${
        mem.length>3?' و'+(mem.length-3)+' غيرهم':''}</div>
      <button class="btn btn-xs btn-default" data-g="${k}" data-ga="show">${
        activeGrp===k?'إلغاء التصفية':'اعرض'}</button>
      ${lock?'<span class="tg">محمية</span>':`
        <button class="btn btn-xs btn-default" data-g="${k}" data-ga="lim">حدّها 2</button>
        <button class="btn btn-xs btn-danger" data-g="${k}" data-ga="blk">أوقفها</button>`}`;
    box.appendChild(el);
  });
  $('#c-grp').textContent=keys.length;
}
$('#grps').addEventListener('click',e=>{
  const b=e.target.closest('[data-ga]'); if(!b) return;
  const k=b.dataset.g, a=b.dataset.ga;
  if(a==='show'){activeGrp=activeGrp===k?null:k;groups();devices();showTab('dev');return;}
  const items=S.devices.filter(d=>d.group===k&&!d.protected);
  if(!items.length){alert('المجموعة فاضية');return;}
  pend={kind:'bulk',op:a==='blk'?'block':'lim2',macs:items.map(d=>d.mac)};
  sheet(a==='blk'?'إيقاف مجموعة '+(GRPN[k]||k):'تحديد مجموعة '+(GRPN[k]||k),
    items.length+' جهاز',
    a==='blk'?'كل أجهزة المجموعة هيتقطع عنها النت.':'حد النزول هيبقى 2 ميجا لكلها.',
    devList(items),
    items.map(d=>a==='blk'?'pfctl -t nc_blocked -T add '+d.ip
      :'dnctl pipe … config bw 2Mbit/s  # '+d.mac).join('\n'),'تنفيذ',true);
});

function sched(){
  const nowSeconds = Number(S.now) || Date.now()/1000;
  const temporary = S.devices.filter(d=>!d.blocked && d.limit>0 && d.expires>nowSeconds)
    .sort((a,b)=>a.expires-b.expires);
  $('#temporary-limits').innerHTML = temporary.length ? temporary.map(d=>
    '<tr><td><span class="nm">'+esc(devLabel(d))+'</span></td><td><bdi class="m">'+esc(d.ip||d.last_ip||'—')+
    '</bdi></td><td><bdi>'+esc(d.limit)+' Mb/s</bdi></td><td><bdi>'+
    esc(d.limit_up ?? Math.max(1,Math.round(d.limit/2)))+' Mb/s</bdi></td><td><bdi>'+
    esc(dmhm(d.expires))+'</bdi></td></tr>').join('')
    : '<tr><td colspan="5" class="empty">مفيش حدود سرعة مؤقتة</td></tr>';
  const box=$('#sched'); box.innerHTML='';
  (S.schedule||[]).forEach((s,i)=>{
    const el=document.createElement('div'); el.className='srow';
    el.innerHTML=`<label class="sw g"><input type="checkbox" ${s.enabled?'checked':''}
        data-sch="${i}"><span></span></label>
      <div><div>${esc(s.what)}</div>
        <div style="font-size:10.5px;color:#9aa5b1">${esc(s.days)} · ${esc(s.scope)}</div></div>
      <span class="num" style="color:#0f6fb5;font-weight:600">${esc(s.from)} – ${esc(s.to)}</span>
      <button class="btn btn-xs btn-danger" data-dsch="${i}">حذف</button>`;
    box.appendChild(el);
  });
  if(!(S.schedule||[]).length) box.innerHTML='<div class="empty">مفيش قواعد بمواعيد</div>';
  $('#schedule-timeline').hidden=!(S.schedule||[]).some(s=>s.enabled);
  const tl=$('#tl'); tl.innerHTML='';
  const now=new Date().getHours();
  for(let h=0;h<24;h++){
    const d=document.createElement('div');
    d.className=(h>=7&&h<17)?'w':'f';
    if(h===now) d.innerHTML='<span class="now"></span>';
    tl.appendChild(d);
  }
  $('#c-sch').textContent=temporary.length+(S.schedule||[]).filter(s=>s.enabled).length;
}
$('#sched').addEventListener('change',async e=>{
  if(e.target.dataset.sch===undefined) return;
  await withProgress(e.target, ()=>
    api('schedule',{op:'toggle',index:e.target.dataset.sch}));
  load(1); loadLog();
});
$('#sched').addEventListener('click',async e=>{
  const b=e.target.closest('[data-dsch]'); if(!b) return;
  if(!confirm('تحذف القاعدة دي؟')) return;
  await withProgress(b, ()=>
    api('schedule',{op:'delete',index:b.dataset.dsch}));
  load(1); loadLog();
});

function sites(){
  const box=$('#rules'); box.innerHTML='';
  (S.sites||[]).forEach((s,i)=>{
    const n=(S.attempts||[]).filter(a=>a.domain===s.domain)
      .reduce((t,a)=>t+(a.count||1),0);
    const el=document.createElement('div'); el.className='rule';
    el.innerHTML=`<label class="sw"><input type="checkbox" ${s.enabled?'checked':''}
        data-site="${i}"><span></span></label>
      <span class="dom">${esc(s.domain)}</span>
      <span style="font-size:11px;color:#6b7785">${
        s.scope==='all'?'كل الشبكة':((s.macs||[]).length+' جهاز')}</span>
      <span class="num" style="font-size:11px">${n} محاولة</span>
      <button class="btn btn-xs btn-danger" data-dsite="${i}">حذف</button>`;
    box.appendChild(el);
  });
  if(!(S.sites||[]).length) box.innerHTML='<div class="empty">مفيش مواقع محظورة</div>';
  $('#c-sites').textContent=(S.sites||[]).filter(s=>s.enabled).length;
}
$('#rules').addEventListener('change',async e=>{
  if(e.target.dataset.site===undefined) return;
  await withProgress(e.target, ()=>
    api('site',{op:'toggle',index:e.target.dataset.site}));
  load(1); loadLog();
});
$('#rules').addEventListener('click',async e=>{
  const b=e.target.closest('[data-dsite]'); if(!b) return;
  if(!confirm('تشيل الحظر ده؟')) return;
  await withProgress(b, ()=>
    api('site',{op:'delete',index:b.dataset.dsite}));
  load(1); loadLog();
});
$$('[data-sc]').forEach(b=>b.onclick=()=>{
  $$('[data-sc]').forEach(x=>x.classList.remove('on'));
  b.classList.add('on'); newScope=b.dataset.sc;
});
$('#adddom').onclick=()=>{
  const v=$('#dom').value.trim().toLowerCase().replace(/^https?:\/\//,'').replace(/\/.*$/,'');
  if(!v||!v.includes('.')){alert('اكتب نطاق صحيح');return;}
  if(newScope==='sel'&&!picked.size){alert('حدد أجهزة من تبويب الأجهزة الأول');return;}
  pend={kind:'site',domain:v,scope:newScope,macs:[...picked]};
  sheet('حظر موقع',v,
    newScope==='all'?'هيتحظر على كل أجهزة الشبكة.':'هيتحظر على '+picked.size+' جهاز محدد.',
    newScope==='sel'?'<ul>'+sel().map(d=>'<li><b>'+esc(d.name||d.ip)+'</b> — '+esc(d.ip)+'</li>').join('')+'</ul>':'',
    'local-zone: "'+v+'" redirect\nlocal-data: "'+v+' A 0.0.0.0"','حظر',true);
};

const VID=[{k:'yt',n:'يوتيوب',d:'youtube.com · googlevideo.com'},
           {k:'tt',n:'تيك توك',d:'tiktok.com · tiktokcdn.com'},
           {k:'nf',n:'نتفليكس',d:'netflix.com · nflxvideo.net'},
           {k:'fb',n:'فيديو فيسبوك',d:'fbcdn.net'}];
function video(){
  const box=$('#vid'); box.innerHTML='';
  VID.forEach(v=>{
    const cur=(S.video||[]).find(x=>x.key===v.k)||{mode:'off',macs:[]};
    const el=document.createElement('div'); el.className='card';
    el.innerHTML=`<h5>${esc(v.n)}</h5>
      <div style="font-size:11px;color:#9aa5b1;margin-bottom:8px">${esc(v.d)}</div>
      <div class="seg">
        <button class="${cur.mode==='off'?'on':''}" data-v="${v.k}" data-m="off">مسموح</button>
        <button class="${cur.mode==='limit'?'on':''}" data-v="${v.k}" data-m="limit">محدود</button>
        <button class="${cur.mode==='block'?'on':''}" data-v="${v.k}" data-m="block">ممنوع</button>
      </div>
      <div style="font-size:11px;color:#6b7785;margin-top:7px">${
        (cur.macs||[]).length?'على '+cur.macs.length+' جهاز':'على كل الشبكة'}</div>`;
    box.appendChild(el);
  });
}
$('#vid').addEventListener('click',e=>{
  const b=e.target.closest('[data-v]'); if(!b) return;
  const v=VID.find(x=>x.k===b.dataset.v), m=b.dataset.m;
  const mb=1;
  pend={kind:'video',key:v.k,mode:m,macs:[...picked],mb:mb};
  const lbl={off:'مسموح',limit:'محدود',block:'ممنوع'}[m];

  /*
   * Be exact about what "limit" does. A service cannot be throttled on its
   * own - its addresses move constantly, and the DNS layer that blocks a
   * domain can only answer yes or no. What gets capped is the device that
   * is streaming, which slows everything that device does. Saying so here
   * is the difference between a working control and a misleading one.
   */
  const what =
    m==='limit'
      ? 'الأجهزة اللي <b>بتتفرّج على '+esc(v.n)+' دلوقتي</b> '+
        'هياخدوا حد <b>'+mb+' ميجا</b>، وأي حد يبدأ بعد كده هيتحد كمان.<br>'+
        '<b>ملحوظة:</b> الحد بيتطبق على <b>كل استخدام الجهاز</b> مش على '+
        esc(v.n)+' بس — مافيش طريقة تحدّ خدمة لوحدها.'
      : m==='block'
      ? 'هيتحظر على <b>كل الشبكة</b> — كل نطاقات الخدمة مش واحد بس.'
      : 'هيترفع الحظر، والحدود اللي اتحطت بسبب الخدمة دي هتتشال.';

  sheet(v.n+': '+lbl, v.d, what,
    picked.size?'<ul>'+sel().map(d=>'<li><b>'+esc(d.name||d.ip)+'</b> — '+esc(d.ip)+'</li>').join('')+'</ul>':'',
    m==='block'?'local-zone: "'+v.d.split(' · ')[0]+'." refuse'
      :m==='limit'?'dnctl pipe N config bw '+mb+'Mbit/s mask dst-ip 0xffffffff'
      :'# شيل القيود','تطبيق',true);
});

function att(){
  const box=$('#att'); box.innerHTML='';
  (S.attempts||[]).forEach(a=>{
    const el=document.createElement('div'); el.className='arow';
    el.innerHTML=`<span class="num" style="font-size:10.5px;color:#9aa5b1">${
        esc(hhmm(a.at||0))}</span>
      <div><span class="dom" style="color:#b3261e">${esc(a.domain)}</span> — ${esc(a.device||a.ip)}</div>
      <span style="font-size:11px;color:#6b7785">${a.count||1} محاولة</span>`;
    box.appendChild(el);
  });
  if(!(S.attempts||[]).length)
    box.innerHTML='<div class="empty">مفيش محاولات مسجّلة — بيتسجّل هنا أول ما حد يحاول يفتح موقع محظور</div>';
  $('#c-att').textContent=(S.attempts||[]).length;
}

function drawStale(items){
  const tb=$('#tbS'); tb.innerHTML='';
  items.forEach(s=>{
    const tr=document.createElement('tr');
    tr.innerHTML=`<td><input type="checkbox" data-st="${esc(s.mac)}"></td>
      <td class="num ${s.random?'':''}" style="${s.random?'color:#9a6508':''}">${esc(s.mac)}
        ${s.random?'<span class="tg ipb">عشوائي</span>':''}</td>
      <td class="num">${esc(s.ip)}</td>
      <td style="color:#6b7785">${esc(s.descr||'—')}</td>
      <td><span class="tg">${esc(s.kind)}</span></td>`;
    tb.appendChild(tr);
  });
  if(!items.length) tb.innerHTML='<tr><td colspan="5" class="empty">مفيش حاجة غائبة '+age+' يوم أو أكتر</td></tr>';
  $('#c-clean').textContent=items.length;
  updDel();
}
function updDel(){
  const n=$$('#tbS [data-st]:checked').length;
  $('#delstale').disabled=!n;
  $('#delstale').textContent=n?'مسح '+n+' محدد':'مسح المحدد';
}
$('#tbS').addEventListener('change',updDel);
$('#allS').onchange=e=>{$$('#tbS [data-st]').forEach(c=>c.checked=e.target.checked);updDel();};
$('#selall').onclick=()=>{$$('#tbS [data-st]').forEach(c=>c.checked=true);updDel();};
$$('[data-age]').forEach(b=>b.onclick=()=>{
  $$('[data-age]').forEach(x=>x.classList.remove('on'));
  b.classList.add('on'); age=+b.dataset.age; loadStale();
});
$('#delstale').onclick=()=>{
  const macs=$$('#tbS [data-st]:checked').map(c=>c.dataset.st);
  if(!macs.length) return;
  pend={kind:'clean',macs};
  sheet('مسح حجوزات غير مستخدمة',macs.length+' عنصر',
    'الحجوزات هتتمسح والـ IPs هترجع للـ pool. لو جهاز رجع، ياخد IP جديد عادي.',
    '<ul>'+macs.map(m=>'<li>'+esc(m)+'</li>').join('')+'</ul>','# حذف حجوزات DHCP','مسح');
};

/* which rows are expanded; survives a refresh because it keys on MAC */
const opened = new Set();

/*
 * The expanded panel. It carries the columns the summary row dropped (MAC,
 * type, vendor, connections) plus everything the old modal showed, so the
 * table can stay narrow enough to show a device's name in full.
 */
function detailHTML(d){
  const F = (l,v,cls) =>
    '<div class="df"><div class="dl">'+l+'</div>'+
    '<div class="dv '+(cls||'')+'">'+v+'</div></div>';
  const dash = '<span style="color:var(--faint)">—</span>';
  return '<div class="dwrap"><div class="dgrid">'+
    F('عنوان MAC','<span class="m">'+esc(d.mac)+'</span>') +
    F('النوع', esc(d.type_name||'—')) +
    F('المصنّع', esc(d.vendor||'—')) +
    F('اسم الهوست', d.hostname?'<span class="m">'+esc(d.hostname)+'</span>':dash) +
    F('الاتصالات', d.conns?esc(String(d.conns))+' اتصال':dash) +
    F('مسجّل بـ', d.cp_user?'<b style="color:var(--ok)">'+esc(d.cp_user)+'</b>':dash) +
    F('المجموعة', esc(GRPN[d.group]||d.group||'—')) +
    F('الحد الحالي', fmtL(d.limit)) +
    F('حد الرفع', d.limit ? esc(String(d.limit_up ?? Math.max(1, Math.round(d.limit/2))))+' Mb/s' : dash) +
    F('حظر بـ', d.random?'<span class="tg ipb">IP</span>':'<span class="tg mac">MAC</span>') +
    F('حجز DHCP', d.reserved
        ? (d.res_ip
            ? esc(d.res_ip) + ' \u2014 \u062b\u0627\u0628\u062a' +
              ((d.ip && d.ip !== d.res_ip)
                ? ' (\u0627\u0644\u062c\u0647\u0627\u0632 \u062f\u0644\u0648\u0642\u062a\u064a \u0639\u0644\u0649 ' + esc(d.ip) + ')'
                : '')
            : '\u062b\u0627\u0628\u062a')
        : '\u0644\u0627 \u2014 \u0639\u0646\u0648\u0627\u0646 \u0645\u062a\u063a\u064a\u0631 \u0645\u0646 DHCP') +
    F('السرعة دلوقتي',
      (+d.rate>0) ? '<b style="color:var(--acc)">'+(+d.rate).toFixed(2)+'</b> Mb/s'
                  : '<span style="color:var(--faint)">ساكن</span>') +
    F('الاستهلاك الإجمالي', mb(d.bytes)) +
    F('آخر ظهور', d.last_seen?dmhm(d.last_seen):dash) +
  '</div>'+
  (d.moved?'<div class="dnote">الـ IP اتغير من <b>'+esc(d.prev_ip)+
    '</b> — القواعد اتنقلت تلقائيًا بالـ MAC.</div>':'')+
  (d.random?(d.fp
    ?'<div class="dnote">MAC عشوائي، بس الجهاز متابَع باسم <b>'+esc(d.fp)+
      '</b> — لو غيّر الـ MAC الحظر بيتنقل معاه تلقائي.</div>'
    :'<div class="dnote warn">MAC عشوائي ومافيش اسم ثابت نتابعه بيه — '+
      'لو غيّر الـ MAC هيعدي. احجزله IP من DHCP أو سمّيه يدوياً.</div>'):'')+
  '</div>';
}

function profile(d){
  sheet(d.name||d.ip, d.ip+' · '+d.mac,
    `<div class="prof">
      <div class="pb"><div class="l">النوع</div><div class="v" style="font-size:12px">${
        esc(d.type_name||'—')}</div></div>
      <div class="pb"><div class="l">المصنّع</div><div class="v" style="font-size:12px">${
        esc(d.vendor||'—')}</div></div>
      <div class="pb"><div class="l">اسم الهوست</div><div class="v" style="font-size:12px">${
        esc(d.hostname||'—')}</div></div>
      <div class="pb"><div class="l">مسجّل بـ</div><div class="v" style="font-size:12px;${
        d.cp_user?'color:#137333':''}">${esc(d.cp_user||'—')}</div></div>
      <div class="pb"><div class="l">المجموعة</div><div class="v" style="font-size:12px">${
        esc(GRPN[d.group]||d.group||'—')}</div></div>
      <div class="pb"><div class="l">الحد</div><div class="v" style="font-size:12px">${fmtL(d.limit)}</div></div>
      <div class="pb"><div class="l">الحالة</div><div class="v" style="font-size:12px">${
        d.blocked?'موقوف':(d.online?'شغال':'أوفلاين')}</div></div>
      <div class="pb"><div class="l">حظر بـ</div><div class="v" style="font-size:12px">${
        d.random?'IP':'MAC'}</div></div>
      <div class="pb"><div class="l">الاستهلاك</div><div class="v" style="font-size:12px">${mb(d.bytes)}</div></div>
      <div class="pb"><div class="l">حجز DHCP</div><div class="v" style="font-size:12px">${
        d.reserved?'نعم':'لا'}</div></div>
     </div>
     ${d.moved?'<div class="what">الـ IP اتغير من <b>'+esc(d.prev_ip)+
       '</b> — القواعد اتنقلت تلقائيًا بالـ MAC.</div>':''}
     ${d.random?'<div class="what">MAC عشوائي: الحظر بيتم بالـ IP وممكن يفلت لو الجهاز غيّر الـ MAC.</div>':''}`,
    '','','',false,true);
}

/* The real commands, capped so the preview stays readable. */
function cmdList(items, op){
  const line = d =>
    op === 'block' ? 'pfctl -t nc_blocked -T add ' + d.ip
    : op === 'lim2' ? 'dnctl pipe \u2026 bw 2Mbit/s   # ' + d.mac
    : '# ' + d.mac;
  const shown = items.slice(0, 6).map(line);
  if (items.length > 6) shown.push('\u2026 +' + (items.length - 6));
  return shown.join(String.fromCharCode(10));
}

/* A compact, capped list of devices for the confirm sheet. */
function devList(items, cap){
  cap = cap || 10;
  const shown = items.slice(0, cap);
  const rest  = items.length - shown.length;
  return '<ul>' + shown.map(d =>
    '<li><b>' + esc(d.name || d.hostname || d.ip) + '</b>' +
    '<span class="mm">' + esc(d.ip) + (d.mac ? ' \u00b7 ' + esc(d.mac) : '') + '</span>' +
    (d.random ? '<span class="tg ipb">\u0639\u0634\u0648\u0627\u0626\u064a</span>' : '') +
    '</li>').join('') +
    (rest > 0 ? '<li style="color:var(--faint)">\u0648 ' + rest +
                ' \u062c\u0647\u0627\u0632 \u0643\u0645\u0627\u0646</li>' : '') +
    '</ul>';
}

/* ---------------- confirm sheet ---------------- */
function sheet(title,who,what,list,cmd,go,withDur,noBtns){
  dur='perm';
  const dh=withDur?`<div class="dur"><div class="dl">المدة</div><div class="seg">${
    Object.entries(DUR).map(([k,v])=>`<button class="${k==='perm'?'on':''}" data-d="${k}">${v}</button>`)
    .join('')}</div></div>`:'';
  const btns=noBtns?`<div class="btns"><button class="btn btn-sm btn-default" id="no">إغلاق</button></div>`
    :`<div class="btns"><button class="btn btn-sm btn-default" id="no">إلغاء</button>
       <button class="btn btn-sm btn-primary" id="yes">${esc(go)}</button></div>`;
  const el=document.createElement('div'); el.className='sheet'; el.id='sheet';
  el.innerHTML=`<div class="bx" role="dialog" aria-modal="true">
    <h4>${esc(title)}</h4><div class="who">${esc(who)}</div>
    ${what?`<div class="what">${what}</div>`:''}${list||''}${dh}
    ${cmd?`<div class="cmd">${esc(cmd)}</div>`:''}${btns}
    ${noBtns?'':'<div class="note">نسخة احتياطية بتتاخد أوتوماتيك قبل التنفيذ</div>'}</div>`;
  /*
   * Mount inside #nc, not on <body>: every rule for this dialog is scoped
   * "#nc .sheet", so appending to the body left it completely unstyled.
   */
  (document.getElementById('nc')||document.body).appendChild(el);
  (el.querySelector('#yes')||el.querySelector('#no')).focus();
  el.querySelector('#no').onclick=closeSheet;
  const y=el.querySelector('#yes'); if(y) y.onclick=apply;
  el.onclick=ev=>{if(ev.target===el)closeSheet();};
  el.querySelectorAll('[data-d]').forEach(b=>b.onclick=()=>{
    el.querySelectorAll('[data-d]').forEach(x=>x.classList.remove('on'));
    b.classList.add('on'); dur=b.dataset.d;
  });
  document.addEventListener('keydown',onEsc);
}
function onEsc(e){if(e.key==='Escape')closeSheet();}
function closeSheet(){const s=$('#sheet');if(s)s.remove();
  document.removeEventListener('keydown',onEsc);pend=null;devices();}

function askBlock(d){
  if(d.protected){alert('الجهاز ده من البنية الأساسية ومايتوقفش من هنا');return;}
  const on=!d.blocked;
  pend={kind:on?'block':'unblock',mac:d.mac};
  sheet(on?'إيقاف النت عن الجهاز':'إرجاع النت للجهاز',
    (d.name||d.hostname||d.ip)+' · '+d.ip+' · '+d.mac,
    on?(d.random?'MAC عشوائي — الحظر بالـ IP مع تثبيت حجز DHCP. أضعف وممكن يفلت.'
               :'الحظر بالـ MAC — لو الجهاز أخد IP جديد، الحظر بيتابعه تلقائيًا.')
      :'الجهاز هيرجع بحده الحالي ('+fmtL(d.limit)+').','',
    on?'pfctl -t nc_blocked -T add '+d.ip+'\npfctl -k '+d.ip
      :'pfctl -t nc_blocked -T delete '+d.ip,
    on?'إيقاف':'تشغيل', on);
}
function limitFields(down, up = Math.max(1, Math.round(down / 2))){
  return '<div class="nc-limit-fields">'+
    '<label>حد النزول (Mb/s)<input id="limit-down-value" class="form-control" type="number" min="1" max="50" step="1" required value="'+esc(down)+'"></label>'+
    '<label>حد الرفع (Mb/s)<input id="limit-up-value" class="form-control" type="number" min="0.1" max="50" step="0.001" required value="'+esc(up)+'"></label>'+
    '</div>';
}

function askLimit(d,v,restartTraffic=false){
  pend={kind:'limit',mac:d.mac,mb:v,restartTraffic};
  sheet('تغيير حد النزول',(d.name||d.hostname||d.ip)+' · '+d.ip+' · '+d.mac,
    v?limitFields(v, d.limit_up ?? Math.max(1, Math.round(v / 2)))+
      (restartTraffic?'<p>اتصالات الإنترنت المفتوحة للجهاز هتتقفل بعد تطبيق الحد.</p>':'')
     :('الجهاز هياخد الخط بلا حد (كان '+fmtL(d.limit)+').'),'',
    v?''
     :'# شيل الجهاز من الأنبوب','تطبيق',true);
}
function askBulk(k){
  const items=sel();
  if(!items.length) return;
  const t={block:'إيقاف الأجهزة المحددة',lim2:'تحديد النزول بـ 2 ميجا',unl:'إعفاء من حد السرعة'}[k];
  pend={kind:'bulk',op:k,macs:items.map(d=>d.mac)};
  if(k==='lim2'){ pend.op='lim'; pend.mb=2; }
  sheet(t,items.length+' جهاز',
    k==='lim2'?limitFields(2):k==='block'?'كل الأجهزة دي هيتقطع عنها النت.':'الحد العام هيتطبق على كلها.',
    devList(items),
    k==='lim2'?'':cmdList(items,k),'تنفيذ',true);
}
function askGroup(){
  const items=sel(); if(!items.length) return;
  const opts=Object.entries(GRPN).filter(([k])=>k!=='infra')
    .map(([k,n])=>`<button class="btn btn-xs btn-default" data-mg="${k}">${esc(n)}</button>`).join(' ');
  sheet('ضمّ لمجموعة',items.length+' جهاز',
    'اختار المجموعة:<div style="margin-top:8px;display:flex;gap:5px;flex-wrap:wrap">'+opts+'</div>',
    devList(items),
    '','',false,true);
  $('#sheet').addEventListener('click',async ev=>{
    const b=ev.target.closest('[data-mg]'); if(!b) return;
    await withProgress(b, ()=>
      api('group',{group:b.dataset.mg,macs:items.map(d=>d.mac)}));
    picked.clear(); closeSheet(); load(1); loadLog();
  });
}

async function apply(){
  const p=pend; if(!p) return;
  const downField=$('#limit-down-value'), upField=$('#limit-up-value');
  if (downField && (!downField.reportValidity() || !upField.reportValidity())) return;
  const speeds = downField ? {mb:downField.value,up_mb:upField.value} : {mb:p.mb||0};
  /*
   * The spinner goes in the confirm button, not over the whole dialog:
   * the label stays readable, and the control that was pressed is where
   * the eye already is. progOff() happens in the finally below, after
   * the reload, because that is most of the wait.
   */
  const stop = working($('#sheet #yes'));
  try{
    let r;
    if(p.kind==='block'||p.kind==='unblock')
      r=await api(p.kind,{mac:p.mac,duration:dur});
    else if(p.kind==='limit')
      r=await api('limit',{mac:p.mac,...speeds,duration:dur,...(p.restartTraffic?{restart_traffic:1}:{})});
    else if(p.kind==='bulk'){
      /* the API takes 60 at a time; a big category goes in slices */
      const skipped=[];
      for(let i=0;i<p.macs.length;i+=60){
        r=await api('bulk',{op:p.op,...speeds,macs:p.macs.slice(i,i+60),duration:dur});
        if(!r || r.ok===false) break;
        (r.skipped||[]).forEach(x=>skipped.push(x));
      }
      if(r && r.ok!==false) r.skipped=skipped;
    }
    else if(p.kind==='clean')
      r=await api('clean',{macs:p.macs});
    else if(p.kind==='site')
      r=await api('site',{op:'add',domain:p.domain,scope:p.scope,macs:p.macs,duration:dur});
    else if(p.kind==='video')
      r=await api('video',{key:p.key,mode:p.mode,macs:p.macs,
                           mb:p.mb||1,duration:dur});
    else if(p.kind==='savepolicy'){
      r=await api('savepolicy',p.body);
      if(r&&r.ok){ POL={policy:r.policy,preview:r.preview,history:POL.history}; }
    }
    else if(p.kind==='blockservice')
      r=await api('blockservice',{service:p.service,scope:'all',duration:dur});
    else if(p.kind==='global'){
      r=await api('global',{down:p.down,up:p.up});
      gD=null; gU=null;          /* re-read from the server */
    }

    if(r && r.ok===false){ alert('فشل: '+(r.error||'غير معروف')); }
    if(r && r.warning){
      if($('#nc').dataset.lang==='en') alert('Limit applied. Existing internet connections could not be closed.');
      else alert(r.warning);
    }
    if(r && r.skipped && r.skipped.length)
      alert('اتخطى أجهزة محمية: '+r.skipped.join('، '));
    closeSheet();
    if(p.kind==='clean'){loadStale();}
    if(p.kind==='savepolicy'){loadPolicy();}
    picked.clear();
    await load(1); loadLog();
  }catch(e){
    apiFail('خطأ في الاتصال: '+e.message);
    closeSheet();
  }finally{
    stop();
  }
}

/* ---------------- live traffic ---------------- */
let LIVE=null, lvView='devices';
const secs = n => {
  const total = Math.floor(Number(n));
  if (!Number.isFinite(total) || total <= 0) return '—';
  const h = Math.floor(total / 3600), m = Math.floor(total % 3600 / 60);
  const ar = h ? h+'س '+m+'د' : m ? m+'د' : total+'ث';
  const en = h ? h+'h '+m+'m' : m ? m+'m' : total+'s';
  return '<bdi class="nc-duration"><span lang="ar">'+ar+'</span><span lang="en">'+en+'</span></bdi>';
};
/*
 * The video streams open right now. Each row names the watcher, so the admin
 * can act on the person rather than on an abstract service.
 */
const vOpen = new Set();   /* which service groups are expanded */

/*
 * The video panel, grouped: service, then the devices inside it, then the
 * hosts that device is actually talking to.
 *
 * It shows hostnames, never full URLs, because the rest of the address is
 * encrypted: a firewall sees the handshake's server name and the DNS lookup
 * before it, not the path. Saying "youtube.com" when we cannot know which
 * video is playing is the honest limit, and the panel states it.
 */
async function loadVideo(){
  const box=$('#vlist'); if(!box) return;
  try{
    const d=await get('livevideo');
    const gs=d.groups||[];
    const devs=gs.reduce((n,g)=>n+g.count,0);
    const watching=gs.reduce((n,g)=>n+g.watching,0);
    $('#vcount').innerHTML = gs.length
      ? `<b>${devs}</b> جهاز على <b>${gs.length}</b> خدمة`+
        (watching?` · <b style="color:var(--crit)">${watching}</b> بيتفرّج فعلاً`:'')
      : 'مافيش فيديو مفتوح دلوقتي';
    if(!gs.length){
      box.innerHTML='<div class="empty" style="padding:28px">'+
        'مافيش حد بيتفرّج على فيديو دلوقتي</div>';
      return;
    }
    box.innerHTML = gs.map(g=>{
      const open = vOpen.has(g.kind);
      return `<div class="vg${open?' open':''}">
        <div class="vgh" data-vg="${esc(g.kind)}">
          <span class="vk ${esc(g.kind)}">${VICO[g.kind]||'▶'}</span>
          <div class="vgm">
            <div class="vgn">${esc(VNAME[g.kind]||g.kind)}
              ${g.watching?`<span class="vtag stream">${g.watching} بيتفرّج</span>`:''}
            </div>
            <div class="vgs">${g.count} جهاز · ${g.conns} اتصال</div>
          </div>
          <div class="vgb">${mb(g.bytes)}</div>
          <button class="btn btn-xs btn-danger" data-vgblk="${esc(g.kind)}"
            title="يحظر الخدمة على كل الشبكة">احظرها</button>
          <span class="chev">${open?'▾':'▸'}</span>
        </div>
        <div class="vgd" ${open?'':'hidden'}>
          ${g.devices.map(v=>`
            <div class="vrow${v.blocked?' blk':''}">
              <div class="vmeta">
                <div class="vn">${esc(v.name)}
                  <span class="vtag ${esc(v.how)}">${
                    v.how==='stream'?'بيتفرّج'
                    :v.how==='page'?'الصفحة مفتوحة':'غالبًا فيديو'}</span>
                  ${v.cp_user?`<span class="tg cpu">${esc(v.cp_user)}</span>`:''}
                </div>
                <div class="vw"><span class="m">${esc(v.ip)}</span>
                  ${v.hosts.map(h=>`<span class="vh"
                    title="${esc(h.host)} · ${mb(h.bytes)}">${esc(h.host)}</span>`).join('')}
                </div>
              </div>
              <div class="vb">${mb(v.bytes)}<i>${v.conns} اتصال</i></div>
              <div class="vacts">
                <button class="btn btn-xs btn-default"
                  data-vlim="${esc(v.mac)}">حدّه 1 ميجا</button>
                <button class="btn btn-xs ${v.blocked?'btn-success':'btn-danger'}"
                  data-vblk="${esc(v.mac)}">${v.blocked?'تشغيل':'إيقاف'}</button>
              </div>
            </div>`).join('')}
        </div>
      </div>`;
    }).join('') +
    '<div class="vnote">الأسماء دي هي السيرفرات اللي الجهاز متصل بيها فعلاً. '+
    'الرابط الكامل للفيديو مشفّر جوه الاتصال ومايظهرش لأي فايروول من غير '+
    'اعتراض الشهادات على كل جهاز.</div>';
  }catch(e){
    box.innerHTML='<div class="empty" style="padding:28px">ماقدرش يقرا البث المفتوح</div>';
  }
}
const VICO={youtube:'▶',tiktok:'♪',facebook:'f',netflix:'N',
  instagram:'◉',twitch:'▸',whatsapp:'✆'};
const VNAME={youtube:'يوتيوب',tiktok:'تيك توك',
  facebook:'فيسبوك',netflix:'نتفليكس',
  instagram:'انستغرام',twitch:'تويتش',
  whatsapp:'واتساب'};

let trafficRequest = 0;
async function loadLive(){
  const request = ++trafficRequest;
  $('#lvbox').innerHTML='<div class="empty">بيحمّل…</div>';
  try{
    const d=await get('traffic','view='+lvView);
    if (request !== trafficRequest) return;
    if(!d.ok) throw new Error(d.error||'fail');
    LIVE=d; drawLive();
  }catch(e){
    if (request !== trafficRequest) return;
    $('#lvbox').innerHTML='<div class="empty">فشل التحميل: '+esc(e.message)+'</div>';
  }
}
function nameOf(ip){
  const d=S.devices.find(x=>x.ip===ip);
  return d ? (d.name||d.hostname||ip) : ip;
}

const liveColumns = {
  devices: [['name','الجهاز',devLabel], ['session','مدة الجلسة',d=>d.session], ['bytes','حجم البيانات',d=>d.bytes],
    ['conns','الاتصالات',d=>d.conns], ['services','الخدمات',d=>d.services.length]],
  services: [['name','الخدمة',d=>d.service], ['bytes','حجم البيانات',d=>d.bytes],
    ['conns','الاتصالات',d=>d.conns], ['devices','الأجهزة',d=>d.device_count]],
  detail: [['name','الخدمة',d=>d.service], ['bytes','حجم البيانات',d=>d.bytes],
    ['conns','الاتصالات',d=>d.conns], ['age','عمر الاتصال',d=>d.age]]
};
const liveOrder = {devices:['bytes',-1],services:['bytes',-1],detail:['bytes',-1]};
const liveExpanded = new Set();

function orderedLive(rows, scope){
  const [field, direction] = liveOrder[scope];
  const value = liveColumns[scope].find(column=>column[0]===field)[2];
  return [...rows].sort((a,b)=>{
    const x=value(a), y=value(b);
    if(field==='name') return direction*String(x||'').localeCompare(String(y||''),undefined,{numeric:true});
    if(x==null) return y==null ? 0 : 1;
    if(y==null) return -1;
    return direction*(x-y);
  });
}

function liveSortButton(scope, column){
  const [field,label] = column;
  const [key,direction] = liveOrder[scope];
  return '<button type="button" class="live-sort" data-live-sort="'+field+'" data-live-scope="'+scope+
    '" aria-pressed="'+(key===field)+'">'+label+' <span aria-hidden="true">'+
    (key===field ? (direction>0?'↑':'↓') : '↕')+'</span></button>';
}

function liveHeader(scope){
  const [first,...metrics] = liveColumns[scope];
  return '<div class="live-head">'+liveSortButton(scope,first)+'<div class="dvm">'+
    metrics.map(column=>liveSortButton(scope,column)).join('')+'</div><span class="live-actions">الإجراءات</span></div>';
}

function drawLive(){
  if(!LIVE) return;
  const q=($('#lvq').value||'').trim().toLowerCase();
  const box=document.createElement('div');
  box.className='live-grid live-'+lvView;
  box.innerHTML=liveHeader(lvView);

  if(lvView==='devices'){
    let rows=LIVE.devices||[];
    if(q) rows=rows.filter(d=>
      (d.name||'').toLowerCase().includes(q)||d.ip.includes(q)||
      (d.mac||'').includes(q)||
      d.services.some(x=>x.service.toLowerCase().includes(q)));
    $('#c-live').textContent=(LIVE.devices||[]).length;
    if(!rows.length){
      $('#lvbox').innerHTML='<div class="empty">مفيش أجهزة مطابقة</div>'; return;
    }
    orderedLive(rows,'devices').forEach((d,i)=>{
      const card=document.createElement('div');
      const key='device:'+d.ip+'|'+(d.mac||'');
      const open=liveExpanded.has(key);
      card.dataset.liveKey=key;
      card.className='dvc'+(d.blocked?' blk':'')+(open?' open':'');
      card.innerHTML=`
        <div class="dvh" data-open="${i}">
          <div class="dvi">
            <div class="l1">
              <button type="button" class="live-expand" data-live-expand aria-expanded="${open}" aria-controls="live-body-${i}"><span class="chev" aria-hidden="true">${open?'▾':'▸'}</span> <span class="nm">${esc(devLabel(d))}</span></button>
              ${devSub(d)?'<span class="host">'+esc(devSub(d))+'</span>':''}
              ${d.type_name?'<span class="tg">'+esc(d.type_name)+'</span>':''}
              ${d.vendor?'<span class="tg">'+esc(d.vendor)+'</span>':''}
              ${d.protected?'<span class="tg prot">نظام</span>':''}
              ${d.cp_user?'<span class="tg cpu"><i class="fa fa-user"></i> '+esc(d.cp_user)+'</span>':''}
              ${d.blocked?'<button class="tg gotoblk" data-goblk="1" '+
                'title="روح لتبويب الموقوفة">موقوف</button>':''}
              ${d.limit?'<span class="tg ipb">حد '+d.limit+'Mb</span>':''}
            </div>
            <div class="l2">${esc(d.ip)} · <span class="${d.random?'r':''}">${esc(d.mac||'—')}</span>${
              d.random?' <span class="tg ipb">عشوائي</span>':''}</div>
          </div>
          <div class="dvm">
            <div class="k"><div class="v">${secs(d.session)}</div><div class="t">مدة الجلسة</div></div>
            <div class="k"><div class="v">${mb(d.bytes)}</div><div class="t">حجم البيانات</div></div>
            <div class="k"><div class="v">${d.conns}</div><div class="t">الاتصالات</div></div>
            <div class="k"><div class="v">${d.services.length}</div><div class="t">الخدمات</div></div>
          </div>
          <div class="dva">
            ${d.protected?'<span style="color:#ccc;font-size:11px">محمي</span>':`
              <button class="btn btn-xs btn-default" data-dv="lim" data-m="${esc(d.mac)}">حدّه</button>
              <button class="btn btn-xs ${d.blocked?'btn-success':'btn-danger'}"
                data-dv="blk" data-m="${esc(d.mac)}">${d.blocked?'شغّله':'أوقفه'}</button>`}
          </div>
        </div>
        <div class="dvs" id="live-body-${i}" data-body="${i}" ${open?'':'hidden'}>
          <div class="svr live-detail-head">${liveColumns.detail.map(column=>liveSortButton('detail',column)).join('')}<span>الإجراءات</span></div>
          ${orderedLive(d.services,'detail').map(x=>{
            const isIP=/^\d+\.\d+\.\d+\.\d+$/.test(x.service);
            return `<div class="svr">
              <div class="sn"><b>${esc(x.service)}</b>${isIP?' <span class="tg">غير معروف</span>':''}
                ${x.host?'<span class="hh">'+esc(x.host)+'</span>':''}</div>
              <div class="sm"><span class="live-detail-label">حجم البيانات</span>${mb(x.bytes)}</div>
              <div class="sm"><span class="live-detail-label">الاتصالات</span>${x.conns}</div>
              <div class="sm"><span class="live-detail-label">عمر الاتصال</span>${secs(x.age)}</div>
              <button class="btn btn-xs btn-danger" data-sv="${esc(x.service)}">احظر</button>
            </div>`;}).join('')}
        </div>`;
      box.appendChild(card);
    });
  } else {
    let svcs=LIVE.services||[];
    if(q) svcs=svcs.filter(x=>x.service.toLowerCase().includes(q));
    $('#c-live').textContent=(LIVE.services||[]).length;
    if(!svcs.length){
      $('#lvbox').innerHTML='<div class="empty">مفيش خدمات مطابقة</div>'; return;
    }
    orderedLive(svcs,'services').forEach((x,i)=>{
      const isIP=/^\d+\.\d+\.\d+\.\d+$/.test(x.service);
      const card=document.createElement('div');
      const key='service:'+x.service;
      const open=liveExpanded.has(key);
      card.dataset.liveKey=key;
      card.className='dvc'+(open?' open':'');
      card.innerHTML=`
        <div class="dvh" data-open="${i}">
          <div class="dvi">
            <div class="l1"><button type="button" class="live-expand" data-live-expand aria-expanded="${open}" aria-controls="live-body-${i}"><span class="chev" aria-hidden="true">${open?'▾':'▸'}</span> <span class="nm">${esc(x.service)}</span></button>
              ${isIP?'<span class="tg">غير معروف</span>':''}</div>
            ${x.host?'<div class="l2">'+esc(x.host)+'</div>':''}
          </div>
          <div class="dvm">
            <div class="k"><div class="v">${mb(x.bytes)}</div><div class="t">حجم البيانات</div></div>
            <div class="k"><div class="v">${x.conns}</div><div class="t">الاتصالات</div></div>
            <div class="k"><div class="v">${x.device_count}</div><div class="t">الأجهزة</div></div>
          </div>
          <div class="dva">
            <button class="btn btn-xs btn-danger" data-sv="${esc(x.service)}">احظره</button>
          </div>
        </div>
        <div class="dvs" id="live-body-${i}" data-body="${i}" ${open?'':'hidden'}>
          ${(x.devices||[]).map(ip=>`<div class="svr">
            <div class="sn"><b>${esc(nameOf(ip))}</b><span class="hh">${esc(ip)}</span></div>
            <div></div><div></div><div></div></div>`).join('')}
        </div>`;
      box.appendChild(card);
    });
  }
  $('#lvbox').innerHTML=''; $('#lvbox').appendChild(box);
}
$('#lvbox').addEventListener('click',e=>{
  const sort=e.target.closest('[data-live-sort]');
  if(sort){
    const scope=sort.dataset.liveScope, field=sort.dataset.liveSort;
    const parentKey=sort.closest('.dvc')?.dataset.liveKey;
    const [key,dir]=liveOrder[scope];
    liveOrder[scope]=[field,key===field?-dir:field==='name'?1:-1];
    drawLive();
    $$('#lvbox [data-live-sort]').find(b=>b.dataset.liveScope===scope &&
      b.dataset.liveSort===field && b.closest('.dvc')?.dataset.liveKey===parentKey)?.focus();
    return;
  }
  /* the "blocked" badge jumps to the tab that can undo it */
  if(e.target.closest('[data-goblk]')){
    e.stopPropagation();
    showTab('blocked');
    return;
  }
  const hdr=e.target.closest('[data-open]');
  if(hdr && (!e.target.closest('button') || e.target.closest('[data-live-expand]'))){
    const card=hdr.closest('.dvc');
    const body=card.querySelector('[data-body]');
    if(body){
      body.hidden=!body.hidden;
      const open=!body.hidden;
      card.classList.toggle('open',open);
      const button=hdr.querySelector('[data-live-expand]');
      button.setAttribute('aria-expanded',String(open));
      button.querySelector('.chev').textContent=open?'▾':'▸';
      if(open) liveExpanded.add(card.dataset.liveKey); else liveExpanded.delete(card.dataset.liveKey);
    }
    return;
  }
  const sv=e.target.closest('[data-sv]');
  if(sv){
    const name=sv.dataset.sv;
    pend={kind:'blockservice',service:name};
    sheet('حظر '+name, name,
      'هيتحظر على <b>كل الشبكة</b>. كل نطاقات الخدمة هتتحظر مش واحد بس.',
      '', 'local-zone: "'+name+'" redirect','حظر',true);
    return;
  }
  const dv=e.target.closest('[data-dv]');
  if(dv){
    const d=S.devices.find(x=>x.mac===dv.dataset.m);
    if(!d){ alert('الجهاز مش معروف للنظام'); return; }
    if(dv.dataset.dv==='blk') askBlock(d); else askLimit(d,2);
  }
});
$('#lvref').onclick=loadLive;
$('#lvq').addEventListener('input',drawLive);
$$('[data-lvv]').forEach(b=>b.onclick=()=>{
  $$('[data-lvv]').forEach(x=>x.classList.remove('on'));
  b.classList.add('on'); lvView=b.dataset.lvv; loadLive();
});

/* ---------------- new-device policy ---------------- */
let POL=null, polDirty=false;
const FAM={android:'أندرويد',ios:'آيفون/آيباد',
  mac:'ماك',windows:'ويندوز',linux:'لينكس',
  iot:'أجهزة IoT',mobile_unknown:'موبايل (MAC عشوائي)',
  unknown:'غير معروف'};

async function loadPolicy(){
  $('#polbox').innerHTML='<div class="empty">بيحمّل…</div>';
  try{
    const d=await get('policy');
    if(!d.ok) throw new Error(d.error||'fail');
    POL=d; polDirty=false; drawPolicy();
  }catch(e){
    $('#polbox').innerHTML='<div class="empty">فشل التحميل: '+esc(e.message)+'</div>';
  }
}
function drawPolicy(){
  if(!POL) return;
  const p=POL.policy, pv=POL.preview||[], hs=POL.history||[];
  $('#c-pol').textContent = p.enabled ? pv.length : '—';

  const chip=(set,val,label)=>
    '<span class="chip'+(set.includes(val)?' on':'')+'" data-t="'+val+'">'+label+'</span>';
  const radio=(name,val,cur,label)=>
    '<span class="chip'+(cur===val?' on':'')+'" data-r="'+name+'" data-v="'+val+'">'+label+'</span>';

  $('#polbox').innerHTML=`
    <div class="pol">
      <div class="polh ${p.enabled?'on':''}">
        <label class="sw"><input type="checkbox" id="polon" ${p.enabled?'checked':''}><span></span></label>
        <div>
          <h4>سياسة الأجهزة الجديدة</h4>
          <div class="sub">${p.enabled
            ? 'شغالة — بتتطبق على أي جهاز جديد يدخل الشبكة'
            : 'موقوفة — مفيش أي إجراء تلقائي'}</div>
        </div>
        <button class="btn btn-sm btn-primary" id="polsave" style="margin-right:auto" disabled>حفظ</button>
      </div>
      <div class="polb">

        <div class="polf">
          <label>يتطبق على</label>
          <div class="fv"><div class="chips" id="poltg">
            ${chip(p.targets,'mobile','كل الموبايلات')}
            ${chip(p.targets,'android','أندرويد')}
            ${chip(p.targets,'ios','آيفون')}
            ${chip(p.targets,'windows','ويندوز')}
            ${chip(p.targets,'mac','ماك')}
            ${chip(p.targets,'linux','لينكس')}
            ${chip(p.targets,'iot','IoT')}
            ${chip(p.targets,'all','أي جهاز')}
          </div></div>
        </div>

        <div class="polf">
          <label>الإجراء</label>
          <div class="fv">
            <div class="chips" id="polact">
              ${radio('act','block',p.action,'منع كامل')}
              ${radio('act','limit',p.action,'تحديد سرعة')}
              ${radio('act','allow',p.action,'سماح (تسجيل بس)')}
            </div>
            <div class="limrow" id="limrow" ${p.action==='limit'?'':'hidden'}>
              <input type="range" id="pollim" min="1" max="20" value="${p.limit_mb||1}">
              <span class="num" id="pollimv">${p.limit_mb||1} Mb</span>
            </div>
          </div>
        </div>

        <div class="polf">
          <label>المدة</label>
          <div class="fv"><div class="chips" id="poldur">
            ${radio('dur','perm',p.duration,'دائم')}
            ${radio('dur','60',p.duration,'ساعة')}
            ${radio('dur','180',p.duration,'٣ ساعات')}
            ${radio('dur','eod',p.duration,'لآخر اليوم')}
          </div></div>
        </div>

        <div class="polf sw-row">
          <span class="fl">استثناءات</span>
          <div class="fv">
            <label class="swlab">
              <span class="sw g" style="margin:0"><input type="checkbox" id="respcp"
                ${p.respect_portal!==false?'checked':''}><span></span></span>
              استثنِ اللي سجّل دخول بيوزر
            </label>
            <div class="hint">
              ${POL.portal_active
                ? 'الكابتيف بورتال شغال · <b>'+(POL.portal_users||0)+
                  '</b> جهاز مسجّل دخول دلوقتي'
                : '⚠ الكابتيف بورتال موقوف — الخيار ده مش هيفرق'}
            </div>
          </div>
        </div>

        <div class="polf sw-row">
          <span class="fl">المواعيد</span>
          <div class="fv">
            <label class="swlab">
              <span class="sw g" style="margin:0"><input type="checkbox" id="schon"
                ${p.schedule.enabled?'checked':''}><span></span></span>
              شغّالة في مواعيد محددة بس
            </label>
            <div class="schrow" id="schrow" ${p.schedule.enabled?'':'hidden'}>
              <span class="lbl">من</span>
              <input type="time" id="schf" value="${p.schedule.from}">
              <span class="lbl">لغاية</span>
              <input type="time" id="scht" value="${p.schedule.to}">
              <div class="chips" id="schd">
                ${radio('days','all',p.schedule.days,'كل يوم')}
                ${radio('days','work',p.schedule.days,'أيام العمل')}
                ${radio('days','weekend',p.schedule.days,'العطلة')}
              </div>
            </div>
          </div>
        </div>

      </div>
    </div>

    <div class="polprev" style="margin-bottom:12px">
      <div class="ph">${p.enabled
        ? 'أجهزة هتتأثر دلوقتي لو السياسة اشتغلت'
        : 'معاينة — دول اللي هيتأثروا لو فعّلتها'}
        &nbsp;·&nbsp; <b>${pv.length}</b> جهاز</div>
      ${pv.length ? '<div class="pvlist">'+pv.map(x=>`<div class="pvr">
          <div class="pn"><b>${esc(x.name||x.ip)}</b>
            <span class="pm">${esc(x.ip)} · ${esc(x.mac)}</span></div>
          ${x.cp_user?'<span class="tg cpu"><i class="fa fa-user"></i> '+esc(x.cp_user)+'</span>':''}
          <span class="tg">${esc(FAM[x.family]||x.family)}</span>
          <span class="tg ${x.action==='block'?'ipb':''}">${
            x.action==='block'?'منع':x.action==='limit'?'تحديد':'تسجيل'}</span>
        </div>`).join('')+'</div>'
        : '<div class="empty" style="padding:16px">مفيش أجهزة جديدة مطابقة دلوقتي</div>'}
    </div>

    ${hs.length?`<div class="polprev">
      <div class="ph">اللي اتصرف فيه قبل كده · <b>${hs.length}</b></div>
      <div class="pvlist">${hs.map(x=>`<div class="pvr">
        <div class="pn"><b>${esc(x.name||x.ip||x.mac)}</b>
          <span class="pm">${esc(x.ip)} · ${esc(x.mac)}</span></div>
        <span class="tg">${x.blocked?'موقوف':x.limit?('حد '+x.limit+'Mb'):'مسجّل'}</span>
        <button class="btn btn-xs btn-default" data-forget="${esc(x.mac)}">اسمح له</button>
      </div>`).join('')}</div>
    </div>`:''}

    <div class="polw" style="margin-top:12px">
      <b>ازاي بتشتغل:</b>
      السياسة بتفحص الشبكة كل دقيقة. أي جهاز مش متشاف قبل كده
      ومطابق للشروط بيتصرف فيه مرة واحدة بس — بعدها يبقى معروف ومايتصرفش فيه تاني.<br>
      <b>نظام التشغيل</b> بيتحدد من اسم الجهاز والمصنّع — تخمين مش تأكيد.
      الموبايلات الحديثة بتغير الـ MAC كل ما تتصل، فهتظهر كجهاز جديد تاني.<br>
      <b>أجهزة النظام</b> (البوابة · السويتش · الأكسس بوينت · المضيف) مستثناة دائمًا.
    </div>`;

  const mark=()=>{ polDirty=true; $('#polsave').disabled=false; };

  $('#polon').onchange=mark;
  $('#poltg').onclick=e=>{
    const c=e.target.closest('[data-t]'); if(!c) return;
    c.classList.toggle('on'); mark();
  };
  $('#polact').onclick=e=>{
    const c=e.target.closest('[data-v]'); if(!c) return;
    $$('#polact [data-v]').forEach(x=>x.classList.remove('on'));
    c.classList.add('on');
    /* the CSS owns the layout now, so toggle visibility, not display */
    $('#limrow').hidden = c.dataset.v!=='limit';
    mark();
  };
  $('#poldur').onclick=e=>{
    const c=e.target.closest('[data-v]'); if(!c) return;
    $$('#poldur [data-v]').forEach(x=>x.classList.remove('on'));
    c.classList.add('on'); mark();
  };
  $('#schd').onclick=e=>{
    const c=e.target.closest('[data-v]'); if(!c) return;
    $$('#schd [data-v]').forEach(x=>x.classList.remove('on'));
    c.classList.add('on'); mark();
  };
  $('#pollim').oninput=e=>{ $('#pollimv').textContent=e.target.value+' Mb'; mark(); };
  $('#schon').onchange=e=>{
    $('#schrow').hidden = !e.target.checked; mark();
  };
  $('#schf').onchange=mark; $('#scht').onchange=mark;
  if($('#respcp')) $('#respcp').onchange=mark;

  $('#polbox').querySelectorAll('[data-forget]').forEach(b=>b.onclick=async()=>{
    if(!confirm('تسمح للجهاز ده وتشيل القيود؟')) return;
    await withProgress(b, async()=>{
      await api('policyforget',{mac:b.dataset.forget});
      loadPolicy(); await load(1); loadLog();
    });
  });

  $('#polsave').onclick=()=>{
    const targets=$$('#poltg .chip.on').map(x=>x.dataset.t);
    if(!targets.length){ alert('اختار نوع جهاز واحد على الأقل'); return; }
    const act=($('#polact .chip.on')||{}).dataset?.v||'block';
    const dur=($('#poldur .chip.on')||{}).dataset?.v||'perm';
    const days=($('#schd .chip.on')||{}).dataset?.v||'all';
    const on=$('#polon').checked;
    pend={kind:'savepolicy',body:{
      enabled:on?1:'', pact:act, pdur:dur,
      limit_mb:$('#pollim')?$('#pollim').value:1,
      targets:targets,
      resp_cp:($('#respcp')&&$('#respcp').checked)?1:'',
      sch_on:$('#schon').checked?1:'',
      sch_from:$('#schf').value, sch_to:$('#scht').value, sch_days:days
    }};
    const n=(POL.preview||[]).length;
    sheet(on?'تفعيل سياسة الأجهزة الجديدة':'حفظ السياسة (موقوفة)',
      targets.map(t=>({mobile:'الموبايلات',all:'أي جهاز'}[t]||FAM[t]||t)).join('، '),
      on ? `أي جهاز جديد مطابق هيتعمله <b>${
          act==='block'?'منع كامل':act==='limit'?'تحديد سرعة':'تسجيل بس'}</b>
          ${dur==='perm'?'':'لمدة '+({'60':'ساعة','180':'٣ ساعات',eod:'لآخر اليوم'}[dur])}.<br>
          <b>${n}</b> جهاز متصل دلوقتي هيتأثر فورًا.`
         : 'السياسة هتتحفظ بس مش هتشتغل.',
      on && n ? devList((POL.preview||[]).map(x=>({name:x.name,ip:x.ip,mac:x.mac}))) : '',
      '', on?'تفعيل':'حفظ');
  };
}

/*
 * The day's report.
 *
 * The figures come from the firewall itself rather than from the store, so
 * what the report shows is what is actually in force. The same numbers are
 * available as a standalone HTML file through the export link, which is a
 * plain download so it opens on a machine that cannot reach this box.
 */
let REP=null, repDay='';

async function loadReport(){
  const box=$('#rbox'); if(!box) return;
  box.innerHTML='<div class="empty" style="padding:24px">بيحمّل…</div>';
  try{
    const d = await get('report', repDay?('day='+encodeURIComponent(repDay)):'');
    REP=d.report||null;
    /* the day list only needs rebuilding when it actually changed */
    const sel=$('#rday');
    if(sel){
      const days=(d.days||[]).slice();
      const today=new Date().toISOString().slice(0,10);
      if(days.indexOf(today)===-1) days.unshift(today);
      const want=days.join('|');
      if(sel.dataset.days!==want){
        sel.dataset.days=want;
        sel.innerHTML=days.map(x=>'<option value="'+esc(x)+'">'+esc(x)+'</option>').join('');
      }
      if(REP) sel.value=REP.day;
    }
    repRender();
  }catch(e){
    box.innerHTML='<div class="empty" style="padding:24px">ماقدرش يقرا التقرير</div>';
  }
}

function repRender(){
  const box=$('#rbox'); if(!box||!REP) return;
  const r=REP, d=r.devices, u=r.usage, l=r.logins, e=r.enforcement;
  const exp=$('#rexp');
  if(exp) exp.href=API+'?action=reporthtml&day='+encodeURIComponent(r.day);
  const wk=$('#rweek');
  if(wk) wk.href=API+'?action=weekhtml';

  const kpi = (list) => '<div class="rkpi">'+list.map(x=>
    '<div class="rk'+(x[2]?' '+x[2]:'')+'"><div class="v">'+x[0]+
    '</div><div class="t">'+x[1]+'</div></div>').join('')+'</div>';

  const panel = (title, sub, body) =>
    '<div class="panel panel-default"><div class="panel-heading" '+
    'style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">'+
    '<h2 class="panel-title">'+title+'</h2>'+
    (sub?'<span style="font-size:11.5px;color:var(--dim)">'+sub+'</span>':'')+
    '</div><div class="panel-body">'+body+'</div></div>';

  const tot=u.total||0;
  const rows=(u.top||[]).map((x,i)=>{
    const who=x.name||x.user||x.ip||x.mac;
    const pct=tot?(x.bytes/tot*100):0;
    return '<tr><td class="c" style="color:var(--dim)">'+(i+1)+'</td>'+
      '<td class="num"><div class="nm"><span class="t">'+esc(who)+'</span></div>'+
      '<div class="m">'+esc((x.ip||'')+(x.mac?' · '+x.mac:''))+'</div></td>'+
      '<td class="num">'+mb(x.bytes)+'</td>'+
      '<td class="num">'+pct.toFixed(1)+'%</td>'+
      '<td><div class="rbar"><i style="width:'+Math.min(100,pct*2).toFixed(1)+'%"></i></div></td>'+
      '</tr>';
  }).join('');

  const peak=Math.max(1,...(l.by_hour||[0]));
  const bars=(l.by_hour||[]).map((n,h)=>
    '<div class="rhb" title="'+h+':00 — '+n+'"><i style="height:'+
    Math.max(2,Math.round(n/peak*100))+'%"></i><span>'+h+'</span></div>').join('');

  const users=(l.users||[]).map(x=>
    '<tr><td class="num">'+esc(x.user)+'</td>'+
    '<td class="num">'+x.logins+'</td>'+
    '<td class="num"'+(x.devices>3?' style="color:var(--crit);font-weight:700"':'')+
    '>'+x.devices+'</td></tr>').join('');

  const akeys=Object.keys(r.actions||{}).slice(0,12);
  const acts=akeys.map(k=>
    '<tr><td class="num">'+esc(k)+'</td><td class="num">'+r.actions[k]+'</td></tr>').join('');

  box.innerHTML =
    (r.partial
      ? '<div class="al warn" style="margin-bottom:14px"><span style="font-size:15px">⚠</span>'+
        '<div class="grow">اليوم لسه ماخلصش — الأرقام لغاية دلوقتي</div></div>'
      : '')

    + panel('الأجهزة','', kpi([
        [d.total,'الإجمالي',''],
        [d.online,'متصل دلوقتي','ok'],
        [d.blocked,'موقوف','bad'],
        [d.limited,'عليه حد سرعة','warn'],
        [d.portal,'مسجّل دخول',''],
        [d.random_mac,'MAC عشوائي','warn']
      ]))

    + panel('الاستهلاك','',
        kpi([
          [mb(tot),'الإجمالي 24 ساعة',''],
          [mb(u.work||0),'في ساعات العمل '+esc(u.hours||''),'ok'],
          [mb(u.off||0),'بره ساعات العمل','warn'],
          [mb(u.am||0)+' / '+mb(u.pm||0),'صباحًا / مساءً',''],
          [u.devices,'جهاز استهلك',''],
          [(r.line.down_limit||0)+' / '+(r.line.up_limit||0),
           'الحد العام نزول/رفع (ميجا)','']
        ])
        + (rows
          ? '<table class="ncgrid" style="min-width:0"><thead><tr>'+
            '<th class="c" style="width:34px"></th>'+
            '<th class="s">الجهاز</th>'+
            '<th class="num" style="width:95px">الاستهلاك</th>'+
            '<th class="num" style="width:70px">النسبة</th>'+
            '<th style="width:120px"></th></tr></thead><tbody>'+rows+'</tbody></table>'
          : '<div class="empty" style="padding:18px">لسه مافيش بيانات</div>'))

    + panel('الدخول','',
        kpi([
          [l.accepted,'دخول ناجح','ok'],
          [l.failed,'محاولة فاشلة','warn'],
          [l.kicked,'جلسة اتقطعت', l.kicked?'bad':'ok']
        ])
        + '<div class="rhours">'+bars+'</div>'
        + (users
          ? '<table class="ncgrid" style="min-width:0;margin-top:12px"><thead><tr>'+
            '<th class="s">المستخدم</th>'+
            '<th class="num" style="width:80px">دخول</th>'+
            '<th class="num" style="width:80px">أجهزة</th>'+
            '</tr></thead><tbody>'+users+'</tbody></table>'
          : ''))

    + panel('التنفيذ الفعلي',
        'مقروء من الفايروول نفسه',
        kpi([
          [e.blocked_in_table,'عنوان محطور فعلًا',''],
          [e.shaping_rules,'قاعدة تحديد سرعة',''],
          [e.site_blocks,'موقع محطور',''],
          [e.dns_redirect?'شغّال':'موقوف',
           'DNS', e.dns_redirect?'ok':'bad'],
          [e.doh_blocked?'شغّال':'موقوف',
           'DoH', e.doh_blocked?'ok':'bad']
        ]))

    + (acts ? panel('إجراءات النظام','',
        '<table class="ncgrid" style="min-width:0"><thead><tr>'+
        '<th class="s">الإجراء</th>'+
        '<th class="num" style="width:80px">مرات</th>'+
        '</tr></thead><tbody>'+acts+'</tbody></table>') : '');
}

function renderMonitor(){
  if (!monitorSamples.length) return;

  const latest = monitorSamples[monitorSamples.length - 1];
  const scale = Math.max(1, ...monitorSamples.flatMap(sample=>[sample.down,sample.up]));
  const plot = $('#nc-monitor-plot');
  const rate = value=>value.toFixed(1)+' Mb/s';
  plot.setAttribute('aria-label', monitorSamples.map(sample=>hhmm(sample.at)+' ↓ '+rate(sample.down)+' ↑ '+rate(sample.up)).join(', '));
  plot.replaceChildren(...monitorSamples.map(sample=>{
    const pair = document.createElement('div');
    pair.className = 'sample';
    pair.title = hhmm(sample.at)+' · ↓ '+rate(sample.down)+' · ↑ '+rate(sample.up);
    for (const [value, css] of [[sample.down,'download'],[sample.up,'upload']]){
      const bar = document.createElement('i');
      bar.className = css;
      bar.style.height = value / scale * 100+'%';
      pair.appendChild(bar);
    }
    return pair;
  }));
  $('#nc-monitor-first').textContent = hhmm(monitorSamples[0].at);
  $('#nc-monitor-last').textContent = hhmm(latest.at);
  $('#nc-monitor-down').textContent = rate(latest.down);
  $('#nc-monitor-up').textContent = rate(Math.max(0, Number(S.rates.wan_out)||0));
  $('#nc-monitor-devices').textContent = S.devices.filter(device=>device.online).length;
  $('#nc-monitor-empty').hidden = true;
  $('#nc-monitor-series').hidden = false;
}

function showTab(t){
  tab=t;
  $$('.tabs button[data-t]').forEach(b=>{
    const current=b.dataset.t===t;
    b.classList.toggle('on',current);
    if(current) b.setAttribute('aria-current','page'); else b.removeAttribute('aria-current');
  });
  /* a tab living in the More menu lights the menu button instead */
  $('#tmore').classList.toggle('on', !!$('#tmenu [data-t="'+t+'"]'));
  /* drive this off the sections that exist, so a new tab needs no edit here */
  $$('#nc section[id^="t-"]').forEach(sec=>{
    sec.hidden = sec.id !== 't-'+t;
  });
  if(t==='clean')  loadStale();
  if(t==='live')   loadLive();
  if(t==='policy') loadPolicy();
  if(t==='log')    loadLog();
  if(t==='hist')   loadHist();
  if(t==='video')  loadVideo();
  if(t==='blocked') blocked();
  if(t==='report') loadReport();
}
function toggleMore(open){
  $('#tmenu').hidden=!open;
  $('#tmore').setAttribute('aria-expanded',String(open));
}
$$('.tabs button[data-t]').forEach(b=>b.onclick=()=>{
  const fromMore=b.closest('#tmenu');
  showTab(b.dataset.t);
  toggleMore(false);
  if(fromMore) $('#tmore').focus();
});
$('#tmore').onclick=()=>toggleMore($('#tmenu').hidden);
$('.tmore').addEventListener('keydown',e=>{
  if(e.key==='Escape' && !$('#tmenu').hidden){
    toggleMore(false);
    $('#tmore').focus();
  }
});

/* a banner can send you to the tab that holds the detail */
document.addEventListener('click',e=>{
  const g=e.target.closest('[data-gotab]'); if(!g) return;
  showTab(g.dataset.gotab);
});

/* sections on/off, remembered per browser */
if($('#grpsw')) $('#grpsw').onclick=()=>{
  grouping=!grouping;
  try{ localStorage.setItem('nc_group', grouping?'1':'0'); }catch(e){}
  $('#grpsw').classList.toggle('on',grouping);
  devices();
};

if($('#rref')) $('#rref').onclick=()=>loadReport();
if($('#rday'))  $('#rday').onchange=e=>{ repDay=e.target.value; loadReport(); };

bindSort(); bindResize(); applyWidths();
load(1); loadLog(); loadLive();
function pollState(){
  if (!document.hidden && !statePending && !$('#sheet')) load(1);
}
setInterval(pollState, 10000);
document.addEventListener('visibilitychange', pollState);
})();
</script>

<?php include("foot.inc"); ?>
<script>

/* =====================================================================
 * Bilingual layer.
 *
 * Translation happens on the rendered DOM rather than in the ~400 places
 * that build it. Those strings are spread through template literals and
 * concatenated fragments, and editing each one is where a bilingual pass
 * breaks a working page. Here the page renders exactly as it always did,
 * and a pass over the result swaps the text.
 *
 * A phrase with no entry simply stays Arabic - a visible gap, never a
 * broken screen.
 * ===================================================================== */
(function(){
  var PAIRS = [["فيديو يوتيوب وتيك توك بياكل معظم الخط. امنعه أو حدده — على الكل أو على أجهزة محددة.", "YouTube and TikTok video eat most of the line. Block or limit it — network-wide or for chosen devices."], ["ومطابق للشروط بيتصرف فيه مرة واحدة بس — بعدها يبقى معروف ومايتصرفش فيه تاني.", "and matching the rules is acted on once — after that it is known and left alone."], ["الرابط الكامل للفيديو مشفّر جوه الاتصال ومايظهرش لأي فايروول من غير", "The full video URL is encrypted inside the connection and is invisible to any firewall without"], ["مفيش محاولات مسجّلة — بيتسجّل هنا أول ما حد يحاول يفتح موقع محظور", "No attempts logged — one appears here as soon as someone tries a blocked site"], ["طبّق قاعدة على مجموعة كاملة. الضم بيتم من تبويب الأجهزة (حدد ثم", "Apply a rule to a whole group. Membership is set from the Devices tab (select, then"], ["قواعد بتشتغل وتقف لوحدها على السيرفر — مش محتاج تفتح الصفحة.", "Rules that start and stop by themselves on the firewall — no need to keep this page open."], ["البوابة · السويتش · الأكسس بوينت · المضيف) مستثناة دائمًا.", "gateway · switch · access point · host) are always exempt."], ["انتهت صلاحية الجلسة — اعمل تحديث للصفحة (F5) وحاول تاني", "Session expired — refresh the page (F5) and try again"], ["السياسة بتفحص الشبكة كل دقيقة. أي جهاز مش متشاف قبل كده", "The policy scans the network every minute. Any device not seen before"], ["هيترفع الحظر، والحدود اللي اتحطت بسبب الخدمة دي هتتشال.", "The block is lifted, and limits applied because of this service are removed."], ["تحكّم لحظي في استهلاك الإنترنت وإدارة الأجهزة المتصلة", "Live control over internet usage and connected devices"], ["الأسماء دي هي السيرفرات اللي الجهاز متصل بيها فعلاً.", "These are the servers the device is actually connected to."], ["جهاز موقوف ولسه بيسحب داتا — الحظر مش بيتنفّذ عليه", "blocked device(s) still pulling data — the block is not being enforced"], ["بيتحدد من اسم الجهاز والمصنّع — تخمين مش تأكيد.", "Guessed from the device name and vendor — a guess, not a certainty."], ["بتاعه اتغير — القواعد اتنقلت تلقائيًا معاه بالـ", "changed — the rules moved with it automatically by"], ["الجهاز ده من البنية الأساسية ومايتوقفش من هنا", "This device is infrastructure and cannot be blocked here"], ["الجهاز ده من البنية الأساسية ومايتحددش من هنا", "This device is infrastructure and cannot be limited here"], ["الجهاز بيغير الـ MAC كل مرة — مش مشاركة حساب", "The device changes its MAC each time - not account sharing"], ["انتهت صلاحية الجلسة — اعمل تحديث للصفحة (F5)", "Session expired - refresh the page (F5)"], ["شغالة — بتتطبق على أي جهاز جديد يدخل الشبكة", "On — applies to any new device joining the network"], ["الاتصال بالسيرفر انقطع — حاول تاني بعد لحظة", "Lost contact with the firewall - try again in a moment"], ["الحد الخاص هيتشال وكل جهاز يرجع للحد العام.", "Own limits are removed; every device falls back to the global limit."], ["نسخة احتياطية بتتاخد أوتوماتيك قبل التنفيذ", "A backup is taken automatically before applying"], ["الكابتيف بورتال موقوف — الخيار ده مش هيفرق", "Captive portal is off — this option makes no difference"], ["بيتطبق على كل الأجهزة اللي مالهاش حد خاص —", "Applies to every device without its own limit —"], ["بيتطبق على كل الأجهزة اللي مالهاش حد خاص", "Applies to every device without its own limit"], ["اليوم لسه ماخلصش — الأرقام لغاية دلوقتي", "The day is not over yet — figures are up to now"], ["كل إجراء اتعمل على الشبكة، الأحدث فوق", "Every action taken on the network, newest first"], ["معاينة — دول اللي هيتأثروا لو فعّلتها", "Preview — these would be affected if you enable it"], ["أجهزة هتتأثر دلوقتي لو السياسة اشتغلت", "Devices that would be affected right now if the policy ran"], ["MAC عشوائي ومافيش اسم ثابت نتابعه بيه", "Random MAC with no stable name to follow it by"], ["الحد الأقصى لكل جهاز مامعاهوش حد خاص", "Maximum for each device without its own limit"], ["جهاز شبكة — محمي من الإيقاف والتحديد", "Network device - protected from blocking and limiting"], ["ابحث باسم الجهاز أو عنوان IP او MAC", "Search by device name, IP or MAC"], ["كل ما تتصل، فهتظهر كجهاز جديد تاني.", "on every connection, so they appear as a new device each time."], ["عشوائي ومافيش اسم ثابت نتابعه بيه —", "random MAC with no stable name to follow it by —"], ["كل أجهزة المجموعة هيتقطع عنها النت.", "Every device in the group will lose internet."], ["الأجهزة اللي ليها حد خاص مش هتتأثر.", "Devices with their own limit are unaffected."], ["كل نطاقات الخدمة هتتحظر مش واحد بس.", "Every domain the service uses is blocked, not just one."], ["لسه مافيش بيانات — بتتجمع كل دقيقة", "No data yet — it is collected every minute"], ["الأجهزة دي هترجع تتصل بالنت فورًا.", "These devices will reconnect immediately."], ["بس — مافيش طريقة تحدّ خدمة لوحدها.", "— there is no way to throttle one service on its own."], ["الجهاز ده مابقاش ظاهر — اعمل تحديث", "That device is no longer visible — refresh"], ["مافيش حد بيتفرّج على فيديو دلوقتي", "Nobody is watching video right now"], ["عشوائي ومافيش اسم ثابت نتابعه بيه", "random MAC with no stable name to follow it by"], ["، وأي حد يبدأ بعد كده هيتحد كمان.", ", and anyone who starts afterwards is limited too."], ["حدد أجهزة من تبويب الأجهزة الأول", "Select devices from the Devices tab first"], ["مفيش حد بيتفرّج على فيديو دلوقتي", "Nobody is watching video right now"], ["مين حاول يفتح موقع محظور وإمتى.", "Who tried to open a blocked site, and when."], ["كل الأجهزة دي هيتقطع عنها النت.", "All these devices will lose internet."], ["الرفع بيزاحم النزول، فخليه أقل", "Upload competes with download, so keep it lower"], ["مفيش أجهزة جديدة مطابقة دلوقتي", "No new matching devices right now"], ["جهاز متصل دلوقتي هيتأثر فورًا.", "connected device(s) will be affected immediately."], ["موقوفة — مفيش أي إجراء تلقائي", "Off — no automatic action"], ["عشوائي، بس الجهاز متابَع باسم", "random MAC, but the device is tracked by the name"], ["وممكن يفلت لو الجهاز غيّر الـ", "and it may escape if the device changes its"], ["جديد، الحظر بيتابعه تلقائيًا.", "the block follows it automatically."], ["حد النزول هيبقى 2 ميجا لكلها.", "The download limit becomes 2 Mb for all of them."], ["الجهاز هياخد الخط بلا حد (كان", "The device gets the line with no limit (was"], ["جهاز متصل دلوقتي هيتأثر فورًا", "connected device(s) affected immediately"], ["اختار نوع جهاز واحد على الأقل", "Choose at least one device type"], ["مفيش أجهزة على الحد ده دلوقتي", "No devices at that limit right now"], ["كل جهاز في المجموعة هياخد لحد", "Every device in the group gets up to"], ["الموبايلات الحديثة بتغير الـ", "Modern phones change their"], ["اعتراض الشهادات على كل جهاز.", "installing an intercepting certificate on every device."], ["القواعد اتنقلت تلقائيًا بالـ", "the rules moved automatically by"], ["تسمح للجهاز ده وتشيل القيود؟", "Allow this device and remove the restrictions?"], ["الحد الجديد هيتطبق على كلها.", "The new limit applies to all of them."], ["كل نطاقات الخدمة مش واحد بس.", "every domain the service uses, not just one."], ["السياسة هتتحفظ بس مش هتشتغل.", "The policy is saved but will not run."], ["استثنِ اللي سجّل دخول بيوزر", "Exempt anyone signed in with an account"], ["هيتحظر على كل أجهزة الشبكة.", "It will be blocked for every device on the network."], ["تفعيل سياسة الأجهزة الجديدة", "Enable the new-device policy"], ["سرعة النزول (تصفّح وتنزيل)", "Download speed (browsing & downloads)"], ["الجهاز هيرجع بحده الحالي (", "The device returns to its current limit ("], ["أي جهاز جديد مطابق هيتعمله", "Any matching new device will be"], ["نزول / رفع — مرجع المقارنة", "down / up — the comparison baseline"], ["الحد العام نزول/رفع (ميجا)", "Global limit down/up (Mb)"], ["MAC عشوائي — نوعه مش معروف", "Random MAC - type unknown"], ["شغّالة في مواعيد محددة بس", "Only active during set hours"], ["الحظر بيتنقل معاه تلقائي.", "the block follows it automatically."], ["يحظر الخدمة على كل الشبكة", "Blocks the service network-wide"], ["السيرفر رجّع رد غير متوقع", "The firewall returned an unexpected reply"], ["مافيش فيديو مفتوح دلوقتي", "No video open right now"], ["موقوف ومع ذلك بيمرر داتا", "blocked yet still passing data"], ["اللي اتصرف فيه قبل كده ·", "Previously acted on ·"], ["ماقدرش يقرا البث المفتوح", "Could not read the open streams"], ["لا — عنوان متغير من DHCP", "No - dynamic address from DHCP"], ["<span class=\"tg ipb\">حد ", "<span class=\"tg ipb\">limit "], ["الحد أعلى من الخط بكتير", "far above what the line can carry"], ["عشوائي: الحظر بيتم بالـ", "random MAC: the block is applied by"], ["مفيش فيديو مفتوح دلوقتي", "No video open right now"], ["مقروء من الفايروول نفسه", "Read from the firewall itself"], ["تجميع الأجهزة حسب النوع", "Group devices by type"], ["مراقبة الشبكة والأجهزة", "Network & Device Monitor"], ["بحث باسم أو IP أو MAC…", "Search by name, IP or MAC…"], ["الفيديو المفتوح دلوقتي", "Video Open Now"], ["اللي اتصرف فيه قبل كده", "Previously acted on"], ["جهاز محظور ممكن يفلت —", "blocked device(s) may escape —"], ["هيترفع الحظر عنهم كلهم", "The block will be lifted from all of them"], ["تحديد النزول بـ 2 ميجا", "Limit download to 2 Mb"], ["مسح حجوزات غير مستخدمة", "Delete unused reservations"], ["جاري تحميل حالة الشبكة", "Loading network state"], ["اسحب لتغيير عرض العمود", "Drag to resize the column"], ["جهاز مسجّل دخول دلوقتي", "device(s) signed in right now"], ["الكابتيف بورتال شغال ·", "Captive portal is on ·"], ["الجهاز مش معروف للنظام", "The device is unknown to the system"], ["الاتصال بالسيرفر انقطع", "Lost contact with the firewall"], ["سياسة الأجهزة الجديدة", "New-Device Policy"], ["إيقاف الأجهزة المحددة", "Block selected devices"], ["شيل الجهاز من الأنبوب", "Remove the device from its pipe"], ["عنوان محجوز للجهاز ده", "Address reserved for this device"], ["حركة الشبكة الداخلية", "Internal Traffic"], ["النزول (تصفح وتنزيل)", "Download (browsing & downloads)"], ["إيقاف النت عن الجهاز", "Cut internet from device"], ["حفظ السياسة (موقوفة)", "Save policy (disabled)"], ["الحجوزات هتتمسح والـ", "The reservations are deleted and the"], ["ماقدرش يقرا البيانات", "Could not read the data"], ["جهاز على الحد دلوقتي", "devices at the limit now"], ["الحد العام لكل جهاز", "Global Per-Device Limit"], ["مفيش نتايج للبحث ده", "No results for that search"], ["عشوائي — الحظر بالـ", "random MAC — blocked by"], ["قاعدة مؤقتة شغالة —", "temporary rule(s) active —"], ["روح لتبويب الموقوفة", "Go to the Blocked tab"], ["ماقدرش يقرا التقرير", "Could not read the report"], ["جهاز أونلاين دلوقتي", "devices online right now"], ["جهاز في المجموعة دي", "devices in this group"], ["الاستهلاك الإجمالي", "Total Usage"], ["مفيش قواعد بمواعيد", "No scheduled rules"], ["بحث باسم الجهاز أو", "Search by device name or"], ["إعفاء من حد السرعة", "Exempt from the speed limit"], ["ماقدرش يحفظ الاسم:", "Could not save the name:"], ["اتخطى أجهزة محمية:", "Skipped protected devices:"], ["سعة النزول / الرفع", "Download / upload capacity"], ["الوارد من الإنترنت", "Inbound from internet"], ["الدخول والاستهلاك", "Logins & Usage"], ["الجهاز · IP · MAC", "Device · IP · MAC"], ["ماقدرش يقرا السجل", "Could not read the history"], ["مفيش أجهزة مطابقة", "No matching devices"], ["مفيش أجهزة موقوفة", "No blocked devices"], ["إرجاع النت للجهاز", "Restore internet to device"], ["كل جهاز هياخد لحد", "Each device gets up to"], ["كل استخدام الجهاز", "everything the device does"], ["لو جهاز رجع، ياخد", "If a device returns it gets a"], ["مفيش مواقع محظورة", "No blocked sites"], ["مفيش خدمات مطابقة", "No matching services"], ["عنوان محطور فعلًا", "address actually blocked"], ["الجهاز دلوقتي على", "device is now on"], ["منها رايح للأجهزة", "of it goes to devices"], ["استهلاك الإنترنت", "Internet Usage"], ["الاستهلاك اليومي", "Daily Usage"], ["مفيش إجراءات لسه", "No actions yet"], ["أو سمّيه يدوياً.", "or give it a name manually."], ["أضعف وممكن يفلت.", "which is weaker and may be escaped."], ["تحذف القاعدة دي؟", "Delete this rule?"], ["تغيير الحد العام", "Change the global limit"], ["الأجهزة الموقوفة", "Blocked devices"], ["داخل من الإنترنت", "inbound from the internet"], ["على فيديو دلوقتي", "video right now"], ["مفيش فيديو مفتوح", "No video open"], ["قاعدة تحديد سرعة", "speed-limit rule"], ["الإجمالي 24 ساعة", "24-hour total"], ["لسه مافيش بيانات", "No data yet"], ["التحكم في الشبكة", "Network control"], ["الأجهزة المتصلة", "Connected Devices"], ["اكتب اسم الجهاز", "Enter a device name"], ["سماح (تسجيل بس)", "Allow (log only)"], ["مفيش حد بيتفرّج", "Nobody is watching"], ["تغيير حد النزول", "Change download limit"], ["الحد بيتطبق على", "The limit applies to"], ["اختار المجموعة:", "Choose the group:"], ["البنية الأساسية", "Infrastructure"], ["مفيش حاجة غائبة", "Nothing stale"], ["بحث في الموقوفة", "Search blocked"], ["موقوفة / محدودة", "blocked / limited"], ["عشوائي · متابَع", "random · tracked"], ["الشبكة الداخلية", "Internal network"], ["خطأ في الاتصال:", "Connection error:"], ["قاعدة Temporary", "Temporary rule"], ["بره ساعات العمل", "Outside work hours"], ["الـ IP اتغير من", "The IP changed from"], ["الصادر للإنترنت", "Outbound to internet"], ["المفتوح دلوقتي", "Live Now"], ["تحديث البيانات", "Refresh"], ["إعدادات الشبكة", "Network Settings"], ["استهلاك كبير —", "Heavy usage —"], ["تشيل الحظر ده؟", "Remove this block?"], ["المجموعة فاضية", "The group is empty"], ["اكتب نطاق صحيح", "Enter a valid domain"], ["إجراءات النظام", "System Actions"], ["التنفيذ الفعلي", "Actually Enforced"], ["في ساعات العمل", "Within work hours"], ["صباحًا / مساءً", "AM / PM"], ["سيرفرات وتخزين", "Servers & storage"], ["مباشر · اتحدّث", "Live · updated"], ["جلسات الاتصال", "Connection States"], ["حفظ التغييرات", "Save Changes"], ["السرعة دلوقتي", "Current Speed"], ["الصفحة مفتوحة", "Page open"], ["على كل الشبكة", "network-wide"], ["كل الموبايلات", "All phones"], ["بمواعيد محددة", "On a schedule"], ["سجل الإجراءات", "Action Log"], ["هيعدي. احجزله", "it will get through. Reserve it an"], ["كلهم متابَعين", "all tracked"], ["بيتفرّج فعلاً", "actually watching"], ["إلغاء التحديد", "Clear selection"], ["بحث بالاسم أو", "Search by name or"], ["إلغاء التصفية", "Clear filter"], ["تفاصيل الجهاز", "Device details"], ["خارج للإنترنت", "outbound to the internet"], ["لو الجهاز أخد", "If the device takes"], ["مُضيف افتراضي", "Hypervisor"], ["أقربها بتنتهي", "the soonest ends at"], ["مرجع المقارنة", "comparison baseline"], ["مش متعرف عليه", "Unidentified"], ["بـ MAC عشوائي", "with a random MAC"], ["موقوف / محدود", "Blocked / limited"], ["سياسة الجديد", "New-Device Policy"], ["غالبًا فيديو", "Probably video"], ["فيديو فيسبوك", "Facebook Video"], ["فلترة السجل…", "Filter the log…"], ["أجهزة النظام", "System devices"], ["ازاي بتشتغل:", "How it works:"], ["نظام التشغيل", "Operating system"], ["بنتابعه باسم", "tracked by the name"], ["الأجهزة اللي", "The devices"], ["إيقاف مجموعة", "Block group"], ["تحديد مجموعة", "Limit group"], ["مع تثبيت حجز", "with a reservation pinned"], ["فشل التحميل:", "Load failed:"], ["عليه حد سرعة", "speed-limited"], ["محاولة فاشلة", "failed attempt"], ["جهاز افتراضي", "Virtual machine"], ["قاعدة مخزّنة", "stored rule"], ["أجهزة الشبكة", "Network equipment"], ["عناوين ثابتة", "Fixed addresses"], ["تجميع: النوع", "Group: Category"], ["من غير تجميع", "No grouping"], ["سرعة المصدر", "Line Speed"], ["الخط يستحمل", "the line can carry this"], ["شغال دلوقتي", "Active now"], ["سمّي الجهاز", "Rename device"], ["حدّه 1 ميجا", "Limit to 1 Mb"], ["الحد الحالي", "Current limit"], ["لو غيّر الـ", "if it changes its"], ["بتتفرّج على", "watching"], ["ضمّ لمجموعة", "Add to group"], ["أجهزة محددة", "selected devices"], ["يوم أو أكتر", "days or more"], ["فلترة السجل", "Filter the log"], ["آيفون/آيباد", "iPhone/iPad"], ["جهاز دلوقتي", "devices now"], ["فيديو مفتوح", "video open"], ["قاعدة مؤقتة", "Temporary rule"], ["متصل دلوقتي", "online now"], ["جلسة اتقطعت", "session dropped"], ["جهاز استهلك", "devices used data"], ["ملف الأسبوع", "Weekly file"], ["إدارة سيرفر", "Server mgmt"], ["الطلب اترفض", "The request was refused"], ["قواعد مؤقتة", "temporary rules"], ["حد للمجموعة", "Set group limit"], ["فشل التحميل", "Load failed"], ["سرعة الرفع", "Upload speed"], ["تنافس عادي", "normal contention"], ["مسجّل دخول", "Signed in"], ["MAC عشوائي", "Random MAC"], ["اسم الهوست", "Hostname"], ["تحديد سرعة", "Limit speed"], ["لآخر اليوم", "Until end of day"], ["أيام العمل", "Workdays"], ["المستخدمين", "Users"], ["سجل الدخول", "Login History"], ["مفيش نتايج", "No results"], ["هيتحظر على", "Will be blocked on"], ["هياخدوا حد", "will get a limit of"], ["جديد عادي.", "new one normally."], ["شيل القيود", "Remove restrictions"], ["تحديد الكل", "Select all"], ["مسح المحدد", "Delete selected"], ["جهاز محدد.", "selected device(s)."], ["حذف حجوزات", "Delete reservations"], ["حسب الجهاز", "By device"], ["حسب الخدمة", "By service"], ["بالسياسة ·", "by policy ·"], ["الموبايلات", "Phones"], ["أونلاين من", "online of"], ["جهاز متأثر", "device affected"], ["الحد هيبقى", "The limit becomes"], ["الحظر بالـ", "blocked by"], ["مدة الجلسة", "Session"], ["تنزيل HTML", "Download HTML"], ["موقع محطور", "site blocked"], ["أكسس بوينت", "Access point"], ["جهاز Apple", "Apple device"], ["جلسات (PF)", "Sessions (PF)"], ["المجموعات", "Groups"], ["حظر مواقع", "Site Blocking"], ["تنظيف MAC", "MAC Cleanup"], ["عنوان MAC", "MAC Address"], ["الاستهلاك", "Data"], ["حد النزول", "Download Limit"], ["الاتصالات", "Conns"], ["ممكن يفلت", "Can escape"], ["شغّل الكل", "Unblock all"], ["يتطبق على", "Applies to"], ["استثناءات", "Exceptions"], ["هترجع للـ", "return to the"], ["جهاز محدد", "selected device"], ["الكاميرات", "Cameras"], ["غير معروف", "Unknown"], ["للأجهزة ·", "to devices ·"], ["حد 2 ميجا", "2 Mb limit"], ["ميجا (كان", "Mb (was"], ["كل الشبكة", "the whole network"], ["الحفظ فشل", "Save failed"], ["على فيديو", "video"], ["دخول ناجح", "successful login"], ["MAC متغير", "MAC rotating"], ["سرعة الخط", "Line speed"], ["حسب النوع", "By category"], ["حد السرعة", "Speed limit"], ["الموقوفة", "Blocked"], ["المواعيد", "Schedules"], ["عنوان IP", "IP Address"], ["الإجمالي", "Total"], ["المجموعة", "Group"], ["مش مسجّل", "Not signed in"], ["IP اتغير", "IP changed"], ["بالسياسة", "By policy"], ["التفاصيل", "Details"], ["مسجّل بـ", "Signed in as"], ["حجز DHCP", "DHCP reservation"], ["آخر ظهور", "Last seen"], ["انستغرام", "Instagram"], ["منع كامل", "Block fully"], ["اتغير من", "changed from"], ["جهاز الـ", "device whose"], ["متربط بـ", "bound to"], ["حظر موقع", "Block a site"], ["متابَع ·", "tracked ·"], ["موبايل (", "phone ("], ["جهاز على", "device on"], ["تسجيل بس", "log only"], ["المستخدم", "User"], ["التقارير", "Reports"], ["جهاز IoT", "IoT device"], ["رقم تاني", "Other"], ["الأجهزة", "Devices"], ["الفيديو", "Video"], ["محاولات", "Attempts"], ["أوفلاين", "Offline"], ["اسمح له", "Allow"], ["المصنّع", "Vendor"], ["بيتفرّج", "Watching"], ["تيك توك", "TikTok"], ["نتفليكس", "Netflix"], ["أندرويد", "Android"], ["أي جهاز", "Any device"], ["الإجراء", "Action"], ["٣ ساعات", "3 hours"], ["بيحمّل…", "Loading…"], ["ملحوظة:", "Note:"], ["الإدارة", "Management"], ["المكاتب", "Offices"], ["أو خدمة", "or service"], ["موقوف ·", "blocked ·"], ["عشوائي)", "random)"], ["اتصال ·", "connections ·"], ["حدّها 2", "Limit to 2"], ["تشغيلهم", "Unblock them"], ["الخدمات", "Services"], ["كمبيوتر", "Computer"], ["أونلاين", "online"], ["كمان في", "more in"], ["عرض أقل", "Show less"], ["القائمة", "the list"], ["من أصل", "of"], ["الجهاز", "Device"], ["النشاط", "Signed in as"], ["الحالة", "Status"], ["حظر بـ", "Blocked by"], ["بينزّل", "Downloading"], ["الأعلى", "Heaviest"], ["محدودة", "Limited"], ["موقوفة", "Blocked"], ["عشوائي", "Random"], ["متابَع", "Tracked"], ["بلا حد", "No limit"], ["بيعدّي", "LEAKING"], ["تفاصيل", "Details"], ["أوقفها", "Block"], ["احظرها", "Block service"], ["يوتيوب", "YouTube"], ["فيسبوك", "Facebook"], ["واتساب", "WhatsApp"], ["ويندوز", "Windows"], ["كل يوم", "Every day"], ["العطلة", "Weekend"], ["مستخدم", "users"], ["مش على", "not only"], ["الضيوف", "Guests"], ["بيحمّل", "Loading"], ["نزول و", "down and"], ["الجلسة", "Session"], ["محاولة", "attempt"], ["جهاز ×", "devices ×"], ["جهاز ·", "devices ·"], ["دلوقتي", "now"], ["هيعدي.", "it will get through."], ["النسبة", "Share"], ["الدخول", "Logins"], ["كاميرا", "Camera"], ["موبايل", "Phone"], ["المزيد", "More"], ["السجل", "Log"], ["موقوف", "Blocked"], ["الرفع", "Upload"], ["النوع", "Type"], ["إجراء", "Actions"], ["مؤقتة", "Temporary"], ["تحديث", "Refresh"], ["اتصال", "conns"], ["أجهزة", "devices"], ["إيقاف", "Block"], ["تشغيل", "Unblock"], ["تطبيق", "Apply"], ["إلغاء", "Cancel"], ["إغلاق", "Close"], ["عرضهم", "Show them"], ["أوقفه", "Block"], ["حدّها", "Limit"], ["تويتش", "Twitch"], ["مسموح", "Allowed"], ["محدود", "Limited"], ["ممنوع", "Blocked"], ["آيفون", "iPhone"], ["لينكس", "Linux"], ["المدة", "Duration"], ["لغاية", "to"], ["تحديد", "Limit"], ["تسجيل", "Log"], ["اتقطع", "Disconnected"], ["ساعات", "hours"], ["دقيقة", "min"], ["غيرهم", "Others"], ["محمية", "protected"], ["إضافة", "Add"], ["احظره", "Block it"], ["اعرضه", "Show it"], ["مثال:", "e.g."], ["الوصف", "Description"], ["عنوان", "Address"], ["تنظيف", "Cleanup"], ["محجوز", "reserved"], ["مسجّل", "registered"], ["الحد:", "Limit:"], ["تفعيل", "Enable"], ["تنفيذ", "Run"], ["جلسات", "sessions"], ["من 31", "of 31"], ["هيعدي", "will get through"], ["اتغير", "changed"], ["شغّله", "Unblock"], ["شغّال", "On"], ["اليوم", "Day"], ["طابعة", "Printer"], ["سويتش", "Switch"], ["راوتر", "Router"], ["سيرفر", "Server"], ["تخزين", "Storage"], ["تنزيل", "Download"], ["تجميع", "Group"], ["تعديل", "Edit"], ["فلاتر", "Filters"], ["إظهار", "Show"], ["(عام)", "(global)"], ["نزول", "down"], ["الكل", "All"], ["بحث…", "Search…"], ["نظام", "System"], ["ثابت", "Reserved"], ["معفى", "Exempt"], ["محمي", "Protected"], ["ساكن", "Idle"], ["جهاز", "device"], ["يدوي", "Manual"], ["اعرض", "View"], ["دائم", "Permanent"], ["ساعة", "1 hour"], ["دخول", "logins"], ["ميجا", "Mb"], ["احظر", "Block"], ["خدمة", "service"], ["منها", "of which"], ["لمدة", "for"], ["أيام", "days"], ["رجوع", "Back"], ["رفع.", "up."], ["حدّه", "Limit it"], ["عنصر", "item"], ["فاضي", "empty"], ["شغال", "active"], ["محدد", "selected"], ["فشل:", "Failed:"], ["الحد", "Limit"], ["مرات", "times"], ["12 ص", "12am"], ["12 ظ", "12pm"], ["تمام", "OK"], ["متاح", "free"], ["رفع", "upload"], ["ألف", "K"], ["حفظ", "Save"], ["بحث", "Search"], ["نشط", "Active"], ["حظر", "Block"], ["نعم", "Yes"], ["ماك", "Mac"], ["منع", "Block"], ["حدث", "events"], ["دخل", "Signed in"], ["يوم", "days"], ["حذف", "Delete"], ["مسح", "Clear"], ["حجز", "Reserve"], ["حتى", "until"], ["على", "on"], ["حسب", "by"], ["6 ص", "6am"], ["6 م", "6pm"], ["لا", "No"], ["من", "from"], ["أو", "or"], ["بـ", "by "]];
  PAIRS.unshift(['التصميم الإضافي', 'Alternate layout'], ['حركة التنزيل على WAN', 'WAN download traffic'],
    ['الحظر محفوظ لكن عنوان الجهاز مش في جدول الفايروول.', 'Block saved. The device address is missing from the firewall table.'],
    ['الحظر موجود في جدول الفايروول.', 'Block present in the firewall table.'],
    ['حالة تطبيق الحظر غير مؤكدة.', 'Block enforcement is unverified.'],
    ['المصدر: سياسة الأجهزة الجديدة', 'Source: new-device policy'], ['المصدر: إجراء يدوي', 'Source: manual action'],
    ['حالة القاعدة', 'Rule status'], ['انتهاء القاعدة', 'Rule expiry'], ['تم النسخ.', 'Copied.'],
    ['العنوان متحدد للنسخ اليدوي.', 'Address selected for manual copying.'], ['نسخ', 'Copy'],
    ['تم تطبيق التغيير.', 'Change applied.'], ['تم حفظ التغيير.', 'Change saved.'],
    ['التغيير اتحفظ لكن تعذّر تطبيقه على الفايروول.', 'Change saved. Firewall application failed.'],
    ['تعذّر حفظ التغيير.', 'Could not save the change.'],
    ['إزالة كل الأوفلاين', 'Remove all offline'], ['إزالة الأوفلاين المحدد', 'Remove selected offline'],
    ['حركة الإنترنت على WAN', 'WAN traffic'], ['التنزيل', 'Download'],
    ['IP على MAC مختلف', 'IP seen with another MAC'],
    ['حد النزول (Mb/s)', 'Download limit (Mb/s)'], ['حد الرفع (Mb/s)', 'Upload limit (Mb/s)'],
    ['حد الرفع', 'Upload limit'], ['حد الرفع لازم يكون بين 0.1 و50 Mb/s', 'Upload limit must be between 0.1 and 50 Mb/s'],
    ['استخدام لحظي مرتفع', 'Heavy usage now'], ['حجم البيانات', 'Data transferred'],
    ['عمر الاتصال', 'Connection age'], ['الإجراءات', 'Actions'], ['الخدمة', 'Service'],
    ['حدود السرعة المؤقتة', 'Temporary speed limits'], ['القواعد المجدولة', 'Scheduled rules'],
    ['مفيش حدود سرعة مؤقتة', 'No temporary speed limits'], ['ينتهي في', 'Expires at'],
    ['اتصالات الإنترنت المفتوحة للجهاز هتتقفل بعد تطبيق الحد.', 'The device’s open internet connections will be closed after the limit is applied.'],
    ['أقسام التحكم في الشبكة', 'Network control sections'], ['عنوان الموقع', 'Website address']);
  var lang = 'ar';
  try { lang = localStorage.getItem('nc_lang') || 'ar'; } catch(e){}

  var root = document.getElementById('nc');
  if (!root) return;

  /* Skip anything whose text is data, not interface: addresses, MACs,
     device names and the log, none of which should be rewritten. */
  function skip(el){
    while (el && el !== root){
      if (el.nodeType === 1){
        if (el.classList && (el.classList.contains('m') ||
                             el.classList.contains('host') ||
                             el.classList.contains('vh') ||
                             el.classList.contains('nm') ||
                             el.classList.contains('logbox') ||
                             el.classList.contains('langsw'))) return true;
        var t = el.tagName;
        if (t === 'SCRIPT' || t === 'STYLE' || t === 'CODE') return true;
      }
      el = el.parentNode;
    }
    return false;
  }

  function tr(txt){
    var out = txt;
    for (var i = 0; i < PAIRS.length; i++){
      var a = PAIRS[i][0];
      if (out.indexOf(a) !== -1) out = out.split(a).join(PAIRS[i][1]);
    }
    return out;
  }

  var AR = /[؀-ۿ]/;

  function sweep(){
    if (lang !== 'en') return;
    var walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, null, false);
    var nodes = [], n;
    while ((n = walker.nextNode())) {
      if (AR.test(n.nodeValue) && !skip(n.parentNode)) nodes.push(n);
    }
    for (var i = 0; i < nodes.length; i++){
      var v = tr(nodes[i].nodeValue);
      if (v !== nodes[i].nodeValue) nodes[i].nodeValue = v;
    }
    /* the attributes a user actually reads */
    var els = root.querySelectorAll('[placeholder],[title],[aria-label]');
    for (var j = 0; j < els.length; j++){
      ['placeholder','title','aria-label'].forEach(function(k){
        var val = els[j].getAttribute(k);
        if (val && AR.test(val)) els[j].setAttribute(k, tr(val));
      });
    }
  }

  function apply(){
    root.setAttribute('data-lang', lang);
    root.setAttribute('dir', lang === 'en' ? 'ltr' : 'rtl');
    sweep();
    var b = root.querySelectorAll('.langsw button');
    for (var i = 0; i < b.length; i++)
      b[i].classList.toggle('on', b[i].dataset.lang === lang);
  }

  /* The page re-renders constantly (every refresh rebuilds the table), so
     watch for new nodes and translate those too. Re-entry is guarded: the
     sweep mutates text, which would otherwise trigger itself forever. */
  var busy = false;
  var mo = new MutationObserver(function(){
    if (busy || lang !== 'en') return;
    busy = true;
    try { sweep(); } finally {
      setTimeout(function(){ busy = false; }, 0);
    }
  });

  function mount(){
    var host = root.querySelector('.nc-bar .sp-r') ||
               root.querySelector('.nc-hd .sp-r') ||
               root.querySelector('.nc-hd') || root;
    var w = document.createElement('div');
    w.className = 'langsw';
    w.innerHTML = '<button data-lang="ar">العربية</button>' +
                  '<button data-lang="en">English</button>';
    w.addEventListener('click', function(e){
      var t = e.target.closest('button'); if (!t) return;
      var want = t.dataset.lang;
      if (want === lang) return;
      try { localStorage.setItem('nc_lang', want); } catch(err){}
      /* Arabic is the source text, so switching back reloads rather than
         trying to translate English into Arabic. */
      if (want === 'ar') { location.reload(); return; }
      lang = want; apply();
    });
    host.appendChild(w);
    apply();
    mo.observe(root, {childList:true, subtree:true, characterData:true});
  }

  if (document.readyState === 'loading')
    document.addEventListener('DOMContentLoaded', mount);
  else mount();
})();

</script>
