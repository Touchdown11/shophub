/* ==========================================================================
   ShopHub — admin dashboard behaviour
   ========================================================================== */
(function () {
    'use strict';

    const CFG = window.SHOPHUB || {};
    const $  = (sel, root = document) => root.querySelector(sel);
    const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

    function toast(message, type = 'success') {
        const stack = $('[data-toast-stack]');
        if (!stack) return;
        const el = document.createElement('div');
        el.className = 'toast is-' + type;
        el.textContent = message;
        stack.appendChild(el);
        setTimeout(() => {
            el.classList.add('is-out');
            setTimeout(() => el.remove(), 260);
        }, 3200);
    }

    /* sidebar on small screens */
    const burger = $('[data-admin-burger]');
    if (burger) {
        burger.addEventListener('click', (event) => {
            event.stopPropagation();
            $('[data-admin-sidebar]').classList.toggle('is-open');
        });
        document.addEventListener('click', (event) => {
            const sidebar = $('[data-admin-sidebar]');
            if (sidebar && sidebar.classList.contains('is-open') && !event.target.closest('[data-admin-sidebar]') && !event.target.closest('[data-admin-burger]')) {
                sidebar.classList.remove('is-open');
            }
        });
    }

    /* select-all checkbox in product tables */
    $$('[data-check-all]').forEach((master) => {
        master.addEventListener('change', () => {
            $$('input[name="ids[]"]').forEach((box) => { box.checked = master.checked; });
        });
    });

    /* specification key/value rows */
    const specWrap = $('[data-spec-rows]');
    if (specWrap) {
        $('[data-add-spec]')?.addEventListener('click', () => {
            const row = document.createElement('div');
            row.className = 'spec-row';
            row.innerHTML = '<input type="text" name="spec_key[]" placeholder="e.g. Battery life">'
                          + '<input type="text" name="spec_value[]" placeholder="e.g. 30 hours">'
                          + '<button type="button" class="icon-btn" data-remove-row aria-label="Remove row">×</button>';
            specWrap.appendChild(row);
        });
        specWrap.addEventListener('click', (event) => {
            const btn = event.target.closest('[data-remove-row]');
            if (btn) {
                const rows = $$('.spec-row', specWrap);
                if (rows.length > 1) btn.closest('.spec-row').remove();
                else rows[0].querySelectorAll('input').forEach((i) => { i.value = ''; });
            }
        });
    }

    /* confirmation guards */
    $$('[data-confirm-form]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (!window.confirm(form.dataset.confirmForm)) event.preventDefault();
        });
    });
    document.addEventListener('click', (event) => {
        const btn = event.target.closest('[data-confirm]');
        if (btn && !window.confirm(btn.dataset.confirm)) event.preventDefault();
    });

    /* inline product visibility toggle */
    $$('[data-toggle-product]').forEach((btn) => {
        btn.addEventListener('click', async () => {
            const body = new FormData();
            body.append('do', 'toggle');
            body.append('id', btn.dataset.toggleProduct);
            const res = await fetch(CFG.base + '/products.php', {
                method: 'POST',
                body,
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': CFG.csrf }
            });
            const data = await res.json().catch(() => ({ ok: false }));
            if (data.ok) {
                toast(data.message || 'Updated.');
                setTimeout(() => window.location.reload(), 500);
            } else {
                toast('Could not update visibility.', 'danger');
            }
        });
    });

    /* auto-dismiss flash messages */
    $$('.flash').forEach((flash) => {
        setTimeout(() => {
            flash.style.transition = 'opacity .4s, transform .4s';
            flash.style.opacity = '0';
            flash.style.transform = 'translateY(-6px)';
            setTimeout(() => flash.remove(), 420);
        }, 6000);
    });

    /* quick inline search filter for long tables */
    $$('[data-table-filter]').forEach((input) => {
        input.addEventListener('input', () => {
            const term = input.value.toLowerCase();
            const table = document.querySelector(input.dataset.tableFilter);
            if (!table) return;
            $$('tbody tr', table).forEach((row) => {
                row.style.display = row.textContent.toLowerCase().includes(term) ? '' : 'none';
            });
        });
    });

    /* ======================================================================
       Next iteration: variant editor, gallery drag-to-reorder, WYSIWYG
       ====================================================================== */

    async function api(path, payload) {
        const body = new FormData();
        Object.entries(payload || {}).forEach(([key, value]) => {
            if (Array.isArray(value)) {
                value.forEach((entry) => body.append(key + '[]', entry));
            } else if (value !== null && typeof value === 'object') {
                Object.entries(value).forEach(([k, v]) => body.append(key + '[' + k + ']', v));
            } else {
                body.append(key, value);
            }
        });
        const res = await fetch(CFG.base + '/' + path, {
            method: 'POST',
            body,
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': CFG.csrf }
        });
        try { return await res.json(); } catch (err) { return { ok: false, message: 'Unexpected server response.' }; }
    }

    /* ------------------------------------------------------- WYSIWYG editor */

    $$('[data-wysiwyg]').forEach((wrap) => {
        const area   = $('[data-wys-area]', wrap);
        const input  = $('[data-wys-input]', wrap);
        const toggle = $('[data-wys-toggle]', wrap);
        if (!area || !input) return;

        const sync = () => { input.value = area.innerHTML.trim(); };
        const ensureFocus = () => { if (document.activeElement !== area) area.focus(); };

        wrap.addEventListener('click', (event) => {
            const cmdBtn = event.target.closest('[data-wys-cmd]');
            const blockBtn = event.target.closest('[data-wys-block]');
            const quoteBtn = event.target.closest('[data-wys-quote]');
            const linkBtn = event.target.closest('[data-wys-link]');

            if (cmdBtn) {
                event.preventDefault();
                ensureFocus();
                document.execCommand(cmdBtn.dataset.wysCmd, false, null);
                sync();
                return;
            }
            if (blockBtn) {
                event.preventDefault();
                ensureFocus();
                document.execCommand('formatBlock', false, '<' + blockBtn.dataset.wysBlock + '>');
                sync();
                return;
            }
            if (quoteBtn) {
                event.preventDefault();
                ensureFocus();
                document.execCommand('formatBlock', false, '<blockquote>');
                sync();
                return;
            }
            if (linkBtn) {
                event.preventDefault();
                ensureFocus();
                const url = window.prompt('Link address (https://…)');
                if (url) {
                    document.execCommand('createLink', false, url);
                    // execCommand forgets the target on same-tab links; make them safe.
                    $$('a[href^="http"]', area).forEach((a) => {
                        a.setAttribute('target', '_blank');
                        a.setAttribute('rel', 'noopener nofollow');
                    });
                    sync();
                }
            }
        });

        ['input', 'blur', 'keyup'].forEach((evt) => area.addEventListener(evt, sync));

        // Paste as plain text so Word styling never leaks into the catalogue.
        area.addEventListener('paste', (event) => {
            event.preventDefault();
            const text = (event.clipboardData || window.clipboardData).getData('text/plain');
            document.execCommand('insertText', false, text);
            sync();
        });

        if (toggle) {
            toggle.addEventListener('click', (event) => {
                event.preventDefault();
                const showingSource = !input.hidden;
                if (showingSource) {
                    area.innerHTML = input.value;
                    input.hidden = true;
                    area.hidden = false;
                    toggle.classList.remove('is-on');
                } else {
                    sync();
                    input.hidden = false;
                    area.hidden = true;
                    toggle.classList.add('is-on');
                    input.focus();
                }
            });
        }

        // Make sure the form always posts the latest markup.
        const form = wrap.closest('form');
        if (form) form.addEventListener('submit', sync);
    });

    /* --------------------------------------------------------- variant editor */

    const editor = $('[data-variant-editor]');
    if (editor) {
        const rowsWrap  = $('[data-option-rows]', editor);
        const body      = $('[data-variant-body]', editor);
        const tableWrap = $('[data-variant-wrap]', editor);
        const emptyNote = $('[data-variant-empty]', editor);
        const summary   = $('[data-variant-summary]', editor);

        let existing = [];
        try { existing = JSON.parse(editor.dataset.existing || '[]') || []; } catch (err) { existing = []; }

        const readGroups = () => $$('[data-option-row]', rowsWrap).map((row) => {
            const name = ($('input[name="option_name[]"]', row) || {}).value || '';
            const values = (($('input[name="option_values[]"]', row) || {}).value || '')
                .split(',').map((v) => v.trim()).filter(Boolean);
            return { name: name.trim(), values };
        }).filter((group) => group.name && group.values.length);

        const combos = (groups) => {
            let out = [{}];
            groups.forEach((group) => {
                const next = [];
                out.forEach((combo) => {
                    group.values.forEach((value) => {
                        next.push(Object.assign({}, combo, { [group.name]: value }));
                    });
                });
                out = next;
            });
            return out;
        };

        const label = (options) => groupsOrder.map((name) => options[name]).filter(Boolean).join(' / ');
        let groupsOrder = [];

        const findExisting = (options) => existing.find((row) =>
            Object.keys(row.options).length === Object.keys(options).length
            && Object.keys(options).every((name) => row.options[name] === options[name]));

        const esc = (value) => String(value).replace(/[&<>"]/g, (ch) => (
            { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[ch]
        ));

        /* Keep values already typed in the table when the groups change. */
        const currentValues = () => {
            const map = {};
            $$('tr', body).forEach((row) => {
                const key = row.dataset.combo;
                map[key] = {
                    sku: ($('input[name="variant_sku[]"]', row) || {}).value || '',
                    price: ($('input[name="variant_price[]"]', row) || {}).value || '',
                    stock: ($('input[name="variant_stock[]"]', row) || {}).value || '',
                    active: $('input[name="variant_active[]"]', row)?.checked ? '1' : ''
                };
            });
            return map;
        };

        function render() {
            const groups = readGroups();
            groupsOrder = groups.map((group) => group.name);
            const kept = currentValues();
            const list = groups.length ? combos(groups) : [];

            body.innerHTML = '';
            list.forEach((options, index) => {
                const key  = groupsOrder.map((name) => options[name]).join('|');
                const prev = kept[key] || {};
                const seed = findExisting(options) || {};
                const value = (field, fallback) => (prev[field] !== undefined && prev[field] !== ''
                    ? prev[field]
                    : (seed[field] !== undefined && seed[field] !== null ? String(seed[field]) : fallback));

                const tr = document.createElement('tr');
                tr.dataset.combo = key;
                tr.innerHTML =
                    '<td><strong>' + esc(label(options)) + '</strong></td>' +
                    '<td><input type="text" name="variant_sku[]" value="' + esc(value('sku', '')) + '" placeholder="optional"></td>' +
                    '<td class="num"><input type="number" step="0.01" min="0" name="variant_price[]" value="' + esc(value('price', '')) + '" placeholder="inherit"></td>' +
                    '<td class="num"><input type="number" min="0" name="variant_stock[]" value="' + esc(value('stock', '0')) + '"></td>' +
                    '<td><input type="checkbox" name="variant_active[]" value="1" ' + (value('active', '1') ? 'checked' : '') + '></td>';
                // Hidden option map for the server.
                groupsOrder.forEach((name) => {
                    const hidden = document.createElement('input');
                    hidden.type = 'hidden';
                    hidden.name = 'variant_options[' + index + '][' + name + ']';
                    hidden.value = options[name];
                    tr.appendChild(hidden);
                });
                body.appendChild(tr);
            });

            const has = list.length > 0;
            tableWrap.hidden = !has;
            emptyNote.hidden = has;
            if (summary) {
                summary.textContent = has ? list.length + ' combination(s) — leave a price blank to inherit the product price.' : '';
            }
        }

        rowsWrap.addEventListener('click', (event) => {
            if (event.target.closest('[data-remove-row]')) {
                const row = event.target.closest('[data-option-row]');
                if (row) { row.remove(); render(); }
            }
        });
        rowsWrap.addEventListener('input', render);
        rowsWrap.addEventListener('change', render);

        $('[data-add-option]')?.addEventListener('click', () => {
            const row = document.createElement('div');
            row.className = 'option-row';
            row.setAttribute('data-option-row', '');
            row.innerHTML = '<input type="text" name="option_name[]" placeholder="Option name (Size, Colour…)">'
                + '<input type="text" name="option_values[]" placeholder="Values, comma separated">'
                + '<button type="button" class="icon-btn" data-remove-row aria-label="Remove option group">×</button>';
            rowsWrap.appendChild(row);
            row.querySelector('input')?.focus();
            render();
        });

        render();
    }

    /* ---------------------------------------------------- gallery drag-sort */

    const sortable = $('[data-sortable]');
    if (sortable) {
        let dragItem = null;

        const persist = async () => {
            const order = $$('.sortable-item', sortable).map((li) => li.dataset.imageId).join(',');
            const data = await api('products.php', {
                do: 'reorder_images',
                id: sortable.dataset.productId,
                order: order
            });
            if (data.ok) {
                toast(data.message || 'Order saved.');
            } else {
                toast('Could not save the new order.', 'danger');
            }
        };

        sortable.addEventListener('dragstart', (event) => {
            const item = event.target.closest('.sortable-item');
            if (!item) return;
            dragItem = item;
            item.classList.add('is-dragging');
            event.dataTransfer.effectAllowed = 'move';
            try { event.dataTransfer.setData('text/plain', item.dataset.imageId); } catch (err) { /* IE */ }
        });

        sortable.addEventListener('dragend', () => {
            if (dragItem) dragItem.classList.remove('is-dragging');
            dragItem = null;
        });

        sortable.addEventListener('dragover', (event) => {
            event.preventDefault();
            const over = event.target.closest('.sortable-item');
            if (!over || !dragItem || over === dragItem) return;

            const items = $$('.sortable-item', sortable);
            const from = items.indexOf(dragItem);
            const to   = items.indexOf(over);
            if (from < 0 || to < 0) return;

            if (from < to) {
                over.after(dragItem);
            } else {
                over.before(dragItem);
            }
        });

        sortable.addEventListener('drop', (event) => {
            event.preventDefault();
            persist();
        });

        // Touch-friendly fallback: arrows on each tile.
        $$('.sortable-item', sortable).forEach((item) => {
            item.addEventListener('keydown', (event) => {
                if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return;
                event.preventDefault();
                if (event.key === 'ArrowLeft' && item.previousElementSibling) {
                    item.previousElementSibling.before(item);
                } else if (event.key === 'ArrowRight' && item.nextElementSibling) {
                    item.nextElementSibling.after(item);
                }
                persist();
            });
        });
    }

    /* delete a gallery image from the sortable list */
    document.addEventListener('click', (event) => {
        const btn = event.target.closest('[data-delete-image]');
        if (!btn) return;
        event.preventDefault();
        if (!window.confirm('Delete this image?')) return;
        const form = $('#image-delete-form');
        if (!form) return;
        form.querySelector('input[name="image_id"]').value = btn.dataset.deleteImage;
        form.submit();
    });

})();
