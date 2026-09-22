    (function () {
        'use strict';
        const $ = (s, r = document) => r.querySelector(s), $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
        const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
        const EMOJI = { rocket: '🚀', memo: '📝', tada: '🎉', sparkles: '✨', ship: '🚢', anchor: '⚓', compass: '🧭', bug: '🐛', art: '🎨', zap: '⚡', white_check_mark: '✅', thumbsup: '👍', '+1': '👍', fire: '🔥', eyes: '👀', wave: '👋', coffee: '☕', star: '⭐', heart: '❤️', warning: '⚠️' };
        const ic = (d, cls = 'i sm') => `<svg class="${cls}" viewBox="0 0 24 24"><path d="${d}"/></svg>`;
        const I = { text: 'M4 6.5h16M4 12h16M4 17.5h9', check: 'M12 21a9 9 0 100-18 9 9 0 000 18zM8.5 12.3l2.3 2.3 4.7-4.8', clock: 'M12 21a9 9 0 100-18 9 9 0 000 18zM12 7.5V12l3 2', plus: 'M12 5v14M5 12h14', close: 'M6 6l12 12M18 6L6 18', chat: 'M20 12.5c0 3.6-3.6 6.5-8 6.5-1.3 0-2.6-.3-3.7-.7L4 20l1.1-3.3C4.4 15.5 4 14 4 12.5 4 8.9 7.6 6 12 6s8 2.9 8 6.5z', dup: 'M8 8V5.5A1.5 1.5 0 019.5 4h9A1.5 1.5 0 0120 5.5v9a1.5 1.5 0 01-1.5 1.5H16M4 9.5A1.5 1.5 0 015.5 8h9A1.5 1.5 0 0116 9.5v9a1.5 1.5 0 01-1.5 1.5h-9A1.5 1.5 0 014 18.5v-9z', archive: 'M4 5.5h16v4H4zM5.5 9.5v9A1.5 1.5 0 007 20h10a1.5 1.5 0 001.5-1.5v-9M10 13.5h4', trash: 'M4 7h16M9.5 7V4.5h5V7M6.5 7l.8 12.2A1.5 1.5 0 008.8 20.5h6.4a1.5 1.5 0 001.5-1.3L17.5 7M10 11v6M14 11v6', pencil: 'M4 20h4l10.5-10.5a2.1 2.1 0 00-3-3L5 17v3zM13.5 6.5l3 3', move: 'M4 12h16M14 6l6 6-6 6', sun: 'M12 16.5a4.5 4.5 0 100-9 4.5 4.5 0 000 9zM12 3v1.8M12 19.2V21M3 12h1.8M19.2 12H21M5.6 5.6l1.3 1.3M17.1 17.1l1.3 1.3M5.6 18.4l1.3-1.3M17.1 6.9l1.3-1.3', moon: 'M20 14.2A8 8 0 019.8 4a8 8 0 1010.2 10.2z' };
        const toast = (m) => { const t = $('#toast'); t.textContent = m; t.style.display = 'block'; clearTimeout(t._t); t._t = setTimeout(() => t.style.display = 'none', 2200); };

        /* ---- Theme (same contract as the app) ---- */
        const isDark = () => { const t = document.documentElement.getAttribute('data-theme'); return t ? t === 'dark' : matchMedia('(prefers-color-scheme: dark)').matches; };
        const setTheme = (m) => { if (m === 'system') { document.documentElement.removeAttribute('data-theme'); try { localStorage.removeItem('beckon_site_theme'); } catch (e) {} } else { document.documentElement.setAttribute('data-theme', m); try { localStorage.setItem('beckon_site_theme', m); } catch (e) {} } paintToggle(); };
        const paintToggle = () => { $('#theme-toggle').innerHTML = ic(isDark() ? I.sun : I.moon, 'i'); };
        paintToggle();
        $('#theme-toggle').addEventListener('click', () => setTheme(isDark() ? 'light' : 'dark'));
        $('#theme-toggle').addEventListener('contextmenu', (e) => { e.preventDefault(); setTheme('system'); toast('Following the system theme'); });
        matchMedia('(prefers-color-scheme: dark)').addEventListener('change', paintToggle);

        /* ---- Tiny Markdown (a subset of the engine in index.php) ---- */
        function inline(s) {
            const slots = []; const keep = (h) => { slots.push(h); return `\u0000${slots.length - 1}\u0000`; };
            s = s.replace(/`([^`]+)`/g, (m, c) => keep(`<code>${esc(c)}</code>`));
            s = esc(s);
            s = s.replace(/\[([^\]]+)\]\(([^)\s]+)\)/g, (m, t, u) => keep(`<a href="${u}" target="_blank" rel="noopener">${t}</a>`));
            s = s.replace(/\*\*(?=\S)(.+?)(?<=\S)\*\*/g, '<strong>$1</strong>').replace(/(?<!\*)\*(?=\S)([^*]+?)(?<=\S)\*(?!\*)/g, '<em>$1</em>').replace(/~~(.+?)~~/g, '<del>$1</del>').replace(/:([a-z0-9_+-]+):/g, (m, k) => EMOJI[k] ? `<span class="emoji">${EMOJI[k]}</span>` : m);
            return s.replace(/\u0000(\d+)\u0000/g, (m, i) => slots[i]);
        }
        function render(text) {
            const lines = String(text || '').split('\n'); const out = []; let i = 0;
            while (i < lines.length) {
                const l = lines[i]; let m;
                if (!l.trim()) { i++; continue; }
                if (/^\s*```/.test(l)) { const buf = []; i++; while (i < lines.length && !/^\s*```/.test(lines[i])) buf.push(lines[i++]); i++; out.push(`<pre><code>${esc(buf.join('\n'))}</code></pre>`); continue; }
                if ((m = /^\s{0,3}(#{1,6})\s+(.*)$/.exec(l))) { out.push(`<h${m[1].length}>${inline(m[2])}</h${m[1].length}>`); i++; continue; }
                if (/^\s{0,3}([-*_])(\s*\1){2,}\s*$/.test(l)) { out.push('<hr>'); i++; continue; }
                if (/^\s{0,3}>/.test(l)) { const buf = []; while (i < lines.length && /^\s{0,3}>/.test(lines[i])) buf.push(lines[i++].replace(/^\s{0,3}>\s?/, '')); out.push(`<blockquote>${render(buf.join('\n'))}</blockquote>`); continue; }
                if ((m = /^\s*([-*+]|\d+\.)\s+/.exec(l))) {
                    const ordered = /\d/.test(m[1]); const items = [];
                    while (i < lines.length && /^\s*([-*+]|\d+\.)\s+/.test(lines[i]) && (/\d/.test(lines[i].match(/^\s*([-*+]|\d+\.)/)[1]) === ordered)) { items.push(lines[i].replace(/^\s*([-*+]|\d+\.)\s+/, '')); i++; }
                    out.push(`<${ordered ? 'ol' : 'ul'}>${items.map((t) => { const tk = /^\[([ xX])\]\s+/.exec(t); if (tk) { const done = tk[1] !== ' '; return `<li class="task${done ? ' done' : ''}"><input type="checkbox"${done ? ' checked' : ''}><span>${inline(t.slice(tk[0].length))}</span></li>`; } return `<li>${inline(t)}</li>`; }).join('')}</${ordered ? 'ol' : 'ul'}>`); continue;
                }
                const buf = []; while (i < lines.length && lines[i].trim() && !/^\s{0,3}(#{1,6}\s|>|```|[-*+]\s|\d+\.\s)/.test(lines[i])) buf.push(lines[i++].trim());
                out.push(`<p>${buf.map(inline).join('<br>')}</p>`);
            }
            return out.join('\n');
        }

        /* Front-page mockups (board, card editor, search demo). Other pages have none of these elements. */
        if ($('#mb')) {
        /* ---- Demo data ---- */
        const COLORS = ['red', 'orange', 'yellow', 'green', 'teal', 'blue', 'purple', 'pink', 'slate'];
        const today = new Date(); const d = (n) => { const x = new Date(today); x.setDate(x.getDate() + n); return x; };
        const fmt = (x) => ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'][x.getMonth()] + ' ' + x.getDate();
        const B = { lists: [
            { title: 'Charted', cards: [
                { id: 'c1', title: 'Plot the autumn release', labels: [['blue', 'Feature'], ['orange', 'Priority']], due: d(11), md: '# Release plan\n\nShip **v2.0** with the new interface.\n\n- [x] Design tokens for light and dark\n- [x] Custom SVG icon set\n- [ ] Write the changelog :memo:\n- [ ] Tag the release :rocket:\n\n> Where Markdown charts the course.' },
                { id: 'c2', title: 'Rewrite the onboarding email', labels: [['teal', 'Writing']], md: 'Keep it to three sentences.\n\n- [ ] Draft\n- [ ] Read it out loud\n- [ ] Send' },
            ] },
            { title: 'Underway', cards: [
                { id: 'c3', title: 'Import the old Trello board', labels: [['purple', 'Migration']], due: d(2), comments: 3, md: 'Attachments and checklists came across cleanly.\n\n```\nphp beckon-cli.php import voyage cards.json\n```\n\nNext: check the cover images.' },
            ] },
            { title: 'Docked', cards: [
                { id: 'c4', title: 'Fix list drag and drop', labels: [['green', 'Bug']], md: 'Done and dusted. :tada:' },
                { id: 'c5', title: 'Card covers from uploads', labels: [['pink', 'Design']], md: '' },
            ] },
        ] };
        const stats = (md) => { const t = (md.match(/- \[[ xX]\]/g) || []).length, done = (md.match(/- \[[xX]\]/g) || []).length; return { t, done }; };
        const dueCls = (x) => { if (!x) return ''; const n = Math.round((x - today) / 864e5); return n < 0 ? 'overdue' : n <= 2 ? 'soon' : ''; };
        const find = (id) => { for (let l = 0; l < B.lists.length; l++) { const c = B.lists[l].cards.findIndex((x) => x.id === id); if (c > -1) return { l, c, card: B.lists[l].cards[c] }; } return null; };

        /* ---- Board render ---- */
        const mb = $('#mb'); let composer = null;
        function cardHtml(c) {
            const s = stats(c.md); const meta = [];
            if (c.md.trim()) meta.push(`<span>${ic(I.text, 'i xs')}</span>`);
            if (c.comments) meta.push(`<span>${ic(I.chat, 'i xs')}${c.comments}</span>`);
            if (s.t) meta.push(`<span class="${s.done === s.t ? 'done' : ''}">${ic(I.check, 'i xs')}${s.done}/${s.t}<i class="mini"><i style="width:${Math.round(s.done / s.t * 100)}%"></i></i></span>`);
            if (c.due) meta.push(`<span class="chip-date ${dueCls(c.due)}">${ic(I.clock, 'i xs')}${fmt(c.due)}</span>`);
            return `<div class="mc" data-id="${c.id}">${c.labels.length ? `<div class="labels">${c.labels.map(([col, n]) => `<span class="lbl bg-${col}">${esc(n)}</span>`).join('')}</div>` : ''}<div class="t">${esc(c.title)}</div>${meta.length ? `<div class="meta">${meta.join('')}</div>` : ''}</div>`;
        }
        function renderBoard() {
            mb.innerHTML = B.lists.map((list, l) => `<div class="mb-col" data-l="${l}"><div class="mb-head">${esc(list.title)}<span class="n">${list.cards.length}</span></div><div class="mb-body" data-l="${l}">${list.cards.map(cardHtml).join('')}</div>${composer === l ? `<div class="mb-composer"><textarea rows="2" placeholder="Card title…" data-composer></textarea><div class="row"><button class="sbtn" data-add>Add card</button><button class="xbtn" data-cancel>${ic(I.close, 'i sm')}</button><span style="margin-left:auto">Enter to add</span></div></div>` : `<button class="mb-add" data-open-composer="${l}">${ic(I.plus, 'i sm')} Add card</button>`}</div>`).join('');
            const ta = $('[data-composer]', mb); if (ta) ta.focus();
        }
        renderBoard();
        mb.addEventListener('click', (e) => {
            const oc = e.target.closest('[data-open-composer]'); if (oc) { composer = +oc.dataset.openComposer; renderBoard(); return; }
            if (e.target.closest('[data-cancel]')) { composer = null; renderBoard(); return; }
            if (e.target.closest('[data-add]')) { commit(); return; }
            const card = e.target.closest('.mc'); if (card && !drag.moved) openCard(card.dataset.id);
        });
        mb.addEventListener('keydown', (e) => { if (e.target.matches('[data-composer]')) { if (e.key === 'Enter') { e.preventDefault(); commit(); } if (e.key === 'Escape') { composer = null; renderBoard(); } } });
        function commit() { const ta = $('[data-composer]', mb); const t = ta && ta.value.trim(); if (!t) { composer = null; renderBoard(); return; } B.lists[composer].cards.push({ id: 'c' + Date.now(), title: t, labels: [], md: '' }); renderBoard(); }

        /* ---- Pointer drag and drop (mouse + touch) ---- */
        const drag = { el: null, ghost: null, ph: null, id: null, moved: false, sx: 0, sy: 0 };
        mb.addEventListener('pointerdown', (e) => { const card = e.target.closest('.mc'); if (!card || e.button > 0) return; drag.el = card; drag.id = card.dataset.id; drag.moved = false; drag.sx = e.clientX; drag.sy = e.clientY; });
        window.addEventListener('pointermove', (e) => {
            if (!drag.el) return;
            if (!drag.moved) { if (Math.hypot(e.clientX - drag.sx, e.clientY - drag.sy) < 6) return; drag.moved = true; const r = drag.el.getBoundingClientRect(); drag.ghost = drag.el.cloneNode(true); drag.ghost.classList.add('ghost'); drag.ghost.style.width = r.width + 'px'; drag.ox = e.clientX - r.left; drag.oy = e.clientY - r.top; document.body.appendChild(drag.ghost); drag.ph = drag.el; drag.el.classList.add('placeholder'); }
            drag.ghost.style.left = (e.clientX - drag.ox) + 'px'; drag.ghost.style.top = (e.clientY - drag.oy) + 'px';
            const under = document.elementFromPoint(e.clientX, e.clientY); const col = under && under.closest('.mb-col'); $$('.mb-col.over', mb).forEach((c) => c.classList.remove('over'));
            if (!col) return; col.classList.add('over'); const body = $('.mb-body', col); const over = under.closest('.mc');
            if (over && over !== drag.ph) { const r = over.getBoundingClientRect(); body.insertBefore(drag.ph, e.clientY < r.top + r.height / 2 ? over : over.nextSibling); }
            else if (!over && !body.contains(drag.ph)) body.appendChild(drag.ph);
            else if (!over && body.contains(drag.ph) && body.lastElementChild !== drag.ph && e.clientY > body.getBoundingClientRect().bottom - 10) body.appendChild(drag.ph);
        });
        const endDrag = () => {
            if (!drag.el) return;
            if (drag.moved) {
                const from = find(drag.id); const body = drag.ph.parentElement; const toL = +body.dataset.l; const toC = Array.from(body.children).indexOf(drag.ph);
                const card = B.lists[from.l].cards.splice(from.c, 1)[0]; B.lists[toL].cards.splice(toC, 0, card);
                drag.ghost.remove(); $$('.mb-col.over', mb).forEach((c) => c.classList.remove('over')); renderBoard();
                if (from.l !== toL) toast(`Moved to ${B.lists[toL].title}`);
            }
            const was = drag.moved; drag.el = null; drag.ghost = null; drag.ph = null; setTimeout(() => { drag.moved = false; }, 0); drag.moved = was;
        };
        window.addEventListener('pointerup', endDrag); window.addEventListener('pointercancel', endDrag);

        /* ---- Context menu (right-click or long-press a card) ---- */
        let ctxEl = null, pressTimer = null;
        const closeCtx = () => { if (ctxEl) { ctxEl.remove(); ctxEl = null; } };
        function showCtx(x, y, id) {
            closeCtx(); const f = find(id); if (!f) return;
            const el = document.createElement('div'); el.className = 'ctx';
            const row = (a, label, d, cls = '') => `<button data-a="${a}" class="${cls}">${ic(d)} ${label}</button>`;
            el.innerHTML = row('open', 'Open card', I.pencil) + row('move', 'Move to next list', I.move) + row('dup', 'Duplicate', I.dup) + row('archive', 'Archive', I.archive) + '<div class="sep"></div>' + row('delete', 'Delete', I.trash, 'danger');
            document.body.appendChild(el); ctxEl = el;
            const r = el.getBoundingClientRect(); el.style.left = Math.max(8, Math.min(x, innerWidth - r.width - 8)) + 'px'; el.style.top = Math.max(8, Math.min(y, innerHeight - r.height - 8)) + 'px';
            el.addEventListener('click', (e) => {
                const b = e.target.closest('[data-a]'); if (!b) return; closeCtx();
                const cur = find(id); if (!cur) return; const { l, c, card } = cur;
                if (b.dataset.a === 'open') openCard(id);
                else if (b.dataset.a === 'move') { B.lists[l].cards.splice(c, 1); const to = (l + 1) % B.lists.length; B.lists[to].cards.unshift(card); renderBoard(); toast(`Moved to ${B.lists[to].title}`); }
                else if (b.dataset.a === 'dup') { const copy = JSON.parse(JSON.stringify(card)); copy.id = 'c' + Date.now(); copy.title += ' (copy)'; copy.due = card.due; B.lists[l].cards.splice(c + 1, 0, copy); renderBoard(); toast('Card duplicated'); }
                else if (b.dataset.a === 'archive') { B.lists[l].cards.splice(c, 1); renderBoard(); toast(`Archived "${card.title}"`); }
                else if (b.dataset.a === 'delete') { B.lists[l].cards.splice(c, 1); renderBoard(); toast('Card deleted'); }
            });
        }
        mb.addEventListener('contextmenu', (e) => { const card = e.target.closest('.mc'); if (!card) return; e.preventDefault(); drag.el = null; showCtx(e.clientX, e.clientY, card.dataset.id); });
        mb.addEventListener('pointerdown', (e) => { const card = e.target.closest('.mc'); if (!card || e.pointerType === 'mouse') return; clearTimeout(pressTimer); pressTimer = setTimeout(() => { drag.el = null; showCtx(e.clientX, e.clientY, card.dataset.id); }, 550); });
        window.addEventListener('pointermove', (e) => { if (pressTimer && drag.el && Math.hypot(e.clientX - drag.sx, e.clientY - drag.sy) > 6) { clearTimeout(pressTimer); pressTimer = null; } });
        window.addEventListener('pointerup', () => { clearTimeout(pressTimer); pressTimer = null; });
        document.addEventListener('pointerdown', (e) => { if (ctxEl && !e.target.closest('.ctx')) closeCtx(); });
        document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeCtx(); });
        window.addEventListener('wheel', closeCtx, { passive: true }); window.addEventListener('touchmove', closeCtx, { passive: true });

        /* ---- Card window ---- */
        const mw = $('#mw'), ta = $('#mw-ta'), mdEl = $('#mw-md'); let active = null, view = 'split';
        function openCard(id) {
            const f = find(id); if (!f) return; active = f.card;
            $('#mw-title').value = active.title; $('#mw-sub').textContent = `in ${B.lists[f.l].title} · created today`; ta.value = active.md; mw.classList.add('open'); paint(); renderSide(); applyView();
            if (matchMedia('(min-width: 900px)').matches) setTimeout(() => ta.focus({ preventScroll: true }), 30);
        }
        function paint() { mdEl.innerHTML = render(active.md) || '<p style="color:var(--muted)">Nothing here yet. Start writing on the left.</p>'; const w = active.md.trim() ? active.md.trim().split(/\s+/).length : 0; $('#mw-stat').textContent = w ? `${w} words · ${Math.max(1, Math.ceil(w / 200))} min read` : ''; const s = stats(active.md); $('#mw-prog').innerHTML = s.t ? `${s.done}/${s.t} tasks <span class="bar"><i style="width:${Math.round(s.done / s.t * 100)}%"></i></span>` : ''; }
        function renderSide() {
            $('#mw-side').innerHTML = `<div class="sec"><h4>Labels</h4>${COLORS.map((c) => { const has = active.labels.find((l) => l[0] === c); return `<button class="lt ${has ? 'on bg-' + c : ''}" data-color="${c}"><i class="bg-${c}"></i>${esc(has ? has[1] : c[0].toUpperCase() + c.slice(1))}</button>`; }).join('')}</div><div class="sec"><h4>Due</h4><input type="date" class="field" value="${active.due ? active.due.toISOString().slice(0, 10) : ''}" data-due></div><div class="sec"><h4>Checklists</h4><div style="color:var(--muted)">Task lists in the description count here too.</div></div>`;
        }
        ta.addEventListener('input', () => { active.md = ta.value; paint(); renderBoard(); });
        mdEl.addEventListener('click', (e) => { if (!e.target.matches('input[type=checkbox]')) return; const all = $$('input[type=checkbox]', mdEl); const idx = all.indexOf(e.target); let n = 0; active.md = active.md.replace(/^(\s*[-*+]\s+\[)([ xX])(\])/gm, (m, p, s, sf) => n++ === idx ? p + (s === ' ' ? 'x' : ' ') + sf : m); ta.value = active.md; paint(); renderBoard(); });
        $('#mw-title').addEventListener('input', (e) => { active.title = e.target.value; renderBoard(); });
        $('#mw-side').addEventListener('click', (e) => { const b = e.target.closest('[data-color]'); if (!b) return; const c = b.dataset.color; const i = active.labels.findIndex((l) => l[0] === c); if (i > -1) active.labels.splice(i, 1); else active.labels.push([c, c[0].toUpperCase() + c.slice(1)]); renderSide(); renderBoard(); });
        $('#mw-side').addEventListener('change', (e) => { if (e.target.matches('[data-due]')) { active.due = e.target.value ? new Date(e.target.value + 'T12:00:00') : null; renderBoard(); } });
        $('#mw-close').addEventListener('click', () => mw.classList.remove('open'));
        mw.addEventListener('click', (e) => { if (e.target === mw) mw.classList.remove('open'); });
        $('#mw-seg').addEventListener('click', (e) => { const b = e.target.closest('[data-v]'); if (!b) return; view = b.dataset.v; applyView(); });
        function applyView() { $$('#mw-seg button').forEach((b) => b.classList.toggle('on', b.dataset.v === view)); $('#mw-editor').style.display = view === 'preview' ? 'none' : ''; $('#mw-preview').style.display = view === 'edit' ? 'none' : ''; }
        document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && mw.classList.contains('open')) mw.classList.remove('open'); });

        /* ---- Search demo ---- */
        const CORPUS = [
            { t: 'Plot the autumn release', b: 'Voyage', s: '# Release plan Ship v2.0 with the new interface. Design tokens for light and dark, custom SVG icon set, write the changelog, tag the release.', l: ['blue', 'orange'] },
            { t: 'Import the old Trello board', b: 'Voyage', s: 'Attachments and checklists came across cleanly. Next: check the cover images.', l: ['purple'] },
            { t: 'Release a WordPress plugin', b: 'Dreams', s: 'Publish the first version to the directory and write the release post.', l: ['green'] },
            { t: 'Public beta checklist', b: 'Ideas', s: 'GUI is required for the first public release. Without it, nobody is going to see what the tool can do.', l: ['yellow'] },
            { t: 'Search across every board', b: 'Roadmap', s: 'Full-text search over titles, descriptions, comments and labels. SQLite FTS5, rebuilt on demand.', l: ['blue'] },
            { t: 'Rewrite the onboarding email', b: 'Voyage', s: 'Keep it to three sentences. Draft, read it out loud, send.', l: ['teal'] },
            { t: 'Import attachments from a private board', b: 'Roadmap', s: 'Paste a Copy as cURL command so the importer can download private attachments.', l: ['purple', 'red'] },
            { t: 'Presentation mode', b: 'Roadmap', s: 'Show a card full screen for a meeting, or export it as a standalone HTML file.', l: ['pink'] },
            { t: 'Kanban for the release train', b: 'Ideas', s: 'One list per sprint, one card per ticket, due dates color by urgency.', l: ['orange'] },
        ];
        const boards = ['All boards', ...Array.from(new Set(CORPUS.map((c) => c.b)))]; let sdBoard = 'All boards', sel = 0;
        $('#sd-chips').innerHTML = boards.map((b) => `<button class="chip ${b === sdBoard ? 'on' : ''}" data-b="${esc(b)}">${esc(b)}</button>`).join('');
        const q = $('#sd-q'), list = $('#sd-list');
        const mark = (s, terms) => { let h = esc(s); terms.forEach((t) => { h = h.replace(new RegExp('(' + t.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'gi'), '<mark>$1</mark>'); }); return h; };
        function search() {
            const terms = q.value.trim().toLowerCase().split(/\s+/).filter(Boolean);
            if (!terms.length) { list.innerHTML = `<div class="sd-empty">Type to search titles, descriptions, comments and labels.</div>`; return; }
            const hits = CORPUS.filter((c) => (sdBoard === 'All boards' || c.b === sdBoard) && terms.every((t) => (c.t + ' ' + c.s).toLowerCase().includes(t)));
            sel = Math.min(sel, Math.max(0, hits.length - 1));
            list.innerHTML = hits.length ? hits.map((c, i) => `<div class="sr ${i === sel ? 'sel' : ''}"><div class="g"><b>${mark(c.t, terms)}</b><div class="snip">${mark(c.s, terms)}</div></div><div class="side"><span class="pill">${esc(c.b)}</span><span style="display:flex;gap:3px">${c.l.map((x) => `<i class="bg-${x}" style="width:8px;height:8px;border-radius:50%;display:block"></i>`).join('')}</span></div></div>`).join('') : `<div class="sd-empty">No results for “${esc(q.value.trim())}”.</div>`;
        }
        search();
        q.addEventListener('input', () => { sel = 0; search(); });
        q.addEventListener('keydown', (e) => { const n = $$('.sr', list).length; if (e.key === 'ArrowDown') { e.preventDefault(); sel = Math.min(sel + 1, n - 1); search(); } else if (e.key === 'ArrowUp') { e.preventDefault(); sel = Math.max(sel - 1, 0); search(); } else if (e.key === 'Enter' && n) { toast('In the app this opens the card'); } else if (e.key === 'Escape') { q.value = ''; search(); } });
        $('#sd-chips').addEventListener('click', (e) => { const b = e.target.closest('[data-b]'); if (!b) return; sdBoard = b.dataset.b; $$('.chip', $('#sd-chips')).forEach((c) => c.classList.toggle('on', c === b)); q.focus(); search(); });
        list.addEventListener('click', (e) => { if (e.target.closest('.sr')) toast('In the app this opens the card'); });

        }

        /* ---- Changelog popup ---- */
        const cl = document.getElementById('cl');
        if (cl) {
            const openCl = (v) => { cl.hidden = false; document.body.style.overflow = 'hidden'; if (v) showRel(v); const first = cl.querySelector('.cl-nav button.on') || cl.querySelector('.cl-nav button'); if (first) first.focus(); };
            const closeCl = () => { cl.hidden = true; document.body.style.overflow = ''; };
            const showRel = (v) => { $$('[data-cl-rel]', cl).forEach((s) => { s.hidden = s.dataset.clRel !== v; }); $$('[data-cl-v]', cl).forEach((b) => b.classList.toggle('on', b.dataset.clV === v)); $('.cl-main', cl).scrollTop = 0; };
            $$('[data-cl-open]').forEach((b) => b.addEventListener('click', () => openCl()));
            $$('[data-cl-close]', cl).forEach((b) => b.addEventListener('click', closeCl));
            cl.addEventListener('click', (e) => { if (e.target === cl) closeCl(); });
            $$('[data-cl-v]', cl).forEach((b) => b.addEventListener('click', () => showRel(b.dataset.clV)));
            document.addEventListener('keydown', (e) => { if (cl.hidden) return; if (e.key === 'Escape') closeCl(); const vs = $$('[data-cl-v]', cl); const i = vs.findIndex((b) => b.classList.contains('on')); if (e.key === 'ArrowDown' && i < vs.length - 1) { showRel(vs[i + 1].dataset.clV); vs[i + 1].focus(); } if (e.key === 'ArrowUp' && i > 0) { showRel(vs[i - 1].dataset.clV); vs[i - 1].focus(); } });
            if (location.hash === '#changelog') openCl();
        }
    })();
