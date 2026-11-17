/**
 * Patch Studio UI — step 4: finishing options per config.optionGroups, fabric, edge colour, use case.
 */
import { optionsFor } from '../shared/config.js';
import { optionEffect } from '../pricing/index.js';
import { describeFabric } from '../colors/index.js';
import { h, keyed, icon, uid } from './dom.js';
import { reconcileOptions } from './state.js';

const USE_CASES = ['uniform', 'club', 'airsoft', 'fashion', 'bag'];

export function stepFinish(ctx) {
  const { t, config, store } = ctx;
  const L = (o) => ctx.L(o);
  const groupsBox = h('div.bps-groups');
  const advBox = h('div.bps-groups');
  const adv = h('details.bps-more', h('summary', t('ui.s4.more')), advBox);
  const fabricBox = h('div');
  const fabricFs = h('fieldset.bps-fieldset', { id: uid('bps-fabric') }, h('legend.bps-legend'), fabricBox);
  const edgeId = uid('bps-edge');
  const edgeSel = h('select.bps-select', { id: edgeId, onchange: (e) => store.set({ edgeColor: e.target.value }) });
  const edgeField = h('div.bps-field', h('label', { for: edgeId }, t('ui.s4.edgeColor')), edgeSel);
  const useId = uid('bps-use');
  const useSel = h('select.bps-select', { id: useId, onchange: (e) => store.set({ useCase: e.target.value }) },
    h('option', { value: '' }, t('ui.s4.useCase.none')), USE_CASES.map((u) => h('option', { value: u }, t('ui.s4.useCase.' + u))));

  const el = h('section.bps-step',
    h('h2.bps-h2', { tabindex: '-1' }, t('ui.s4.title')),
    groupsBox, fabricFs, edgeField,
    h('div.bps-field', h('label', { for: useId }, t('ui.s4.useCase')), useSel),
    adv,
    h('div.bps-step__nav',
      h('button.bps-btn.bps-btn--ghost', { type: 'button', onclick: () => ctx.go(3) }, t('ui.back')),
      h('button.bps-btn.bps-btn--primary', { type: 'button', onclick: () => ctx.go(5) }, t('ui.s4.next'), icon('arrow'))));

  function choose(gid, id, on) {
    const s = store.get();
    const g = config.optionGroups[gid];
    let opts = s.options.slice();
    if (g.type === 'radio') opts = opts.filter((x) => (config.options.find((o) => o.id === x) || {}).group !== gid).concat(id);
    else opts = on ? opts.concat(id) : opts.filter((x) => x !== id);
    store.set({ options: reconcileOptions(config, s.technique, opts) });
  }

  function group(gid, g, s) {
    const opts = optionsFor(config, s.technique).filter((o) => o.group === gid);
    if (!opts.length) return null;
    if (g.showIfAny && !g.showIfAny.some((id) => s.options.includes(id))) return null;
    const name = `${ctx.uidBase}-opt-${gid}`;
    return h('fieldset.bps-fieldset.bps-group', h('legend.bps-legend', L(g.label)),
      h('div.bps-optlist', opts.map((o) => {
        const id = `${name}-${o.id}`;
        const eff = optionEffect(o, s.technique, config, ctx.locale, ctx.overrides);
        const help = L(o.help);
        return h('div.bps-opt',
          h('input', { type: g.type === 'radio' ? 'radio' : 'checkbox', id, name, value: o.id, checked: s.options.includes(o.id), 'data-fk': id, 'aria-describedby': help ? id + '-h' : null,
            onchange: (e) => choose(gid, o.id, e.target.checked) }),
          h('label', { for: id },
            h('span.bps-opt__label', L(o.label)),
            h('span.bps-opt__eff' + (eff.amountIncl < 0 ? '.is-minus' : ''), eff.text)),
          help ? h('p.bps-opt__help', { id: id + '-h' }, help) : null);
      })),
      gid === 'backing' ? h('p.bps-help', icon('info'), t('ui.s4.backingTip')) : null);
  }

  return {
    el,
    update(s, d) {
      const groups = Object.entries(config.optionGroups || {});
      const key = JSON.stringify([s.technique, s.options, ctx.ui.exclVat]);
      keyed(groupsBox, key, () => groups.filter(([, g]) => !g.advanced).map(([gid, g]) => group(gid, g, s)));
      keyed(advBox, key, () => groups.filter(([, g]) => g.advanced).map(([gid, g]) => group(gid, g, s)));
      adv.hidden = !advBox.children.length;

      const leather = (config.techniques[s.technique] || {}).fabricList === 'leathers';
      // Only one possible fabric (sublimation = white polyester, see 'Drager'): nothing to choose.
      fabricFs.hidden = (d.fabrics || []).length <= 1;
      fabricFs.querySelector('legend').textContent = leather ? t('ui.s4.fabricLeather') : t('ui.s4.fabric');
      const autoName = d.fabricPick ? L((d.fabrics.find((f) => f.id === d.fabricPick.id) || {}).label) : '';
      const why = d.fabricPick ? describeFabric(d.fabricPick, d.fabrics, { config, locale: ctx.locale }) : null;
      const fills = !!(ctx.analysis && ctx.analysis.shape && ctx.analysis.shape.fillsPatch && !s.shapePicked && s.art && s.art.scale >= 1);
      keyed(fabricBox, JSON.stringify([s.fabric, s.technique, autoName, d.keepBg, fills]), () => [
        h('div.bps-swatchgrid', [{ id: 'auto', hex: d.fabricPick ? (d.fabrics.find((f) => f.id === d.fabricPick.id) || {}).hex : '#fff', auto: true }, ...d.fabrics].map((f) => {
          const id = `${ctx.uidBase}-fab-${f.id}`;
          return h('div.bps-fab',
            h('input', { type: 'radio', id, name: ctx.uidBase + '-fabric', value: f.id, checked: s.fabric === f.id || (f.auto && s.fabric === 'auto'), 'data-fk': id, onchange: () => store.set({ fabric: f.id }) }),
            h('label', { for: id }, h('span.bps-fab__chip' + (f.auto ? '.is-auto' : ''), { style: { background: f.hex }, 'aria-hidden': 'true' }),
              h('span.bps-fab__name', f.auto ? t('ui.s4.autoFabric', { fabric: autoName.toLowerCase() }) : L(f.label))));
        })),
        d.keepBg ? h('p.bps-help', icon('info'), t('ui.s4.bgKept')) : fills ? h('p.bps-help', icon('info'), t('ui.s4.artFills')) : why && s.fabric === 'auto' ? h('p.bps-help', icon('info'), why.text) : null,
      ]);

      const style = d.layout.edge && d.layout.edge.style;
      edgeField.hidden = !(style === 'satin' || style === 'merrow');
      keyed(edgeSel, JSON.stringify([s.edgeColor, d.autoEdge && d.autoEdge.code]), () => [
        h('option', { value: 'auto', selected: s.edgeColor === 'auto' }, t('ui.s4.edgeAuto', { name: d.autoEdge ? L(d.autoEdge.name).toLowerCase() : '' })),
        (config.threads || []).map((th) => h('option', { value: th.code, selected: th.code === s.edgeColor }, `${L(th.name)} (${th.code})`)),
      ]);
      if (document.activeElement !== useSel) useSel.value = s.useCase || '';
    },
  };
}
