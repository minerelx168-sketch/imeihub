/* Optional first-party funnel adapter. Loaded only with GA4_MEASUREMENT_ID.
 * No IMEI, account details, report contents, form values or raw queries leave it.
 */
(function (w, d) {
    'use strict';
    var script = d.currentScript;
    var id = script && script.getAttribute('data-measurement-id');
    if (!/^G-[A-Z0-9]+$/.test(id || '')) return;
    var allowed = false, started = false, seen = {}, pending = {};
    var consentKey = 'imeihub.analytics.consent.v1';
    var contextKey = 'imeihub.analytics.context.v1';
    var pendingKey = 'imeihub.analytics.pending.v1';
    var seenKey = 'imeihub.analytics.sent.v1';
    var ttl = 30 * 60 * 1000, orderTtl = 24 * 60 * 60 * 1000;
    var article = d.querySelector('[data-article-slug]');
    var articleSlug = article ? article.getAttribute('data-article-slug') : '';
    function slug(s) { return /^[a-z0-9-]{1,150}$/.test(s || '') ? s : ''; }
    articleSlug = slug(articleSlug);
    function read(key) { try { return JSON.parse(w.sessionStorage.getItem(key) || 'null'); } catch (_) { return null; } }
    function write(key, value) { try { w.sessionStorage.setItem(key, JSON.stringify(value)); } catch (_) {} }
    function consent() { try { return w.localStorage.getItem(consentKey); } catch (_) { return null; } }
    function pageUrl() {
        var url = new URL(w.location.href);
        url.search = ''; url.hash = '';
        if (articleSlug) url.pathname = '/article/' + articleSlug;
        else if (url.pathname === '/service.php') {
            var s = slug(new URL(w.location.href).searchParams.get('slug'));
            if (s) url.searchParams.set('slug', s);
        }
        return url.href;
    }
    function referrer() {
        try { var u = new URL(d.referrer); return u.origin; } catch (_) { return ''; }
    }
    function context() {
        if (!allowed) return null;
        var c = read(contextKey);
        if (!c || Date.now() - c.at > ttl) c = { article_slug: '', at: Date.now() };
        if (articleSlug) c.article_slug = articleSlug;
        c.at = Date.now(); write(contextKey, c);
        return { article_slug: slug(c.article_slug) };
    }
    function event(name, params) {
        if (!allowed) return;
        // Explicit location overrides prevent query-string IMEIs/return tokens leaking.
        w.gtag('event', name, Object.assign({ send_to: id, page_location: pageUrl(),
            page_referrer: referrer(), page_title: articleSlug || w.location.pathname }, params));
    }
    function purchase(result, suppliedContext) {
        if (!allowed || !result || result.ok !== true || String(result.status).toLowerCase() !== 'success') return;
        var order = result.public_id, code = result.service_code, value = Number(result.cost);
        if (!/^[0-9A-HJKMNP-TV-Z]{26}$/.test(order || '') || !/^[A-Z0-9_]{2,32}$/.test(code || '') || !Number.isFinite(value) || value <= 0) return;
        var saved = pending[order];
        // Only a current successful request or a recorded pending request qualifies.
        // Merely opening an old report must never create a new purchase.
        if (!suppliedContext && (!saved || Date.now() - saved.at > orderTtl)) return;
        if (seen[order]) return;
        var c = suppliedContext || saved.context || {};
        event('purchase', { transaction_id: order, currency: 'USD', value: value,
            article_slug: slug(c.article_slug),
            items: [{ item_id: code, item_name: code, item_category: 'IMEI report', price: value, quantity: 1 }] });
        seen[order] = Date.now(); delete pending[order];
        write(seenKey, seen); write(pendingKey, pending);
    }
    function start() {
        allowed = true;
        if (started) { w.gtag('consent', 'update', { analytics_storage: 'granted' }); return; }
        started = true;
        pending = read(pendingKey) || {}; seen = read(seenKey) || {};
        Object.keys(pending).forEach(function (key) { if (Date.now() - pending[key].at > orderTtl) delete pending[key]; });
        Object.keys(seen).forEach(function (key) { if (Date.now() - seen[key] > orderTtl) delete seen[key]; });
        w.dataLayer = w.dataLayer || [];
        w.gtag = w.gtag || function () { w.dataLayer.push(arguments); };
        w.gtag('consent', 'default', { analytics_storage: 'denied', ad_storage: 'denied', ad_user_data: 'denied', ad_personalization: 'denied' });
        w.gtag('consent', 'update', { analytics_storage: 'granted' });
        w.gtag('js', new Date());
        w.gtag('config', id, { send_page_view: false, allow_google_signals: false,
            allow_ad_personalization_signals: false, page_location: pageUrl(),
            page_referrer: referrer(), page_title: articleSlug || w.location.pathname });
        var tag = d.createElement('script'); tag.async = true;
        tag.src = 'https://www.googletagmanager.com/gtag/js?id=' + id;
        d.head.appendChild(tag);
        context();
        event('page_view', {});
        if (articleSlug) event('article_view', { article_slug: articleSlug });
    }
    var box = d.createElement('div');
    box.setAttribute('role', 'region'); box.setAttribute('aria-label', 'Analytics preferences');
    box.style.cssText = 'position:fixed;bottom:16px;left:16px;right:16px;max-width:600px;background:#fff;color:#172033;padding:16px;border:1px solid #bbb;border-radius:8px;z-index:10000;box-shadow:0 2px 12px #0002';
    var text = d.createElement('p'); text.textContent = 'Allow optional analytics to help us understand which guides and services are useful? Your IMEI and report contents are not included.';
    box.appendChild(text);
    function choose(yes) {
        try { w.localStorage.setItem(consentKey, yes ? 'granted' : 'denied'); } catch (_) {}
        box.hidden = true;
        if (yes) start();
        else {
            allowed = false;
            if (w.gtag) w.gtag('consent', 'update', { analytics_storage: 'denied' });
            [contextKey, pendingKey, seenKey].forEach(function (k) { try { w.sessionStorage.removeItem(k); } catch (_) {} });
            pending = {}; seen = {};
        }
    }
    ['Allow analytics', 'No thanks'].forEach(function (label, i) {
        var button = d.createElement('button'); button.type = 'button'; button.textContent = label;
        button.style.cssText = 'padding:8px 12px;margin-right:8px;cursor:pointer';
        button.addEventListener('click', function () { choose(i === 0); }); box.appendChild(button);
    });
    d.body.appendChild(box);
    var settings = d.createElement('button'); settings.type = 'button'; settings.textContent = 'Analytics preferences';
    settings.addEventListener('click', function () { box.hidden = false; });
    (d.querySelector('.site-footer') || d.body).appendChild(settings);
    box.hidden = consent() !== null;
    if (consent() === 'granted') start();
    d.addEventListener('click', function (e) {
        if (!allowed || !articleSlug) return;
        var a = e.target.closest && e.target.closest('.article-page a[href]');
        if (!a) return;
        var url = new URL(a.href, w.location.href);
        if (url.origin !== w.location.origin) return;
        var service = '';
        if (url.pathname === '/service.php') service = slug(url.searchParams.get('slug'));
        else if (url.pathname === '/services.php') service = 'service-catalog';
        else if (url.pathname === '/check.php') service = 'paid-check-selector';
        else if (url.pathname === '/') service = 'free-imei-check';
        if (!service) return;
        context();
        event('service_cta_click', { article_slug: articleSlug, service_slug: service,
            cta_location: a.closest('.article-cta') ? 'article_footer' : 'article_body' });
    });
    // Analytics failures must never interrupt the paid report interface.
    function safe(fn) { return function () { try { return fn.apply(null, arguments); } catch (_) { return null; } }; }
    w.imeihubAnalytics = { context: safe(context), purchase: safe(purchase), pending: safe(function (order, c) {
        if (!allowed || !/^[0-9A-HJKMNP-TV-Z]{26}$/.test(order || '')) return;
        pending[order] = { context: c || {}, at: Date.now() }; write(pendingKey, pending);
    }) };
})(window, document);
