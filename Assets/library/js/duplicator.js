/* SPDX-License-Identifier: GPL-3.0-or-later */
(function () {
    'use strict';
    if (window.WebAnyOneFieldDuplicator) return;
    window.WebAnyOneFieldDuplicator = true;
    var busy = false;
    var scheduled = false;

    function config() {
        var node = document.getElementById('wao-duplicator-config');
        var session = document.getElementById('mauticform_sessionId');
        if (!node || !session) return null;
        try {
            var value = JSON.parse(node.dataset.config);
            return value.formId === session.value ? value : null;
        } catch (_) { return null; }
    }

    function status(message, error) {
        var fields = document.getElementById('mauticforms_fields');
        if (!fields) return;
        var node = document.getElementById('wao-duplicator-status');
        if (!node) {
            node = document.createElement('div');
            node.id = 'wao-duplicator-status';
            node.setAttribute('role', 'status');
            node.setAttribute('aria-live', 'polite');
            fields.prepend(node);
        }
        node.className = error ? 'alert alert-danger' : 'text-muted small mb-sm';
        node.textContent = message;
    }

    function initialize() {
        scheduled = false;
        var cfg = config();
        var fields = document.getElementById('mauticforms_fields');
        if (!cfg || !fields) {
            document.querySelectorAll('.wao-duplicate').forEach(function (button) { button.remove(); });
            return;
        }
        fields.querySelectorAll('.form-field-wrapper').forEach(function (wrapper) {
            var row = Array.from(wrapper.querySelectorAll('.mauticform-row')).find(function (candidate) {
                return candidate.closest('.form-field-wrapper') === wrapper;
            });
            var supported = row && cfg.supported.some(function (type) { return row.classList.contains('mauticform-' + type); });
            var actions = Array.from(wrapper.querySelectorAll('.form-buttons')).find(function (candidate) {
                return candidate.closest('.form-field-wrapper') === wrapper;
            });
            if (!supported || !actions || actions.querySelector('.wao-duplicate')) return;
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'btn btn-ghost btn-sm wao-duplicate';
            button.title = cfg.messages.duplicate;
            button.setAttribute('aria-label', cfg.messages.duplicate);
            button.dataset.source = wrapper.dataset.sortableId.replace(/^mauticform_/, '');
            button.disabled = busy;
            var icon = document.createElement('i');
            icon.className = 'ri-file-copy-line';
            icon.setAttribute('aria-hidden', 'true');
            button.append(icon);
            actions.append(button);
            if (window.mQuery && mQuery.fn.tooltip) mQuery(button).tooltip({html: false});
        });
    }

    function schedule() {
        if (!scheduled) {
            scheduled = true;
            window.setTimeout(initialize, 0);
        }
    }

    function bindNative(root) {
        var $root = mQuery(root);
        $root.find('[data-toggle="ajax"]').on('click', function (event) {
            event.preventDefault();
            return Mautic.ajaxifyLink(this, event);
        });
        $root.find('[data-toggle="ajaxmodal"]').on('click.ajaxmodal', function (event) {
            event.preventDefault();
            Mautic.ajaxifyModal(this, event);
        });
        $root.find('[data-toggle="tooltip"]').tooltip({html: true});
        Mautic.initFormFieldButtons(root);
        Mautic.initHideItemButton(root);
        Mautic.activateChosenSelect($root.find('.form-builder-new-component'));
        Mautic.formBuilderNewComponentInit();
        $root.find('.add-new-conditional-field').on('click', function (event) {
            event.preventDefault();
            mQuery(this).parent().next().show('normal');
        });
    }

    function duplicate(button) {
        var cfg = config();
        if (!cfg || busy || !window.mQuery || !window.Mautic) return;
        var modal = document.getElementById('formComponentModal');
        if ((modal && (modal.classList.contains('in') || modal.getAttribute('aria-hidden') === 'false')) ||
            (window.MauticVars && MauticVars.formSubmitInProgress)) {
            status(cfg.messages.modal_open, true);
            return;
        }
        var source = button.closest('.form-field-wrapper');
        busy = true;
        document.querySelectorAll('.wao-duplicate').forEach(function (item) { item.disabled = true; });
        button.setAttribute('aria-busy', 'true');
        status('', false);
        if (!button.dataset.requestId) {
            var random = new Uint8Array(16);
            window.crypto.getRandomValues(random);
            button.dataset.requestId = Array.from(random).map(function (byte) { return byte.toString(16).padStart(2, '0'); }).join('');
        }
        mQuery.ajax({
            url: cfg.url,
            type: 'POST',
            dataType: 'json',
            data: {formId: cfg.formId, generation: cfg.generation, _token: cfg.token, source: button.dataset.source, requestId: button.dataset.requestId},
            success: function (response) {
                var current = config();
                if (!current || current.generation !== cfg.generation || !source.isConnected) return;
                if (!response.success) { status(response.message || cfg.messages['error.generic'], true); return; }
                var selector = '[data-sortable-id="mauticform_' + response.rootId + '"]';
                var copy = document.querySelector(selector);
                if (!copy) {
                    // HTML comes only from the authenticated server's native Twig renderer.
                    var fragment = mQuery(response.fieldHtml);
                    mQuery(source).after(fragment);
                    copy = document.querySelector(selector);
                    bindNative(copy);
                }
                delete button.dataset.requestId;
                initialize();
                status(response.message || '', false);
                mQuery('#mauticforms_fields').sortable('refresh');
                var edit = copy.querySelector('.btn-edit');
                if (edit) { edit.focus(); mQuery(edit).trigger('click'); }
            },
            error: function (xhr) {
                var current = config();
                if (!current || current.generation !== cfg.generation) return;
                status((xhr.responseJSON && xhr.responseJSON.message) || cfg.messages['error.generic'], true);
                // Keep ID on ambiguous network/500 failures: retry cannot create a second group.
                if (xhr.status >= 400 && xhr.status < 500) delete button.dataset.requestId;
            },
            complete: function () {
                busy = false;
                button.removeAttribute('aria-busy');
                document.querySelectorAll('.wao-duplicate').forEach(function (item) { item.disabled = false; });
            }
        });
    }

    document.addEventListener('click', function (event) {
        var button = event.target.closest('.wao-duplicate');
        if (button) {
            event.preventDefault();
            event.stopImmediatePropagation();
            duplicate(button);
        } else if (busy && event.target.closest('a, button')) {
            // Let the in-flight operation finish before save/cancel/navigation/edit/reorder.
            event.preventDefault();
            event.stopImmediatePropagation();
        }
    }, true);
    document.addEventListener('dblclick', function (event) {
        if (event.target.closest('.wao-duplicate')) { event.preventDefault(); event.stopImmediatePropagation(); }
    }, true);
    document.addEventListener('submit', function (event) {
        if (busy) { event.preventDefault(); event.stopImmediatePropagation(); }
    }, true);
    document.addEventListener('mousedown', function (event) {
        if (busy && event.target.closest('#mauticforms_fields')) { event.preventDefault(); event.stopImmediatePropagation(); }
    }, true);
    new MutationObserver(schedule).observe(document.documentElement, {childList: true, subtree: true});
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize);
    else initialize();
}());
