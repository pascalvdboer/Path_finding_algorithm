/**
 * Order screen behaviour of the production cards: proof dialog, manual proof status, copy thread codes.
 * Talks to admin-ajax.php (actions bps_send_proof / bps_set_proof_status, see class-order-production.php).
 */

const FALLBACK_I18N = {
  sending: 'Bezig met versturen…',
  saving: 'Bezig met opslaan…',
  sent: 'De digitale proef is verstuurd.',
  saved: 'Status bijgewerkt.',
  error: 'Er ging iets mis. Probeer het opnieuw of herlaad de pagina.',
  chooseImage: 'Kies een proefafbeelding',
  useImage: 'Gebruik deze afbeelding',
  copied: 'Gekopieerd',
  copyFailed: 'Kopiëren lukte niet',
  invalidEmail: 'Vul een geldig e-mailadres in.',
  roundN: 'Dit wordt proefronde %d.',
  langFr: 'De e-mail wordt in het Frans verstuurd (taal van het ontwerp).',
  langNl: 'De e-mail wordt in het Nederlands verstuurd (taal van het ontwerp).',
};

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

/**
 * Read the JSON data block of a production root.
 * @param {Element} root
 */
export function readData(root) {
  const el = root.querySelector('script.bps-admin-data');
  if (!el) return null;
  try {
    return JSON.parse(el.textContent || '{}');
  } catch (e) {
    return null;
  }
}

/**
 * POST to admin-ajax and normalise the WordPress JSON response.
 * @param {string} url
 * @param {Object<string,string|number>} fields
 * @returns {Promise<{ok:boolean, data:any}>}
 */
export async function post(url, fields) {
  const body = new FormData();
  Object.keys(fields).forEach((k) => body.append(k, String(fields[k] == null ? '' : fields[k])));
  let res;
  try {
    res = await fetch(url, { method: 'POST', body, credentials: 'same-origin', headers: { Accept: 'application/json' } });
  } catch (e) {
    return { ok: false, data: null };
  }
  let json = null;
  try {
    json = await res.json();
  } catch (e) {
    json = null;
  }
  return { ok: !!(res.ok && json && json.success), data: json ? json.data : null };
}

/**
 * Initialise one production metabox.
 * @param {HTMLElement} root element with [data-bps-production]
 */
