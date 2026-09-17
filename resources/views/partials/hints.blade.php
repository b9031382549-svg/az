{{-- Hint popover engine — ported from start-data/design/app-mockup.html.
     Any element carrying data-hint="<key>" (into HINTS below) or an inline
     data-hint-title / data-hint-body / data-hint-meta triple shows a themed
     popover on hover and on keyboard focus (Tab). Listeners are delegated on
     document, so the engine survives Livewire DOM morphs without re-init. --}}
<div id="hintPop" role="tooltip" aria-hidden="true"></div>
<script>
(function () {
  if (window.__hintsBound) return;   // guard against double-include
  window.__hintsBound = true;

  /* Copy mirrors the real vocabulary: classification_items.resolution (Consensus),
     classification_results.status (per mechanism), and the confidence tiers. */
  const HINTS = {
    // ---- item resolutions ----
    pending: { title: 'pending', body: 'Not every enabled mechanism has reported yet. The item is still moving through the pipeline.', meta: 'Default value of items.resolution.' },
    agreed: { title: 'agreed', body: 'Broker and Direct committed to the <b>same 4-digit heading</b>, and the Vector top-5 shortlist carries it. Auto-resolved — no human needed.', meta: 'The only tier eligible for promotion into Memory. A bare 2-of-3 does not reach it.' },
    'agreed:cache': { title: 'agreed · from cache', body: 'Answered straight from the <b>verified answer cache</b> on an exact normalized-name match. The mechanisms never ran — no AI calls, no tokens.', meta: 'Same resolution value as a consensus “agreed”, but a different path: the cache writes it itself. The confidence tier is “verified”, not “unanimous”.' },
    ai_resolved: { title: 'ai_resolved', body: 'The mechanisms diverged, and the <b>web-search resolver</b> settled the item at a 4-digit heading with confidence at or above the threshold.', meta: 'Set by the search resolver when it is confident enough.' },
    conflict: { title: 'conflict', body: 'No unanimity, but at least one mechanism produced a code. A web search was dispatched once and did not settle it — <b>a human decides</b>.', meta: 'Counts towards “Needs attention”.' },
    no_match: { title: 'no_match', body: 'No mechanism produced a code at all. No web search is paid for — the item goes straight to a human.', meta: 'Deliberate cost guard: nothing to corroborate, nothing to search for.' },
    confirmed: { title: 'confirmed', body: 'A reviewer accepted the answer, or corrected it to another heading. <b>Terminal</b> — consensus never overwrites it.', meta: 'Confidence tier becomes “verified”.' },
    rejected: { title: 'rejected', body: 'A reviewer rejected the answer. <b>Terminal</b> — the item keeps no final code.', meta: 'Consensus never overwrites it.' },
    blocked_on_fact: { title: 'blocked_on_fact', body: 'Reserved from an earlier phase. It is still in the vocabulary and in the “Needs attention” filter, but <b>no current code path writes it</b> — the count stays at zero.', meta: 'Legacy value, kept for backward compatibility.' },
    resolving: { title: 'searching…', body: 'A conflict whose <b>web-search resolver has not finished yet</b>. The machine is still working — this is not a final conflict.', meta: 'Display-only status (displayResolution); the stored value is still conflict.' },

    // ---- column headers ----
    'column:memory': { title: 'To Memory', body: 'How many items of this upload were <b>promoted into the answer cache</b> — so the same name never costs an AI call again.', meta: 'Only unanimous answers qualify: broker = direct with the vector corroborating. A web-resolved answer is promoted only when its heading overlaps a candidate the mechanisms had already proposed.' },
    'column:status': { title: 'Status column', body: 'The item-level <b>resolution</b> — the outcome of the whole flow, not one mechanism\'s opinion. A mechanism can be auto-confirmed on its own and the item still land in conflict.', meta: 'Hover any badge for what that value means.' },
    'column:result': { title: 'Result', body: 'The mix of outcomes for this upload: <b>resolved</b> (green), <b>searching</b> (pulsing), <b>needs attention</b> (amber) and <b>conflict</b> (red).', meta: 'Click a row to open the upload.' },

    // ---- decision-flow stage pills ----
    'cache:hit': { title: 'Cache · hit', body: 'An exact normalized-name match in the verified answer cache. The item resolves immediately, with <b>no AI calls at all</b>.', meta: 'A cache row is written only on a hit; its status is always auto_confirmed.' },
    'cache:miss': { title: 'Cache · miss', body: 'The name is not in the answer cache, so the item falls through to the mechanism pipeline.', meta: 'A miss writes no result row.' },
    'mech:agreed': { title: 'AI search · agreed', body: 'Broker and Direct named the same heading and the Vector top-5 corroborates it — the consensus rule is satisfied.', meta: 'Vector votes by membership in its shortlist, not by equality with one code.' },
    'mech:diverged': { title: 'AI search · diverged', body: 'The mechanisms did not converge on one heading. The item is handed to the web-search resolver, and to a human if that fails.', meta: 'A bare majority counts as divergence.' },
    'mech:abstained': { title: 'AI search · nothing found', body: 'Not one of the three mechanisms produced a candidate. Retrieval had nothing to hold onto and both generative mechanisms abstained.', meta: 'Different from divergence: there are no competing answers to reconcile, only an empty result set.' },
    'search:resolved': { title: 'Web search · resolved', body: 'The thinking model identified the item online with confidence above the threshold, so its heading was taken as the answer.', meta: 'Flips the item to ai_resolved.' },
    'search:human': { title: 'Web search · to a human', body: 'The search ran but was not confident enough (or returned no valid heading), so the item stays open for a reviewer.', meta: 'The attempt is still recorded as a search trace row.' },
    'human:auto': { title: 'Human · auto-accepted', body: 'The classifier settled this item on its own. A reviewer can still override the code at any time.', meta: 'This stage is skipped entirely on the review list for agreed / ai_resolved items.' },
    'human:waiting': { title: 'Human · waiting', body: 'Nothing produced a confident answer. The item is waiting for a reviewer to pick the heading.', meta: '' },
    'human:confirmed': { title: 'Human · confirmed', body: 'A reviewer signed off on this heading. The decision is terminal.', meta: '' },
    'human:rejected': { title: 'Human · rejected', body: 'A reviewer rejected the answer. The item keeps no final code.', meta: '' },

    // ---- confidence tiers ----
    'tier:verified': { title: 'tier · verified', body: 'Backed by a cache hit or a human confirmation — the strongest evidence there is.', meta: 'Derived from the evidence type, not from a self-reported number.' },
    'tier:unanimous': { title: 'tier · unanimous', body: 'Every voting mechanism agreed. The only tier promoted into Memory.', meta: 'Corresponds to resolution = agreed.' },
    'tier:resolved': { title: 'tier · web-resolved', body: 'Settled by the web-search resolver — worth a reviewer\'s glance.', meta: 'Corresponds to resolution = ai_resolved.' },
    'tier:weak': { title: 'tier · weak', body: 'Divergent mechanisms with no agreement behind the answer.', meta: '' },

    // ---- code granularity ----
    'code:heading': { title: 'heading only', body: 'The answer is the <b>4-digit HS heading</b>, not a full 10-digit code. Every mechanism is scored at this granularity.', meta: 'Services collapse to the “99” service level instead.' },
    'code:service': { title: 'service level', body: 'This line is a <b>service</b>, so it resolves at the bare “99” service level rather than a 4-digit heading — chapter 99 has no single heading row to descend to.', meta: 'Consensus compares a service against a service, not by code equality.' },
  };

  const HINT_SEL = '[data-hint],[data-hint-title]';
  let hintTarget = null;

  function showHint(el) {
    const h = HINTS[el.dataset.hint] ||
      (el.dataset.hintTitle ? { title: el.dataset.hintTitle, body: el.dataset.hintBody || '', meta: el.dataset.hintMeta || '' } : null);
    if (!h) return;
    hintTarget = el;
    const pop = document.getElementById('hintPop');
    pop.innerHTML = `<div class="hint-title">${h.title}</div><div class="hint-body">${h.body}</div>` +
                    (h.meta ? `<div class="hint-meta">${h.meta}</div>` : '');
    pop.classList.add('show');
    pop.setAttribute('aria-hidden', 'false');

    const r = el.getBoundingClientRect();
    const p = pop.getBoundingClientRect();
    let left = r.left;
    let top = r.bottom + 8;
    if (left + p.width > window.innerWidth - 12) left = window.innerWidth - p.width - 12;
    if (left < 12) left = 12;
    if (top + p.height > window.innerHeight - 12) top = r.top - p.height - 8;
    pop.style.left = left + 'px';
    pop.style.top = Math.max(12, top) + 'px';
  }

  function hideHint() {
    hintTarget = null;
    const pop = document.getElementById('hintPop');
    pop.classList.remove('show');
    pop.setAttribute('aria-hidden', 'true');
  }

  document.addEventListener('mouseover', e => {
    const el = e.target.closest(HINT_SEL);
    if (el && el !== hintTarget) showHint(el);
  });
  document.addEventListener('mouseout', e => {
    const el = e.target.closest(HINT_SEL);
    if (el && el === hintTarget && !el.contains(e.relatedTarget)) hideHint();
  });
  document.addEventListener('focusin', e => {
    const el = e.target.closest(HINT_SEL);
    if (el) showHint(el);
  });
  document.addEventListener('focusout', hideHint);
  window.addEventListener('scroll', hideHint, true);
})();
</script>
