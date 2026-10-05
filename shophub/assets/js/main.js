/* ==========================================================================
   ShopHub — storefront behaviour (vanilla JS, no dependencies)
   ========================================================================== */
(function () {
    'use strict';

    const CFG = window.SHOPHUB || {};
    const $  = (sel, root = document) => root.querySelector(sel);
    const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

    /* ------------------------------------------------------------- helpers */

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

    async function api(path, payload) {
        const body = new FormData();
        Object.entries(payload || {}).forEach(([k, v]) => body.append(k, v));
        const res = await fetch(CFG.base + '/' + path, {
            method: 'POST',
            body,
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': CFG.csrf
            }
        });
        try {
            return await res.json();
        } catch (err) {
            return { ok: false, message: 'Something went wrong. Please try again.' };
        }
    }

    function fill(template, values) {
        return template.replace(/\{(\w+)\}/g, (_, key) => (key in values ? values[key] : ''));
    }

    const money = (template, value) => (template ? template.replace('__VALUE__', value) : value);

    function debounce(fn, wait) {
        let timer;
        return (...args) => {
            clearTimeout(timer);
            timer = setTimeout(() => fn(...args), wait);
        };
    }

    /* ---------------------------------------------------------------- cart */

    function paintCart(cart) {
        const badge = $('[data-cart-count]');
        if (badge) {
            badge.textContent = cart.count;
            badge.hidden = cart.count === 0;
        }
        $$('[data-cart-total]').forEach((el) => { el.textContent = cart.total; });
        $$('[data-cart-subtotal]').forEach((el) => { el.textContent = cart.subtotal; });
        $$('[data-cart-discount]').forEach((el) => { el.textContent = cart.discount; });
        $$('[data-cart-shipping]').forEach((el) => { el.textContent = cart.shipping; });
        $$('[data-cart-tax]').forEach((el) => { el.textContent = cart.tax; });
        $$('[data-cart-count-label]').forEach((el) => {
            el.textContent = cart.count + (cart.count === 1 ? ' item' : ' items');
        });
        document.dispatchEvent(new CustomEvent('shophub:cart', { detail: cart }));
    }

    /** Add a product or a specific variant combination to the cart. */
    async function addItem(payload) {
        const data = await api('ajax/cart.php', Object.assign({ action: 'add' }, payload));
        if (data.ok && data.cart) {
            paintCart(data.cart);
        }
        toast(data.message, data.ok ? 'success' : 'danger');
        return data;
    }

    /* Kept for anything calling ShopHub.addToCart(id, qty) */
    function addToCart(productId, qty) {
        return addItem({ product_id: productId, qty: qty || 1 });
    }

    /** Read the intended line out of a buy form — variant id when present. */
    function formPayload(form) {
        const variant  = form.querySelector('input[name="variant_id"]');
        const product  = form.querySelector('input[name="product_id"]');
        const qtyInput = form.querySelector('input[name="qty"]');
        const payload  = { qty: qtyInput ? qtyInput.value : 1 };
        if (variant && variant.value) {
            payload.variant_id = variant.value;
        } else if (product) {
            payload.product_id = product.value;
        }
        return payload;
    }

    document.addEventListener('click', async (event) => {
        /* add-to-cart buttons inside product cards */
        const btn = event.target.closest('[data-add-to-cart]');
        if (btn && btn.tagName === 'BUTTON' && !btn.closest('[data-add-form]')) {
            event.preventDefault();
            if (btn.disabled) return;
            btn.disabled = true;
            const original = btn.textContent;
            btn.textContent = 'Adding...';
            const data = await addItem({ product_id: btn.dataset.addToCart, qty: 1 });
            btn.textContent = original;
            btn.disabled = !data.ok ? false : false;
            if (!data.ok) btn.disabled = true;
            return;
        }

        /* remove a cart line */
        const removeBtn = event.target.closest('[data-remove-line]');
        if (removeBtn) {
            event.preventDefault();
            const row = removeBtn.closest('[data-line]') || removeBtn.closest('.cart-line');
            const data = await api('ajax/cart.php', { action: 'remove', key: removeBtn.dataset.removeLine });
            if (data.ok) {
                if (row) {
                    row.style.transition = 'opacity .25s, transform .25s';
                    row.style.opacity = '0';
                    row.style.transform = 'translateX(-12px)';
                    setTimeout(() => row.remove(), 260);
                }
                paintCart(data.cart);
                if (data.cart.count === 0) {
                    setTimeout(() => window.location.reload(), 500);
                }
            }
            toast(data.message, data.ok ? 'success' : 'danger');
            return;
        }

        /* copy coupon code */
        const copyBtn = event.target.closest('[data-copy-target], [data-copy]');
        if (copyBtn) {
            const code = copyBtn.dataset.copyTarget || copyBtn.dataset.copy;
            try {
                await navigator.clipboard.writeText(code);
                copyBtn.textContent = 'Copied!';
                setTimeout(() => { copyBtn.textContent = 'Copy'; }, 1800);
                toast('Coupon code ' + code + ' copied.');
            } catch (err) {
                toast('Copy failed — code is ' + code, 'warn');
            }
        }
    });

    /* product page buy form */
    $$('[data-add-form]').forEach((form) => {
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const btn = form.querySelector('[data-add-to-cart]');
            if (btn && btn.disabled) return;
            if (btn) { btn.disabled = true; btn.textContent = 'Adding...'; }
            const data = await addItem(formPayload(form));
            if (btn) {
                btn.disabled = !data.ok;
                btn.textContent = btn.disabled ? 'Unavailable' : 'Add to cart';
            }
        });
    });

    $$('[data-buy-now]').forEach((btn) => {
        btn.addEventListener('click', async () => {
            const form = btn.closest('[data-add-form]');
            if (!form) return;
            btn.disabled = true;
            const data = await addItem(formPayload(form));
            if (data.ok) {
                window.location.href = CFG.base + '/checkout.php';
            } else {
                btn.disabled = false;
            }
        });
    });

    /* --------------------------------------------------------- variant picker */

    const variantDataEl = $('[data-variant-data]');
    if (variantDataEl) {
        let variants = [];
        try { variants = JSON.parse(variantDataEl.textContent) || []; } catch (err) { variants = []; }

        const form      = $('[data-variant-form]');
        const picker    = form ? form.querySelector('[data-variant-picker]') : null;
        const idInput   = form ? form.querySelector('[data-variant-id]') : null;
        const priceEl   = $('[data-variant-price]');
        const stockEl   = $('[data-variant-stock]');
        const skuEl     = $('[data-variant-sku]');
        const stockLine = $('[data-variant-stockline]');
        const addBtn    = form ? form.querySelector('[data-add-to-cart]') : null;
        const buyBtn    = form ? form.querySelector('[data-buy-now]') : null;
        const qtyInput  = form ? form.querySelector('input[name="qty"]') : null;
        const galleryMainImg = $('[data-gallery-main]');

        // Whatever the markup starts with is the initial selection.
        const state = {};
        if (picker) {
            $$('[data-option-value]', picker).forEach((btn) => {
                if (btn.classList.contains('is-active')) state[btn.dataset.optionName] = btn.dataset.optionValue;
            });
            $$('[data-option-select]', picker).forEach((sel) => {
                state[sel.dataset.optionName] = sel.value;
            });
        }

        const matches = (variant) => Object.keys(state).every((k) => String(variant.options[k]) === String(state[k]));

        function resolve() {
            Object.keys(state).forEach((name) => {
                const label = picker ? picker.querySelector('[data-option-chosen="' + name + '"]') : null;
                if (label) label.textContent = state[name];
            });

            const variant = variants.find(matches);

            if (!variant) {
                if (stockEl) stockEl.textContent = 'That combination is unavailable — try another.';
                if (stockLine) { stockLine.classList.remove('is-in'); stockLine.classList.add('is-out'); }
                if (idInput) idInput.value = '';
                if (addBtn) { addBtn.disabled = true; addBtn.textContent = 'Unavailable'; }
                if (buyBtn) buyBtn.disabled = true;
                if (skuEl) skuEl.textContent = '';
                return;
            }

            if (idInput) idInput.value = variant.id;
            if (priceEl) priceEl.textContent = variant.price;
            if (skuEl) skuEl.textContent = variant.sku ? 'SKU ' + variant.sku : '';

            const soldOut = variant.stock <= 0;
            if (qtyInput) {
                qtyInput.max = Math.max(1, variant.stock);
                if (parseInt(qtyInput.value, 10) > variant.stock) qtyInput.value = Math.max(1, variant.stock);
            }
            if (stockEl) {
                stockEl.textContent = soldOut
                    ? 'Sold out in this combination'
                    : (variant.stock <= 12 ? 'In stock — only ' + variant.stock + ' left' : 'In stock');
            }
            if (stockLine) {
                stockLine.classList.toggle('is-in', !soldOut);
                stockLine.classList.toggle('is-out', soldOut);
            }
            if (addBtn) { addBtn.disabled = soldOut; addBtn.textContent = soldOut ? 'Sold out' : 'Add to cart'; }
            if (buyBtn) buyBtn.disabled = soldOut;
            if (galleryMainImg && variant.image) galleryMainImg.src = variant.image;
        }

        if (picker) {
            picker.addEventListener('click', (event) => {
                const btn = event.target.closest('[data-option-value]');
                if (!btn) return;
                event.preventDefault();
                const name = btn.dataset.optionName;
                state[name] = btn.dataset.optionValue;
                $$('[data-option-value][data-option-name="' + name + '"]', picker)
                    .forEach((other) => other.classList.toggle('is-active', other === btn));
                resolve();
            });
            picker.addEventListener('change', (event) => {
                const sel = event.target.closest('[data-option-select]');
                if (!sel) return;
                state[sel.dataset.optionName] = sel.value;
                resolve();
            });
        }

        resolve();
    }

    /* quantity steppers */
    $$('[data-qty]').forEach((stepper) => {
        const input = stepper.querySelector('input');
        if (!input) return;
        const clamp = (value) => {
            const min = parseInt(input.min || '1', 10);
            const max = parseInt(input.max || '999', 10);
            return Math.max(min, Math.min(max, value));
        };
        stepper.addEventListener('click', (event) => {
            const up = event.target.closest('[data-qty-up]');
            const down = event.target.closest('[data-qty-down]');
            if (!up && !down) return;
            event.preventDefault();
            input.value = clamp((parseInt(input.value, 10) || 1) + (up ? 1 : -1));
            input.dispatchEvent(new Event('change', { bubbles: true }));
        });
        if (stepper.closest('.cart-line')) {
            input.addEventListener('change', debounce(async () => {
                const line = input.closest('.cart-line');
                const key  = line.dataset.line;
                const data = await api('ajax/cart.php', { action: 'set', key: key, qty: input.value });
                if (data.ok) {
                    const match = (data.cart.lines || []).find((l) => String(l.key) === String(key));
                    const priceEl = line.querySelector('.cart-line-price .price-now');
                    if (match && priceEl) priceEl.textContent = match.subtotal;
                    paintCart(data.cart);
                }
                if (data.message) toast(data.message, data.ok ? 'success' : 'danger');
            }, 400));
        }
    });

    /* ------------------------------------------------------------ wishlist */

    $$('[data-wishlist]').forEach((btn) => {
        btn.addEventListener('click', async (event) => {
            event.preventDefault();
            const data = await api('ajax/wishlist.php', { product_id: btn.dataset.wishlist });
            if (!data.ok) { toast(data.message, 'danger'); return; }
            btn.classList.toggle('is-on', data.added);
            btn.setAttribute('aria-pressed', data.added ? 'true' : 'false');
            const badge = $('[data-wishlist-count]');
            if (badge) { badge.textContent = data.count; badge.hidden = data.count === 0; }
            toast(data.message, 'success');
        });
    });

    /* ------------------------------------------------------------ search UX */

    const searchInput = $('#search-input');
    if (searchInput && searchInput.dataset.suggestUrl) {
        const box = $('[data-suggest]', searchInput.closest('form'));
        const render = (items) => {
            if (!items.length) {
                box.innerHTML = '<div class="suggest-empty">No matches — try another term.</div>';
            } else {
                box.innerHTML = items.map((item) => `
                    <a href="${item.url}">
                        <img src="${item.image}" alt="">
                        <span class="suggest-meta">
                            <strong>${item.name}</strong>
                            <small>${item.category}</small>
                        </span>
                        <span class="suggest-price">${item.price}</span>
                    </a>`).join('');
            }
            box.hidden = false;
        };

        searchInput.addEventListener('input', debounce(async () => {
            const term = searchInput.value.trim();
            if (term.length < 2) { box.hidden = true; return; }
            const res = await fetch(searchInput.dataset.suggestUrl + '?q=' + encodeURIComponent(term));
            const data = await res.json();
            render(data.items || []);
        }, 220));

        document.addEventListener('click', (event) => {
            if (!event.target.closest('form')?.contains(searchInput) && box) {
                box.hidden = true;
            }
        });
        searchInput.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') { box.hidden = true; }
        });
    }

    /* ------------------------------------------------------------- currency */

    $$('[data-currency-switch] .currency-btn').forEach((btn) => {
        btn.addEventListener('click', async () => {
            if (btn.classList.contains('is-active')) return;
            const data = await api('ajax/currency.php', { currency: btn.dataset.currency });
            if (data.ok) {
                window.location.reload();
            } else {
                toast(data.message, 'danger');
            }
        });
    });

    /* -------------------------------------------------------------- account */

    $$('[data-star-input]').forEach((wrap) => {
        const hidden = wrap.querySelector('input[type="hidden"]');
        const stars = $$('.star', wrap);
        stars.forEach((star) => {
            star.addEventListener('click', () => {
                const value = parseInt(star.dataset.value, 10);
                hidden.value = value;
                stars.forEach((s) => s.classList.toggle('is-on', parseInt(s.dataset.value, 10) <= value));
            });
        });
    });

    /* ----------------------------------------------------------- misc UI bits */

    const navToggle = $('[data-nav-toggle]');
    if (navToggle) {
        navToggle.addEventListener('click', () => {
            const nav = $('.main-nav');
            const open = nav.classList.toggle('is-open');
            navToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
    }

    const filtersToggle = $('[data-filters-toggle]');
    if (filtersToggle) {
        filtersToggle.addEventListener('click', () => {
            $('.filters-body').classList.toggle('is-open');
        });
    }

    $$('[data-auto-submit]').forEach((select) => {
        select.addEventListener('change', () => select.form.submit());
    });

    $$('.flash-close').forEach((btn) => {
        btn.addEventListener('click', () => btn.closest('.flash').remove());
    });

    $$('[data-scroll]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const rail = document.getElementById(btn.dataset.scroll);
            if (rail) {
                rail.scrollBy({ left: parseInt(btn.dataset.dir, 10) * Math.min(480, rail.clientWidth * 0.8), behavior: 'smooth' });
            }
        });
    });

    const galleryMain = $('[data-gallery-main]');
    $$('[data-gallery-src]').forEach((thumb) => {
        thumb.addEventListener('click', () => {
            if (!galleryMain) return;
            galleryMain.src = thumb.dataset.gallerySrc;
            $$('.gallery-thumb').forEach((t) => t.classList.remove('is-active'));
            thumb.classList.add('is-active');
        });
    });

    $$('[data-tabs]').forEach((tabs) => {
        tabs.addEventListener('click', (event) => {
            const tab = event.target.closest('[data-tab]');
            if (!tab) return;
            const wrap = tabs.closest('.product-tabs');
            $$('.tab', tabs).forEach((t) => t.classList.toggle('is-active', t === tab));
            $$('[data-panel]', wrap).forEach((panel) => {
                panel.classList.toggle('is-active', panel.dataset.panel === tab.dataset.tab);
            });
        });
    });

    const newsletter = $('[data-newsletter]');
    if (newsletter) {
        newsletter.addEventListener('submit', async (event) => {
            event.preventDefault();
            const note = $('[data-news-note]');
            const data = await api('ajax/newsletter.php', { email: newsletter.email.value });
            if (note) {
                note.textContent = data.message;
                note.style.color = data.ok ? 'var(--ok)' : 'var(--danger)';
            }
            if (data.ok) newsletter.reset();
        });
    }

    /* lazy section reveals */
    const reveals = $$('.section, .product-card, .cat-card');
    if ('IntersectionObserver' in window) {
        const io = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('is-visible');
                    io.unobserve(entry.target);
                }
            });
        }, { rootMargin: '0px 0px -8% 0px', threshold: 0.05 });
        reveals.forEach((el) => { el.classList.add('reveal'); io.observe(el); });
    }

    window.ShopHub = { toast, api, addToCart, addItem, paintCart };
})();
