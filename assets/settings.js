/**
 * Lookit Bulk SEO Manager — Settings page behaviour (3.27.0)
 *
 * Nav rail, shared placeholder palette targeting, live SERP preview and the
 * sample-post picker. Data arrives via wp_localize_script as BSM_SET.
 */
(function () {
    'use strict';

    var root = document.getElementById('bsm-set-shell');
    if (!root || typeof BSM_SET === 'undefined') { return; }

    var TPL_PANES  = { title: 1, desc: 1, kp: 1 };
    var LIST_FOR   = { title: 'bsm-title-templates-list', desc: 'bsm-templates-list', kp: 'bsm-kw-templates-list' };
    var LIMIT_FOR  = { title: 60, desc: 155, kp: 40 };
    var LABEL_FOR  = { title: 'Title', desc: 'Description', kp: 'Keyphrase' };

    var shell    = root;
    var prev     = document.getElementById('bsm-set-prev');
    var savebar  = document.querySelector('.bsm-set-savebar');
    var dirtyEl  = document.getElementById('bsm-set-dirty');
    var current  = 'engine';
    var sampleId = parseInt(BSM_SET.sample_id, 10) || 0;
    var lastFocused = {};   // pane -> the template-string input last focused
    var timer = null;

    /* ── Nav ──────────────────────────────────────────────────────────── */

    function showPane(name) {
        var pane = shell.querySelector('.bsm-set-pane[data-pane="' + name + '"]');
        if (!pane) { return; }
        current = name;

        shell.querySelectorAll('.bsm-set-pane').forEach(function (p) {
            p.classList.toggle('is-active', p === pane);
        });
        shell.querySelectorAll('.bsm-set-navbtn').forEach(function (b) {
            b.classList.toggle('is-active', b.getAttribute('data-pane') === name);
        });

        var isTpl = !!TPL_PANES[name];
        prev.hidden = !isTpl;
        shell.classList.toggle('bsm-no-preview', !isTpl);

        if (window.history && window.history.replaceState) {
            window.history.replaceState(null, '', '#' + name);
        }
        if (isTpl) { syncTarget(); refresh(); }
    }

    shell.querySelectorAll('.bsm-set-navbtn').forEach(function (btn) {
        btn.addEventListener('click', function () { showPane(btn.getAttribute('data-pane')); });
    });

    /* ── Template rows ────────────────────────────────────────────────── */

    function stringInputs(pane) {
        var list = document.getElementById(LIST_FOR[pane]);
        return list ? Array.prototype.slice.call(list.querySelectorAll('input[name*="[template]"]')) : [];
    }

    function activeInput(pane) {
        var inputs = stringInputs(pane);
        if (!inputs.length) { return null; }
        if (lastFocused[pane] && inputs.indexOf(lastFocused[pane]) !== -1) { return lastFocused[pane]; }
        return inputs[0];
    }

    function rowLabel(input) {
        var row = input.closest('.bsm-tpl-row');
        var name = row ? row.querySelector('input[name*="[label]"]') : null;
        var val = name && name.value ? name.value.trim() : '';
        return val || 'Untitled template';
    }

    function syncTarget() {
        var el = shell.querySelector('.bsm-set-palette-target[data-target-for="' + current + '"]');
        if (!el) { return; }
        var input = activeInput(current);
        el.innerHTML = '';
        if (!input) { return; }
        var into = document.createTextNode('into ');
        var strong = document.createElement('strong');
        strong.textContent = rowLabel(input);
        el.appendChild(into);
        el.appendChild(strong);
    }

    function markActiveRow(input) {
        var list = document.getElementById(LIST_FOR[current]);
        if (!list) { return; }
        list.querySelectorAll('.bsm-tpl-row').forEach(function (r) {
            r.classList.toggle('bsm-tpl-active', input && r.contains(input));
        });
    }

    /* Delegated so rows added by buildTemplateRow() are covered too. */
    document.addEventListener('focusin', function (e) {
        var t = e.target;
        if (!t || !t.matches || !t.matches('input[name*="[template]"]')) { return; }
        if (!TPL_PANES[current]) { return; }
        lastFocused[current] = t;
        markActiveRow(t);
        syncTarget();
        refresh();
    });

    document.addEventListener('input', function (e) {
        var t = e.target;
        if (!t || !t.matches) { return; }
        if (t.matches('input[name*="[template]"]')) { markDirty(); refresh(); }
        else if (t.matches('input[name*="[label]"]')) { markDirty(); syncTarget(); }
    });

    document.addEventListener('click', function (e) {
        if (e.target.closest && e.target.closest('.bsm-remove-tpl')) {
            setTimeout(function () { recount(); syncTarget(); refresh(); }, 0);
        }
        if (e.target.closest && e.target.closest('#bsm-add-template, #bsm-add-title-template, #bsm-add-kw-template')) {
            setTimeout(function () { recount(); syncTarget(); }, 0);
        }
    });

    function recount() {
        Object.keys(LIST_FOR).forEach(function (pane) {
            var badge = shell.querySelector('.bsm-set-count[data-count-for="' + pane + '"]');
            if (badge) { badge.textContent = stringInputs(pane).length; }
        });
    }

    function markDirty() {
        if (!savebar) { return; }
        savebar.classList.add('is-dirty');
        if (dirtyEl) { dirtyEl.textContent = 'Unsaved changes'; }
    }

    /* ── Live preview ─────────────────────────────────────────────────── */

    function firstValue(pane) {
        var input = activeInput(pane);
        return input ? input.value : '';
    }

    function setMeter(text) {
        var box = document.getElementById('bsm-set-meter');
        if (!box) { return; }
        var max  = LIMIT_FOR[current] || 60;
        var n    = text.length;
        var over = n > max;
        var pct  = Math.min(100, Math.round((n / max) * 100));

        box.innerHTML = '';
        var wrap = document.createElement('div');
        wrap.className = 'bsm-set-meter' + (over ? ' is-over' : '');

        var label = document.createElement('span');
        label.style.minWidth = '118px';
        label.textContent = (LABEL_FOR[current] || '') + ' ' + n + '/' + max;

        var bar = document.createElement('div');
        bar.className = 'bsm-set-bar';
        var fill = document.createElement('span');
        fill.style.width = pct + '%';
        bar.appendChild(fill);

        wrap.appendChild(label);
        wrap.appendChild(bar);
        if (over) {
            var warn = document.createElement('span');
            warn.textContent = 'truncated';
            wrap.appendChild(warn);
        }
        box.appendChild(wrap);
    }

    function paint(data) {
        var titleEl = document.getElementById('bsm-serp-title');
        var descEl  = document.getElementById('bsm-serp-desc');
        if (!titleEl || !descEl) { return; }

        var live = data.live || {};
        var titleTxt = data.title || live.title || (data.post && data.post.title) || '—';
        var descTxt  = data.desc  || live.desc  || '';

        titleEl.textContent = titleTxt;
        titleEl.className = 'bsm-set-serp-title' +
            (current === 'title' ? ' bsm-set-focus' : (current === 'desc' ? ' bsm-set-dim' : ''));

        if (descTxt) {
            descEl.textContent = descTxt;
            descEl.className = 'bsm-set-serp-desc' +
                (current === 'desc' ? ' bsm-set-focus' : (current === 'title' ? ' bsm-set-dim' : ''));
        } else {
            descEl.textContent = 'No description template selected — Google will pull its own snippet from the page.';
            descEl.className = 'bsm-set-serp-desc bsm-set-fallback';
        }

        var kpEl = document.getElementById('bsm-prev-kp');
        if (kpEl) { kpEl.textContent = data.kp || live.kp || 'Not set for this post'; }

        setMeter(current === 'desc' ? descTxt : (current === 'kp' ? (data.kp || '') : titleTxt));

        if (data.post) {
            sampleId = data.post.id;
            setText('bsm-sample-title', data.post.title);
            setText('bsm-sample-type', data.post.type);
            setText('bsm-sample-slug', '/' + data.post.slug);
            setText('bsm-sample-id', String(data.post.id));
            setText('bsm-serp-url', data.post.url || '');
        }
    }

    function setText(id, txt) {
        var el = document.getElementById(id);
        if (el) { el.textContent = txt; }
    }

    function refresh() {
        if (!TPL_PANES[current] || !sampleId) { return; }
        clearTimeout(timer);
        timer = setTimeout(function () {
            var body = new FormData();
            body.append('action', 'bsm_preview_templates');
            body.append('nonce', BSM_SET.nonce);
            body.append('post_id', sampleId);
            body.append('title_tpl', firstValue('title'));
            body.append('desc_tpl', firstValue('desc'));
            body.append('kp_tpl', firstValue('kp'));

            window.fetch(BSM_SET.ajax_url, { method: 'POST', body: body, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (resp) { if (resp && resp.success) { paint(resp.data); } })
                .catch(function () { /* preview is non-critical — stay quiet */ });
        }, 250);
    }

    /* ── Post picker ──────────────────────────────────────────────────── */

    var scrim   = document.getElementById('bsm-set-scrim');
    var query   = document.getElementById('bsm-picker-q');
    var results = document.getElementById('bsm-picker-results');
    var pickerType = 'all';
    var pickerTimer = null;
    var lastTrigger = null;

    function search() {
        if (!results) { return; }
        var body = new FormData();
        body.append('action', 'bsm_search_posts');
        body.append('nonce', BSM_SET.nonce);
        body.append('s', query ? query.value : '');
        body.append('type', pickerType);

        window.fetch(BSM_SET.ajax_url, { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (resp) {
                results.innerHTML = '';
                var posts = (resp && resp.success && resp.data.posts) ? resp.data.posts : [];
                if (!posts.length) {
                    var none = document.createElement('div');
                    none.className = 'bsm-set-res-none';
                    none.textContent = 'Nothing matches that. Try an ID, or clear the type filter.';
                    results.appendChild(none);
                    return;
                }
                posts.forEach(function (p) {
                    var btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'bsm-set-res';

                    var box = document.createElement('div');
                    box.style.minWidth = '0';
                    var t = document.createElement('p');
                    t.className = 'bsm-set-res-t';
                    t.textContent = p.title;
                    var m = document.createElement('p');
                    m.className = 'bsm-set-res-m';
                    var tag = document.createElement('span');
                    tag.className = 'bsm-set-tag';
                    tag.textContent = p.type;
                    m.appendChild(tag);
                    m.appendChild(document.createTextNode('/' + p.slug));
                    box.appendChild(t);
                    box.appendChild(m);

                    var id = document.createElement('span');
                    id.className = 'bsm-set-res-id';
                    id.textContent = '#' + p.id;

                    btn.appendChild(box);
                    btn.appendChild(id);
                    btn.addEventListener('click', function () {
                        sampleId = p.id;
                        closePicker();
                        refresh();
                    });
                    results.appendChild(btn);
                });
            })
            .catch(function () {
                results.innerHTML = '';
                var err = document.createElement('div');
                err.className = 'bsm-set-res-none';
                err.textContent = 'Could not reach the server. Try again.';
                results.appendChild(err);
            });
    }

    function openPicker() {
        if (!scrim) { return; }
        lastTrigger = document.activeElement;
        scrim.hidden = false;
        if (query) { query.value = ''; query.focus(); }
        search();
    }

    function closePicker() {
        if (!scrim) { return; }
        scrim.hidden = true;
        if (lastTrigger && lastTrigger.focus) { lastTrigger.focus(); }
    }

    var changeBtn = document.getElementById('bsm-change-sample');
    if (changeBtn) { changeBtn.addEventListener('click', openPicker); }

    var closeBtn = document.getElementById('bsm-picker-close');
    if (closeBtn) { closeBtn.addEventListener('click', closePicker); }

    if (scrim) {
        scrim.addEventListener('click', function (e) { if (e.target === scrim) { closePicker(); } });
    }
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && scrim && !scrim.hidden) { closePicker(); }
    });
    if (query) {
        query.addEventListener('input', function () {
            clearTimeout(pickerTimer);
            pickerTimer = setTimeout(search, 250);
        });
    }
    var filters = document.getElementById('bsm-picker-filters');
    if (filters) {
        filters.addEventListener('click', function (e) {
            var b = e.target.closest('.bsm-set-filt');
            if (!b) { return; }
            filters.querySelectorAll('.bsm-set-filt').forEach(function (x) { x.classList.remove('is-active'); });
            b.classList.add('is-active');
            pickerType = b.getAttribute('data-type');
            search();
        });
    }

    /* ── Text size ────────────────────────────────────────────────────── */

    var sizeWrap = shell.querySelector('.bsm-set-sizes');
    if (sizeWrap) {
        sizeWrap.addEventListener('click', function (e) {
            var btn = e.target.closest('.bsm-set-size');
            if (!btn) { return; }

            var scale = btn.getAttribute('data-scale');
            var size  = btn.getAttribute('data-size');

            // Apply immediately so the change is visible while it saves.
            document.documentElement.style.setProperty('--bsm-fs', scale);

            sizeWrap.querySelectorAll('.bsm-set-size').forEach(function (b) {
                var on = b === btn;
                b.classList.toggle('is-active', on);
                b.setAttribute('aria-checked', on ? 'true' : 'false');
            });

            var status = document.getElementById('bsm-size-status');
            if (status) { status.textContent = 'Saving…'; }

            var body = new FormData();
            body.append('action', 'bsm_save_text_size');
            body.append('nonce', BSM_SET.nonce);
            body.append('size', size);

            window.fetch(BSM_SET.ajax_url, { method: 'POST', body: body, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (resp) {
                    if (status) {
                        status.textContent = (resp && resp.success)
                            ? 'Saved. This applies across the Bulk Editor, Auto SEO Manager and Settings.'
                            : 'Could not save that — it will reset on reload.';
                    }
                })
                .catch(function () {
                    if (status) { status.textContent = 'Could not save that — it will reset on reload.'; }
                });
        });
    }

    /* ── Boot ─────────────────────────────────────────────────────────── */

    var hash = (window.location.hash || '').replace('#', '');
    showPane(shell.querySelector('.bsm-set-pane[data-pane="' + hash + '"]') ? hash : 'engine');
    recount();
}());
