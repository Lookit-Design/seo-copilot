/* Auto SEO for Yoast — Admin JS v1.3.1 */
(function ($) {
    'use strict';

    // ── Placeholder copy/insert ───────────────────────────────────────────────
    $(document).on('click', '.asy-placeholder-btn', function () {
        var $btn = $(this), tag = $btn.data('tag');
        var $focused = $('input.asy-tpl-input:focus');
        if ($focused.length) { insertAtCursor($focused[0], tag); return; }
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(tag).then(function () { flashCopied($btn); });
        } else {
            var $t = $('<input>').val(tag).appendTo('body').select();
            document.execCommand('copy'); $t.remove(); flashCopied($btn);
        }
    });

    function flashCopied($btn) {
        var orig = $btn.html();
        $btn.addClass('asy-copied').html('<code>✓ copied</code>');
        setTimeout(function () { $btn.removeClass('asy-copied').html(orig); }, 1400);
    }

    function insertAtCursor(input, text) {
        var s = input.selectionStart, e = input.selectionEnd, v = input.value;
        input.value = v.substring(0, s) + text + v.substring(e);
        input.selectionStart = input.selectionEnd = s + text.length;
        input.focus();
    }

    // ── Row active state ──────────────────────────────────────────────────────
    $(document).on('change', '.asy-enable-cb', function () {
        $(this).closest('.asy-row').toggleClass('asy-row--active', $(this).is(':checked'));
    });

    // ── Save all settings ─────────────────────────────────────────────────────
    //
    // The table used to save only on an explicit button click, bound directly
    // at parse time. Two things went wrong with that on real sites:
    //   1. A direct $('#asy-save-btn').on(...) binding silently does nothing if
    //      the markup isn't in the DOM yet, so the button looked dead.
    //   2. Nothing saved on navigation, so switching tabs threw the edits away.
    //      Playground hid this — the browser restores <select> values from
    //      bfcache on back/forward, so the settings *looked* like they stuck.
    // Now: delegated binding, debounced auto-save on change, and a flush on
    // page hide. Mirrors how the Bulk Editor keeps its state.

    var SAVE_DEBOUNCE = 700;
    var saveTimer     = null;
    var inFlight      = false;
    var isDirty       = false;

    function collectTemplates() {
        var templates = {};
        $('.asy-row').each(function () {
            // attr() not data() — jQuery's .data() coerces types, so a post
            // type slug that looks numeric or boolean would arrive mangled.
            var pt = String($(this).attr('data-pt') || '');
            if (!pt) { return; }
            templates[pt] = {
                enabled:          $(this).find('.asy-enable-cb').is(':checked') ? '1' : '0',
                keyphrase_source: $(this).find('.asy-kp-source').val() || '',
                related_source:   $(this).find('.asy-rel-source').val() || '',
                template:         $(this).find('.asy-tpl-input').val() || '',
                title_source:     $(this).find('.asy-title-source').val() || '',
            };
        });
        return templates;
    }

    // Compare what the browser sent against what the database actually holds.
    // `enabled` is stored as a PHP bool, so normalise both sides before diffing.
    function verifyRoundTrip(sent, stored) {
        var bad = [];
        if (!stored || typeof stored !== 'object') { return bad; }
        Object.keys(sent).forEach(function (pt) {
            var row = stored[pt];
            if (!row) {
                bad.push({ pt: pt, field: '(whole row)', sent: 'row', got: 'missing' });
                return;
            }
            Object.keys(sent[pt]).forEach(function (field) {
                var a = sent[pt][field];
                var b = row[field];
                if (field === 'enabled') {
                    a = (a === '1' || a === 1 || a === true);
                    b = (b === '1' || b === 1 || b === true);
                } else {
                    a = String(a == null ? '' : a);
                    b = String(b == null ? '' : b);
                }
                if (a !== b) { bad.push({ pt: pt, field: field, sent: a, got: b }); }
            });
        });
        return bad;
    }

    function setState(text, cls) {
        $('#asy-save-state')
            .removeClass('is-dirty is-saving is-saved is-error')
            .addClass(cls || '')
            .text(text);
    }

    function saveTemplates(silent) {
        if (inFlight) { return; }
        var templates = collectTemplates();
        var rowCount  = Object.keys(templates).length;
        if (!rowCount) { return; }

        inFlight = true;
        if (saveTimer) { clearTimeout(saveTimer); saveTimer = null; }
        $('#asy-save-btn').prop('disabled', true).text('Saving…');
        setState('Saving…', 'is-saving');

        if (window.console && console.log) {
            console.log('[Auto SEO] sending ' + rowCount + ' rows', templates);
        }

        $.post(ASY.ajax_url, {
            action:    'asy_save_templates',
            nonce:     ASY.nonce,
            row_count: rowCount,
            templates: templates,
        })
        .done(function (r) {
            // admin-ajax answers a stale nonce or a logged-out session with a
            // bare -1 / 0, which used to surface as the generic error string.
            if (r === -1 || r === '-1' || r === 0 || r === '0') {
                isDirty = true;
                setState('Session expired', 'is-error');
                showNotice('Your session expired. Reload this page, then save again.', 'error');
                return;
            }
            if (r && r.success) {
                var bad = verifyRoundTrip(templates, r.data && r.data.stored);
                if (bad.length) {
                    isDirty = false;
                    setState('Saved, but ' + bad.length + ' field(s) changed on write', 'is-error');
                    showNotice(
                        'The server accepted the save but stored different values for: ' +
                        bad.slice(0, 4).map(function (b) {
                            return b.pt + '.' + b.field + ' (sent "' + b.sent + '", stored "' + b.got + '")';
                        }).join('; ') + (bad.length > 4 ? ' …and ' + (bad.length - 4) + ' more' : '') +
                        ' — full detail in the browser console.',
                        'error'
                    );
                    if (window.console && console.table) { console.table(bad); }
                    return;
                }
                isDirty = false;
                setState('All changes saved', 'is-saved');
                if (!silent) { showNotice(ASY.saved, 'success'); }
                return;
            }
            isDirty = true;
            var msg = (r && r.data && typeof r.data === 'string') ? r.data : ASY.error;
            setState('Not saved', 'is-error');
            showNotice(msg, 'error');
        })
        .fail(function () {
            isDirty = true;
            setState('Not saved', 'is-error');
            showNotice(ASY.error, 'error');
        })
        .always(function () {
            inFlight = false;
            $('#asy-save-btn').prop('disabled', false).text('Save Settings');
        });
    }

    // Explicit button — delegated so it works no matter when the table renders.
    $(document).on('click', '#asy-save-btn', function () { saveTemplates(false); });

    // Auto-save on any change inside the table.
    $(document).on('change', '.asy-row select, .asy-row input', function () {
        isDirty = true;
        setState('Unsaved changes…', 'is-dirty');
        if (saveTimer) { clearTimeout(saveTimer); }
        saveTimer = setTimeout(function () { saveTemplates(true); }, SAVE_DEBOUNCE);
    });

    // Flush anything still pending when the page goes away (tab switch, menu
    // click, reload). sendBeacon survives navigation; $.post does not.
    function flushPending() {
        if (!isDirty || inFlight) { return; }
        if (!window.navigator || !navigator.sendBeacon || !window.FormData) { return; }

        var templates = collectTemplates();
        var rowCount  = Object.keys(templates).length;
        if (!rowCount) { return; }

        var fd = new FormData();
        fd.append('action', 'asy_save_templates');
        fd.append('nonce', ASY.nonce);
        fd.append('row_count', String(rowCount));
        Object.keys(templates).forEach(function (pt) {
            Object.keys(templates[pt]).forEach(function (field) {
                fd.append('templates[' + pt + '][' + field + ']', templates[pt][field]);
            });
        });

        if (navigator.sendBeacon(ASY.ajax_url, fd)) { isDirty = false; }
    }

    $(window).on('pagehide', flushPending);
    $(document).on('visibilitychange', function () {
        if (document.visibilityState === 'hidden') { flushPending(); }
    });

    // Last resort: if the flush couldn't run, don't let the edits vanish quietly.
    $(window).on('beforeunload', function (e) {
        if (!isDirty) { return; }
        e.preventDefault();
        e.originalEvent.returnValue = '';
        return '';
    });

    // ── Reprocess a single post (synchronous — fast, no polling needed) ───────
    $(document).on('click', '#asy-reprocess-btn', function () {
        var $btn    = $(this);
        var $result = $('#asy-reprocess-result');
        var postId  = parseInt($('#asy_reprocess_id').val(), 10);

        if (!postId || postId < 1) {
            setResult($result, 'error', 'Please enter a valid post ID.');
            return;
        }

        $btn.prop('disabled', true).text('Generating…');
        $result.hide();

        $.post(ASY.ajax_url, {
            action:  'asy_reprocess_post',
            nonce:   ASY.nonce,
            post_id: postId,
        })
        .done(function (r) {
            if (r.success && r.data && r.data.keyphrases) {
                var pills = r.data.keyphrases.map(function (kp) {
                    return '<span class="asy-kp-pill">' + escHtml(kp) + '</span>';
                }).join('');
                setResult($result, 'ok',
                    '✓ Saved to post ' + postId + '. <strong>Reload the post editor</strong> to see them in Yoast.<br>' + pills
                );
            } else {
                var msg = (r.data && typeof r.data === 'string') ? r.data : ASY.error;
                setResult($result, 'error', 'Error: ' + msg);
            }
        })
        .fail(function () {
            setResult($result, 'error', ASY.error);
        })
        .always(function () { $btn.prop('disabled', false).text('Generate Keyphrases'); });
    });

    // ── Use AI: focus keyphrase + related + meta description via Bedrock ──────
    $(document).on('click', '#asy-ai-btn', function () {
        var $btn    = $(this);
        var $result = $('#asy-reprocess-result');
        var postId  = parseInt($('#asy_reprocess_id').val(), 10);

        if (!postId || postId < 1) {
            setResult($result, 'error', 'Please enter a valid post ID.');
            return;
        }

        $btn.prop('disabled', true).text('Generating…');
        $result.hide();

        $.post(ASY.ajax_url, {
            action:  'asy_ai_generate',
            nonce:   ASY.nonce,
            post_id: postId,
        })
        .done(function (r) {
            if (r.success && r.data) {
                var d = r.data;
                var pills = (d.related || []).map(function (kp) {
                    return '<span class="asy-kp-pill">' + escHtml(kp) + '</span>';
                }).join('');
                setResult($result, 'ok',
                    '\u2726 AI generated and saved to post ' + postId + '. <strong>Reload the post editor</strong> to see them in Yoast.<br>' +
                    '<strong>Focus keyphrase:</strong> ' + escHtml(d.primary || '') + '<br>' +
                    (pills ? '<strong>Related:</strong> ' + pills + '<br>' : '') +
                    '<strong>Meta description:</strong> ' + escHtml(d.metadesc || '')
                );
            } else {
                var msg = (r.data && typeof r.data === 'string') ? r.data : ASY.error;
                setResult($result, 'error', 'Error: ' + msg);
            }
        })
        .fail(function () {
            setResult($result, 'error', ASY.error);
        })
        .always(function () { $btn.prop('disabled', false).html('\u2726 Use AI'); });
    });

    // ── Helpers ───────────────────────────────────────────────────────────────

    function setResult($el, type, html) {
        $el.removeClass('asy-reprocess-result--ok asy-reprocess-result--err asy-reprocess-result--info')
           .addClass('asy-reprocess-result--' + type)
           .html(html)
           .show();
    }

    function escHtml(str) {
        return $('<span>').text(str).html();
    }

    function showNotice(msg, type) {
        $('#asy-notice').removeClass('asy-notice--success asy-notice--error')
            .addClass('asy-notice--' + type).text(msg).fadeIn(200);
        setTimeout(function () { $('#asy-notice').fadeOut(400); }, 3500);
    }

}(jQuery));