export function initProduction(root) {
  if (!root || root.__bpsInit) return;
  root.__bpsInit = true;
  const data = readData(root);
  if (!data) return;
  const t = Object.assign({}, FALLBACK_I18N, data.i18n || {});
  const live = root.querySelector('[data-bps-live]');
  const announce = (msg) => {
    if (!live) return;
    live.textContent = '';
    window.setTimeout(() => {
      live.textContent = msg;
    }, 50);
  };

  /* ---------- Proof dialog ---------- */
  const dialog = root.querySelector('[data-bps-proof-dialog]');
  let current = null; // { itemId, imageId, opener }
  let mediaFrame = null;

  if (dialog) {
    // Move out of the order <form> so it never submits the order and stays above WP admin chrome.
    document.body.appendChild(dialog);
  }
  const q = (sel) => (dialog ? dialog.querySelector(sel) : null);
  const img = q('[data-bps-proof-img]');
  const to = q('[data-bps-proof-to]');
  const msg = q('[data-bps-proof-msg]');
  const hint = q('[data-bps-proof-hint]');
  const err = q('[data-bps-proof-error]');
  const send = q('[data-bps-proof-send]');
  const pick = q('[data-bps-proof-pick]');
  const reset = q('[data-bps-proof-reset]');
  const refEl = q('[data-bps-proof-ref]');
  const canMedia = () => !!(window.wp && window.wp.media);

  const showError = (text) => {
    if (!err) return;
    err.textContent = text || '';
    err.hidden = !text;
  };

  const setImage = (id, url) => {
    if (!current) return;
    current.imageId = id;
    if (img) img.src = url || '';
    if (reset) reset.hidden = !id;
  };

  const close = () => {
    if (!dialog) return;
    if (typeof dialog.close === 'function' && dialog.open) dialog.close();
    else dialog.removeAttribute('open');
  };

  const open = (itemId, opener) => {
    const item = data.items && data.items[String(itemId)];
    if (!dialog || !item) return;
    current = { itemId: String(itemId), imageId: 0, opener };
    if (refEl) refEl.textContent = item.ref ? '· ' + item.ref : '';
    setImage(0, item.preview || '');
    if (to) to.value = data.recipient || '';
    if (msg) msg.value = item.defaultMsg || '';
    if (hint) {
      const round = (Number(item.round) || 0) + 1;
      hint.textContent = [item.lang === 'fr' ? t.langFr : t.langNl, round > 1 ? t.roundN.replace('%d', String(round)) : ''].filter(Boolean).join(' ');
    }
    if (pick) pick.hidden = !canMedia();
    showError('');
    if (send) {
      send.disabled = false;
      send.removeAttribute('aria-busy');
    }
    if (typeof dialog.showModal === 'function') dialog.showModal();
    else dialog.setAttribute('open', '');
    if (msg) msg.focus();
  };

  const replaceProofBlock = (itemId, html, status, label) => {
    const card = root.querySelector('[data-bps-item="' + itemId + '"]');
    const block = card && card.querySelector('[data-bps-proof-status]');
    if (block && typeof html === 'string') block.innerHTML = html;
    if (data.items && data.items[itemId] && status === 'sent') data.items[itemId].round = (Number(data.items[itemId].round) || 0) + 1;
    // Pill in the line items table (outside the metabox).
    const link = document.querySelector('.bps-itemlink a[href="#bps-fiche-' + itemId + '"]');
    const pill = link && link.parentNode ? link.parentNode.querySelector('.bps-proof-pill') : null;
    if (pill && status) {
      pill.className = 'bps-proof-pill bps-proof-pill--' + status;
      if (label) pill.textContent = label;
    }
    return block;
  };

  if (dialog) {
    dialog.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' && e.target && e.target.tagName === 'INPUT') e.preventDefault();
    });
    dialog.addEventListener('close', () => {
      if (current && current.opener && document.contains(current.opener)) current.opener.focus();
    });
    const cancel = q('[data-bps-proof-cancel]');
    if (cancel) cancel.addEventListener('click', close);
    // Click on the backdrop closes the dialog.
    dialog.addEventListener('click', (e) => {
      if (e.target === dialog) close();
    });

    if (pick) {
      pick.addEventListener('click', () => {
        if (!canMedia() || !current) return;
        if (!mediaFrame) {
          mediaFrame = window.wp.media({ title: t.chooseImage, button: { text: t.useImage }, library: { type: 'image' }, multiple: false });
          mediaFrame.on('select', () => {
            const att = mediaFrame.state().get('selection').first();
            const a = att && att.toJSON ? att.toJSON() : null;
            if (a && a.id) setImage(a.id, (a.sizes && a.sizes.large && a.sizes.large.url) || a.url);
          });
        }
        mediaFrame.open();
      });
    }
    if (reset) {
      reset.addEventListener('click', () => {
        if (!current) return;
        const item = data.items[current.itemId];
        setImage(0, item ? item.preview : '');
      });
    }

    if (send) {
      send.addEventListener('click', async () => {
        if (!current) return;
        const email = (to && to.value.trim()) || '';
        if (!EMAIL_RE.test(email)) {
          showError(t.invalidEmail);
          if (to) to.focus();
          return;
        }
        showError('');
        send.disabled = true;
        send.setAttribute('aria-busy', 'true');
        const label = send.textContent;
        send.textContent = t.sending;
        const itemId = current.itemId;
        const r = await post(data.ajaxUrl, {
          action: 'bps_send_proof',
          nonce: data.nonce,
          order_id: data.orderId,
          item_id: itemId,
          recipient: email,
          message: msg ? msg.value : '',
          image_id: current.imageId || 0,
        });
        send.textContent = label;
        send.disabled = false;
        send.removeAttribute('aria-busy');
        if (!r.ok) {
          showError((r.data && r.data.message) || t.error);
          return;
        }
        current.opener = null;
        close();
        const block = replaceProofBlock(itemId, r.data.html, r.data.status, r.data.label);
        announce(r.data.message || t.sent);
        const btn = block && block.querySelector('[data-bps-proof-open]');
        if (btn) btn.focus();
      });
    }
  }

  /* ---------- Delegated clicks in the metabox ---------- */
  root.addEventListener('click', async (e) => {
    const target = e.target && e.target.closest ? e.target : null;
    if (!target) return;

    const opener = target.closest('[data-bps-proof-open]');
    if (opener) {
      e.preventDefault();
      open(opener.getAttribute('data-bps-proof-open'), opener);
      return;
    }

    const copy = target.closest('[data-bps-copy]');
    if (copy) {
      e.preventDefault();
      const text = copy.getAttribute('data-bps-copy') || '';
      let ok = false;
      try {
        if (navigator.clipboard && window.isSecureContext) {
          await navigator.clipboard.writeText(text);
          ok = true;
        } else {
          const ta = document.createElement('textarea');
          ta.value = text;
          ta.setAttribute('readonly', '');
          ta.style.position = 'fixed';
          ta.style.opacity = '0';
          document.body.appendChild(ta);
          ta.select();
          ok = document.execCommand('copy');
          ta.remove();
        }
      } catch (er) {
        ok = false;
      }
      announce(ok ? t.copied : t.copyFailed);
      copy.classList.toggle('is-copied', ok);
      window.setTimeout(() => copy.classList.remove('is-copied'), 1600);
      return;
    }

    const save = target.closest('[data-bps-status-save]');
    if (save) {
      e.preventDefault();
      const itemId = save.getAttribute('data-bps-status-save');
      const box = save.closest('.bps-proof__manual');
      const sel = box && box.querySelector('[data-bps-status-select]');
      const note = box && box.querySelector('[data-bps-status-note]');
      save.disabled = true;
      const label = save.textContent;
      save.textContent = t.saving;
      const r = await post(data.ajaxUrl, {
        action: 'bps_set_proof_status',
        nonce: data.nonce,
        order_id: data.orderId,
        item_id: itemId,
        status: sel ? sel.value : '',
        note: note ? note.value : '',
      });
      save.disabled = false;
      save.textContent = label;
      let errEl = box && box.querySelector('.bps-proof__err');
      if (!r.ok) {
        if (box && !errEl) {
          errEl = document.createElement('p');
          errEl.className = 'bps-proof__err';
          errEl.setAttribute('role', 'alert');
          box.appendChild(errEl);
        }
        if (errEl) errEl.textContent = (r.data && r.data.message) || t.error;
        return;
      }
      const block = replaceProofBlock(itemId, r.data.html, r.data.status, r.data.label);
      announce(r.data.message || t.saved);
      const btn = block && block.querySelector('[data-bps-proof-open]');
      if (btn) btn.focus();
    }
  });
}
