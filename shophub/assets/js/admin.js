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
            const res = await fetch(CFG.base + '/admin/products.php', {
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
})();
