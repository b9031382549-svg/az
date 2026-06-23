{{-- Design theme ported verbatim from start-data/design (Tailwind Play CDN + tokens + custom CSS). --}}
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,400;0,9..144,500;0,9..144,600;0,9..144,700;1,9..144,500&family=Spline+Sans:wght@400;500;600;700&family=Spline+Sans+Mono:wght@400;500;600&display=swap" rel="stylesheet">

<script src="https://cdn.tailwindcss.com"></script>
<script>
tailwind.config = {
  theme: {
    extend: {
      colors: {
        paper:   '#F2EEE3',
        surface: '#FCFBF6',
        ink:     '#17150F',
        inkpanel:'#1C1A14',
        muted:   '#6E6757',
        faint:   '#9A9384',
        line:    '#DAD3C2',
        hair:    '#E6E0D1',
        stamp:   '#BE3A22',
        ledger:  '#1F6B4A',
        amber:   '#A9761A',
      },
      fontFamily: {
        display: ['Fraunces','Georgia','serif'],
        sans:    ['"Spline Sans"','system-ui','sans-serif'],
        mono:    ['"Spline Sans Mono"','ui-monospace','monospace'],
      },
    }
  }
}
</script>

<style>
  :root{ color-scheme:light; }
  html,body{ height:100%; }
  body{
    background:#F2EEE3; color:#17150F;
    font-family:"Spline Sans",system-ui,sans-serif;
    -webkit-font-smoothing:antialiased;
  }
  body::before{
    content:""; position:fixed; inset:0; z-index:9999; pointer-events:none; opacity:.045;
    background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='160' height='160'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.85' numOctaves='2' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)'/%3E%3C/svg%3E");
    mix-blend-mode:multiply;
  }
  .tnum{ font-variant-numeric:tabular-nums; }
  .kicker{ font-size:11px; letter-spacing:.18em; text-transform:uppercase; color:#6E6757; }
  .hair{ border-color:#E6E0D1; }
  ::-webkit-scrollbar{ width:10px; height:10px }
  ::-webkit-scrollbar-thumb{ background:#D5CEBD; border:3px solid #F2EEE3; border-radius:8px }
  ::selection{ background:#BE3A22; color:#FCFBF6; }

  /* ---- modern component layer ---- */
  .card{ background:#FCFBF6; border:1px solid #E6E0D1; border-radius:18px; }
  .card-flat{ background:#FCFBF6; border:1px solid #E6E0D1; border-radius:14px; }

  .btn{ display:inline-flex; align-items:center; justify-content:center; gap:8px;
        border-radius:11px; padding:11px 18px; font-size:14px; font-weight:500;
        line-height:1; transition:.15s; cursor:pointer; white-space:nowrap; }
  .btn-ink{ background:#17150F; color:#F2EEE3; }
  .btn-ink:hover{ background:#000; }
  .btn-stamp{ background:#BE3A22; color:#FCFBF6; }
  .btn-stamp:hover{ filter:brightness(.94); }
  .btn-ghost{ background:#FCFBF6; border:1px solid #DAD3C2; color:#17150F; }
  .btn-ghost:hover{ border-color:#17150F; }
  .btn-sm{ padding:8px 13px; font-size:13px; border-radius:10px; }

  .chip{ display:inline-flex; align-items:center; gap:6px; border:1px solid #DAD3C2;
         background:#FCFBF6; border-radius:10px; transition:.15s; cursor:pointer; }
  .chip:hover{ border-color:#17150F; }

  /* unified form fields — every form aligns to this */
  .field-label{ display:block; font-size:11px; letter-spacing:.16em; text-transform:uppercase;
                color:#6E6757; margin-bottom:7px; font-weight:500; }
  .field-input{ width:100%; height:46px; background:#FCFBF6; border:1px solid #DAD3C2;
                border-radius:11px; padding:0 14px; font-size:14px; color:#17150F;
                outline:none; transition:border-color .15s, box-shadow .15s; }
  .field-input::placeholder{ color:#9A9384; }
  .field-input:focus{ border-color:#17150F; box-shadow:0 0 0 3px rgba(23,21,15,.06); }
  select.field-input{ appearance:none; cursor:pointer;
    background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' fill='none' stroke='%236E6757' stroke-width='1.6'%3E%3Cpath d='M3 5l4 4 4-4'/%3E%3C/svg%3E");
    background-repeat:no-repeat; background-position:right 13px center; padding-right:36px; }
  textarea.field-input{ height:auto; padding:12px 14px; resize:none; }

  /* nav */
  .nav-item{ display:flex; align-items:center; gap:11px; margin:2px 12px; padding:10px 13px;
             border-radius:11px; cursor:pointer; color:#3A352B; font-size:14.5px; transition:.15s; }
  .nav-item:hover{ background:#EBE5D6; }
  .nav-item.active{ background:#17150F; color:#F4F0E6; }
  .nav-item svg{ width:18px; height:18px; flex:none; }

  /* animations */
  @keyframes fadeup{ from{opacity:0; transform:translateY(10px)} to{opacity:1; transform:none} }
  .fadeup{ animation:fadeup .5s cubic-bezier(.2,.7,.2,1) both; }
  @keyframes slidein{ from{transform:translateX(100%)} to{transform:none} }
  .slidein{ animation:slidein .32s cubic-bezier(.2,.8,.2,1) both; }
  @keyframes stampin{ 0%{opacity:0; transform:scale(1.7) rotate(-26deg)} 55%{opacity:1} 100%{opacity:1; transform:scale(1) rotate(-9deg)} }
  .stampin{ animation:stampin .55s cubic-bezier(.2,.7,.2,1) both; }
  @keyframes blink{ 50%{opacity:.25} }
  .blink{ animation:blink 1s steps(2) infinite; }

  .stamp-seal{ border:2.5px solid #BE3A22; color:#BE3A22; border-radius:8px;
    font-family:"Spline Sans Mono",monospace; letter-spacing:.18em; text-transform:uppercase;
    transform:rotate(-9deg); opacity:.92; }

  .hide-approval [data-col=approval]{display:none}
  .hide-excise   [data-col=excise]{display:none}
  .hide-nonvat   [data-col=nonvat]{display:none}
  .hide-exempt   [data-col=exempt]{display:none}
  .hide-zero     [data-col=zero]{display:none}
  .hide-road     [data-col=road]{display:none}

  .dropzone.drag{ background:#FCFBF6; border-color:#BE3A22; }
  .link-under{ text-decoration:underline; text-underline-offset:3px; text-decoration-thickness:1px; }
  .row-hover:hover{ background:#FAF8F1; }
</style>
