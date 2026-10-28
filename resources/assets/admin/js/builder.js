/*
 * Visual page builder (Admin › Pages › Design page, PageDesignController@edit): GrapesJS with the shop's own CSS in
 * the canvas and a plain-language UI for people who don't write code.
 *   - left: blocks to drag in (ready-made sections, layout, basics, shop) and the page's layers
 *   - middle: the page as customers see it, at computer / tablet / phone width
 *   - right: settings and style for whatever is clicked (brand colour swatches, sizes in words, not pixels)
 * Config (#pb-config): project | starter, styles, saveUrl, pageUrl, editUrl, status, palette, productSources,
 * categories, icons, mapAddress. Saving sends { project (JSON), html, css, publish } with Admin.fetch; the server
 * cleans the HTML and scopes the CSS (Content\PageDesign). Pictures come from the media library (Admin.pickImage),
 * links from the link search (Admin.pickLink).
 */
(function () {
    'use strict';

    function start() {
        var root = document.getElementById('pb-builder');
        if (!root || !window.grapesjs || !window.Admin) {
            return;
        }
        var cfg = JSON.parse(document.getElementById('pb-config').textContent);
        var $ = function (sel) { return root.querySelector(sel); };
        var $$ = function (sel) { return Array.prototype.slice.call(root.querySelectorAll(sel)); };
        var icon = function (name) { return cfg.icons[name] || ''; };
        var esc = function (s) {
            return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
        };

        // ------------------------------------------------------------------------------------------- content

        // placeholder picture until one is chosen (a data: SVG, which the server keeps for <img>). No words on it:
        // customers see it too if the page is saved before a picture is chosen.
        var PICTURE = 'data:image/svg+xml;base64,' + btoa('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1200 800">'
            + '<rect width="1200" height="800" fill="#e5e7eb"/><path d="M430 540l150-170 110 125 70-80 150 125H430z" fill="#cbd5e1"/>'
            + '<circle cx="760" cy="330" r="46" fill="#cbd5e1"/></svg>');
        var img = function (cls) { return '<img class="pb-image ' + (cls || 'pb-image--full') + '" src="' + PICTURE + '" alt="">'; };
        var featureIcon = function (name) { return '<span class="pb-icon">' + icon(name) + '</span>'; };
        var section = function (name, cls, inner, container) {
            return '<section class="pb-section' + (cls ? ' ' + cls : '') + '" data-gjs-name="' + name + '"><div class="pb-container'
                + (container ? ' ' + container : '') + '">' + inner + '</div></section>';
        };
        var cols = function (list, rowCls) {
            return '<div class="pb-row' + (rowCls ? ' ' + rowCls : '') + '" data-gjs-name="Columns">' + list.map(function (c) {
                return '<div class="pb-col' + (c.cls ? ' ' + c.cls : '') + '" data-gjs-name="Column">' + (c.html || c) + '</div>';
            }).join('') + '</div>';
        };
        var button = function (label, href, variant) {
            return '<a class="pb-btn' + (variant ? ' ' + variant : '') + '" href="' + href + '" data-gjs-name="Button">' + label + '</a>';
        };
        var buttons = function (html) { return '<div class="pb-buttons" data-gjs-name="Buttons">' + html + '</div>'; };
        var feature = function (name, title, text) {
            return { cls: 'pb-card pb-center', html: featureIcon(name) + '<h3>' + title + '</h3><p>' + text + '</p>' };
        };

        var S = {
            banner: section('Banner', 'pb-hero', '<h1>Say what makes you special</h1><p class="pb-lead">One or two sentences that tell customers why they’ll love shopping with you.</p>'
                + buttons(button('Shop now', '/shop/') + button('Find out more', '#', 'pb-btn--outline')), 'pb-center'),
            pictureText: section('Picture and text', '', cols([img(), '<h2>Our story</h2><p>Tell customers who you are, what you sell and why. Double-click any text to change it.</p>'
                + buttons(button('Find out more', '#', 'pb-btn--outline'))], 'pb-row--middle')),
            textPicture: section('Text and picture', '', cols(['<h2>Made to fit</h2><p>Describe a product range, a service or a promise. Add a button so customers know where to go next.</p>'
                + buttons(button('See the range', '/shop/')), img()], 'pb-row--middle pb-row--reverse-phone')),
            features: section('Three highlights', 'pb-section--tint', '<h2 class="pb-center">Why shop with us</h2>' + cols([
                feature('truck', 'Fast delivery', 'Say how quickly orders arrive.'),
                feature('arrow-path', 'Easy returns', 'Explain your returns promise.'),
                feature('chat-bubble-left-ellipsis', 'Friendly help', 'Tell customers how to reach you.'),
            ])),
            cta: section('Call to action', 'pb-section--brand', '<h2>Ready to find your perfect fit?</h2><p class="pb-lead">A short line that gets customers clicking.</p>'
                + buttons(button('Shop now', '/shop/', 'pb-btn--light')), 'pb-center pb-container--narrow'),
            testimonial: section('Customer review', '', '<div class="pb-stars">★★★★★</div><blockquote class="pb-quote"><p>“Great service and a perfect fit. I’ll definitely be back.”</p>'
                + '<footer>Customer name</footer></blockquote>', 'pb-center pb-container--narrow'),
            faq: section('Questions and answers', '', '<h2>Questions and answers</h2><div class="pb-faq" data-gjs-name="Questions">'
                + ['How long does delivery take?', 'Can I return something?', 'How do I find my size?'].map(function (q, i) {
                    return '<details' + (i === 0 ? ' open' : '') + ' data-gjs-name="Question"><summary>' + q + '</summary><p>Write the answer here.</p></details>';
                }).join('') + '</div>', 'pb-container--narrow'),
            gallery: section('Picture gallery', '', cols([img(), img(), img()])),
            text: section('Text section', '', '<h2>A heading</h2><p>Write as much as you like here. Double-click to type, and use the bar that appears to make words bold or add a link.</p>', 'pb-container--narrow'),
            products: section('Products', '', '<h2 class="pb-center">Best sellers</h2>'
                + '<div class="pb-products" data-pb="products" data-source="best-sellers" data-limit="8" data-columns="4"></div>'
                + '<div class="pb-buttons pb-center" style="justify-content:center">' + button('See everything', '/shop/', 'pb-btn--outline') + '</div>'),
            contact: section('Contact', 'pb-section--tint', cols([
                '<h2>Get in touch</h2><p>Add your address, phone number and opening hours here.</p>',
                { cls: 'pb-card', html: '<div class="pb-contact" data-pb="contact-form"></div>' },
            ])),
        };

        var TEMPLATES = {
            story: S.banner + S.pictureText + S.features + S.testimonial + S.cta,
            promo: S.banner + S.products + S.textPicture + S.faq + S.cta,
            blank: '',
        };

        // ------------------------------------------------------------------------------------------- helpers

        var embedUrl = {
            video: function (url) {
                url = String(url || '').trim();
                var m = url.match(/(?:youtube\.com\/(?:watch\?(?:.*&)?v=|embed\/|shorts\/|live\/)|youtu\.be\/)([\w-]{11})/i);
                if (m) return 'https://www.youtube-nocookie.com/embed/' + m[1];
                m = url.match(/vimeo\.com\/(?:video\/)?(\d+)/i);
                return m ? 'https://player.vimeo.com/video/' + m[1] : '';
            },
            map: function (address) {
                address = String(address || '').trim();
                return address ? 'https://maps.google.com/maps?q=' + encodeURIComponent(address) + '&z=15&output=embed' : '';
            },
        };

        function pickPicture(editor, component) {
            editor.runCommand('core:open-assets', { target: component });
        }

        // a select trait that swaps one class from a set (section background, button style)
        function classChoice(model, prop, choices) {
            var current = choices.filter(function (c) { return c && model.getClasses().indexOf(c) !== -1; })[0] || '';
            model.set(prop, current, { silent: true });
            model.on('change:' + prop, function () {
                model.removeClass(choices.filter(Boolean));
                if (model.get(prop)) model.addClass(model.get(prop));
            });
        }

        // ------------------------------------------------------------------------------------------- types

        function shopPlugin(editor) {
            var dc = editor.Components;
            var attr = function (el, name) { return el && el.getAttribute ? el.getAttribute(name) : null; };
            var hasClass = function (el, cls) { return !!(el && el.classList && el.classList.contains(cls)); };

            // generic boxes and text: nothing to set but their style (no id / title fields)
            ['default', 'text'].forEach(function (type) { dc.addType(type, { model: { defaults: { traits: [] } } }); });

            editor.Traits.addType('pb-link', {
                createInput: function () {
                    var el = document.createElement('div');
                    el.className = 'pb-trait-link';
                    el.innerHTML = '<input type="text" placeholder="/shop/ or https://…"><button type="button" class="btn btn--sm">Choose…</button>';
                    return el;
                },
                onEvent: function (o) {
                    o.component.addAttributes({ href: o.elInput.querySelector('input').value.trim() });
                },
                onUpdate: function (o) {
                    o.elInput.querySelector('input').value = o.component.getAttributes().href || '';
                    o.elInput.querySelector('button').onclick = function () {
                        Admin.pickLink(function (url) {
                            o.elInput.querySelector('input').value = url;
                            o.component.addAttributes({ href: url });
                        }, o.component.getAttributes().href || '');
                    };
                },
            });
            var linkTraits = [
                { type: 'pb-link', name: 'href', label: 'Goes to' },
                { type: 'checkbox', name: 'target', label: 'Open in a new tab', valueTrue: '_blank', valueFalse: '' },
            ];
            dc.addType('link', { model: { defaults: { traits: linkTraits } } });

            dc.addType('pb-button', {
                extend: 'link',
                isComponent: function (el) { return el.tagName === 'A' && hasClass(el, 'pb-btn'); },
                model: {
                    defaults: {
                        name: 'Button',
                        traits: linkTraits.concat([{ type: 'select', name: 'pb-variant', label: 'Style', changeProp: true, options: [
                            { id: '', label: 'Brand colour' }, { id: 'pb-btn--accent', label: 'Accent colour' },
                            { id: 'pb-btn--outline', label: 'Outline' }, { id: 'pb-btn--light', label: 'White' },
                        ] }]),
                    },
                    init: function () { classChoice(this, 'pb-variant', ['pb-btn--accent', 'pb-btn--outline', 'pb-btn--light']); },
                },
            });

            dc.addType('image', {
                model: { defaults: { traits: [
                    { name: 'alt', label: 'Describe the picture', placeholder: 'e.g. Navy wool suit' },
                    { type: 'button', name: 'pb-change-picture', text: 'Change picture', full: true, command: function (ed) { pickPicture(ed, ed.getSelected()); } },
                ] } },
            });

            var backgroundTraits = [
                { type: 'select', name: 'pb-background', label: 'Background', changeProp: true, options: [
                    { id: '', label: 'None' }, { id: 'pb-section--tint', label: 'Light panel' },
                    { id: 'pb-section--brand', label: 'Brand colour' }, { id: 'pb-section--dark', label: 'Dark' },
                ] },
                { type: 'button', name: 'pb-pick-background', text: 'Choose a background picture', full: true, command: function (ed) {
                    var target = ed.getSelected();
                    Admin.pickImage(function (m) { target.addStyle({ 'background-image': 'url("' + m.url + '")' }); });
                } },
                { type: 'button', name: 'pb-clear-background', text: 'Remove the background picture', full: true, command: function (ed) {
                    ed.getSelected().removeStyle('background-image');
                } },
            ];
            dc.addType('pb-section', {
                isComponent: function (el) { return hasClass(el, 'pb-section'); },
                model: {
                    defaults: { name: 'Section', traits: backgroundTraits },
                    init: function () { classChoice(this, 'pb-background', ['pb-section--tint', 'pb-section--brand', 'pb-section--dark']); },
                },
            });
            dc.addType('pb-hero', {
                extend: 'pb-section',
                isComponent: function (el) { return hasClass(el, 'pb-hero'); },
                model: {
                    defaults: { name: 'Banner', traits: backgroundTraits.concat([{ type: 'select', name: 'pb-shade', label: 'Shade over the picture', changeProp: true, options: [
                        { id: '0', label: 'None' }, { id: '.25', label: 'Light' }, { id: '.45', label: 'Medium' }, { id: '.65', label: 'Strong' },
                    ] }]) },
                    init: function () {
                        var model = this;
                        model.set('pb-shade', (model.getStyle() || {})['--pb-overlay'] || '.45', { silent: true });
                        model.on('change:pb-shade', function () { model.addStyle({ '--pb-overlay': model.get('pb-shade') }); });
                    },
                },
            });
            dc.addType('pb-row', { isComponent: function (el) { return hasClass(el, 'pb-row'); }, model: { defaults: { name: 'Columns' } } });
            dc.addType('pb-col', { isComponent: function (el) { return hasClass(el, 'pb-col'); }, model: { defaults: { name: 'Column' } } });

            // shop blocks: placeholders in the builder, filled in on the live page
            var placeholder = function (name, render, traits, attributes) {
                return {
                    model: { defaults: { name: name, droppable: false, traits: traits || [], attributes: attributes || {} } },
                    view: {
                        init: function () { this.listenTo(this.model, 'change:attributes', this.preview); },
                        onRender: function () { this.preview(); },
                        preview: function () { this.el.innerHTML = render(this.model.getAttributes()); },
                    },
                };
            };
            var sources = Object.keys(cfg.productSources).map(function (k) { return { id: k, label: cfg.productSources[k] }; });
            var categories = [{ id: '', label: 'Choose a category' }].concat(Object.keys(cfg.categories).map(function (id) {
                return { id: String(id), label: cfg.categories[id] };
            }).sort(function (a, b) { return a.label.localeCompare(b.label); }));
            dc.addType('pb-products', Object.assign({ isComponent: function (el) { return attr(el, 'data-pb') === 'products'; } }, placeholder('Products', function (a) {
                var count = Math.min(parseInt(a['data-limit'], 10) || 8, 8);
                var columns = parseInt(a['data-columns'], 10) || 4;
                var label = cfg.productSources[a['data-source']] || 'Best sellers';
                if (a['data-source'] === 'category') label = cfg.categories[a['data-category']] || 'Choose a category in Settings';
                var tiles = '';
                for (var i = 0; i < count; i++) tiles += '<span class="pb-ph__tile"><span></span><i></i><i></i></span>';
                return '<div class="pb-ph"><div class="pb-ph__label">' + icon('shopping-bag') + '<span>' + esc(label) + ' · ' + esc(a['data-limit'] || 8)
                    + ' products</span><small>The real products show on the page</small></div><div class="pb-ph__grid" style="--cols:' + columns + '">' + tiles + '</div></div>';
            }, [
                { type: 'select', name: 'data-source', label: 'Show', options: sources },
                { type: 'select', name: 'data-category', label: 'Category (for “From a category”)', options: categories },
                { type: 'select', name: 'data-limit', label: 'How many', options: [4, 8, 12, 16, 24].map(function (n) { return { id: String(n), label: n + ' products' }; }) },
                { type: 'select', name: 'data-columns', label: 'Per row on a computer', options: [2, 3, 4].map(function (n) { return { id: String(n), label: String(n) }; }) },
            ], { 'data-pb': 'products', 'data-source': 'best-sellers', 'data-limit': '8', 'data-columns': '4' })));
            dc.addType('pb-contact', Object.assign({ isComponent: function (el) { return attr(el, 'data-pb') === 'contact-form'; } }, placeholder('Contact form', function () {
                return '<div class="pb-ph pb-ph--form"><div class="pb-ph__label">' + icon('envelope') + '<span>Contact form</span><small>Name, email and message – the real form shows on the page</small></div>'
                    + '<span class="pb-ph__field"></span><span class="pb-ph__field"></span><span class="pb-ph__field pb-ph__field--tall"></span><span class="pb-ph__btn"></span></div>';
            }, [], { 'data-pb': 'contact-form' })));

            // video and map: a box holding the embed, so it can be clicked and dragged (the iframe itself can't)
            dc.addType('pb-frame', {
                isComponent: function (el) { return el.tagName === 'IFRAME'; },
                model: { defaults: { name: 'Embed', selectable: false, hoverable: false, draggable: false, droppable: false, layerable: false,
                    highlightable: false, copyable: false, badgable: false, traits: [] } },
            });
            var embed = function (kind, name, traitName, label, placeholderText) {
                return {
                    isComponent: function (el) { return attr(el, 'data-pb') === kind; },
                    model: {
                        defaults: { name: name, droppable: false, traits: [{ name: traitName, label: label, placeholder: placeholderText, changeProp: false }] },
                        init: function () {
                            this.on('change:attributes:' + traitName, function () {
                                var src = embedUrl[kind](this.getAttributes()[traitName]);
                                var frame = this.components().at(0);
                                if (!frame) return;
                                if (src) frame.addAttributes({ src: src }); else frame.removeAttributes('src');
                            });
                        },
                    },
                };
            };
            dc.addType('pb-video', embed('video', 'Video', 'data-url', 'YouTube or Vimeo link', 'https://www.youtube.com/watch?v=…'));
            dc.addType('pb-map', embed('map', 'Map', 'data-address', 'Address or postcode', 'e.g. 1 High Street, Chester'));

            // pictures come from the media library (GrapesJS opens its asset manager under both names)
            var openAssets = {
                run: function (ed, sender, opts) {
                    opts = opts || {};
                    Admin.pickImage(function (m) {
                        if (typeof opts.select === 'function') {
                            opts.select({ getSrc: function () { return m.url; }, get: function (k) { return k === 'src' ? m.url : undefined; } }, true);
                            return;
                        }
                        var target = opts.target || ed.getSelected();
                        if (target && target.is('image')) {
                            target.set('src', m.url);
                            if (m.alt && !target.getAttributes().alt) target.addAttributes({ alt: m.alt });
                        }
                    });
                },
            };
            editor.Commands.add('open-assets', openAssets);
            editor.Commands.add('core:open-assets', openAssets);

            // brand colour swatches instead of a colour wheel (theme colours follow Admin › Settings › Theme)
            editor.StyleManager.addType('pb-color', {
                create: function (o) {
                    var el = document.createElement('div');
                    el.className = 'pb-swatches';
                    el.innerHTML = cfg.palette.map(function (c) {
                        return '<button type="button" class="pb-swatch" data-value="' + esc(c.value) + '" title="' + esc(c.label) + '" style="--sw:' + esc(c.hex) + '"></button>';
                    }).join('') + '<label class="pb-swatch pb-swatch--custom" title="Another colour"><input type="color" value="#888888"></label>'
                        + '<button type="button" class="pb-swatch pb-swatch--none" data-value="" title="No colour"></button>';
                    el.addEventListener('click', function (ev) {
                        var b = ev.target.closest('button[data-value]');
                        if (b) o.change({ event: { target: { value: b.getAttribute('data-value') } } });
                    });
                    var input = el.querySelector('input');
                    input.addEventListener('input', function (ev) { o.change({ event: ev, partial: true }); });
                    input.addEventListener('change', function (ev) { o.change({ event: ev }); });
                    return el;
                },
                emit: function (o, e) { o.updateStyle(e.event.target.value, { partial: !!e.partial }); },
                update: function (o) {
                    var value = String(o.value || '').toLowerCase();
                    o.el.querySelectorAll('button[data-value]').forEach(function (b) { b.classList.toggle('is-active', b.getAttribute('data-value').toLowerCase() === value); });
                    o.el.querySelector('.pb-swatch--custom').classList.toggle('is-active', /^#[0-9a-f]{6}$/.test(value) && !cfg.palette.some(function (c) { return c.value.toLowerCase() === value; }));
                    if (/^#[0-9a-f]{6}$/.test(value)) o.el.querySelector('input').value = value;
                },
                destroy: function () {},
            });
        }

        // ------------------------------------------------------------------------------------------- blocks

        var blocks = [
            ['banner', 'Banner', 'Sections', 'photo', S.banner],
            ['picture-text', 'Picture and text', 'Sections', 'rectangle-group', S.pictureText],
            ['text-picture', 'Text and picture', 'Sections', 'rectangle-group', S.textPicture],
            ['features', 'Three highlights', 'Sections', 'squares-2x2', S.features],
            ['cta', 'Call to action', 'Sections', 'megaphone', S.cta],
            ['testimonial', 'Customer review', 'Sections', 'chat-bubble-left-right', S.testimonial],
            ['faq', 'Questions and answers', 'Sections', 'question-mark-circle', S.faq],
            ['gallery', 'Picture gallery', 'Sections', 'squares-2x2', S.gallery],
            ['text-section', 'Text section', 'Sections', 'document-text', S.text],
            ['products-section', 'Products', 'Sections', 'shopping-bag', S.products],
            ['contact-section', 'Contact', 'Sections', 'envelope', S.contact],

            ['section', 'Empty section', 'Layout', 'rectangle-stack', section('Section', '', '')],
            ['columns-2', 'Two columns', 'Layout', 'view-columns', cols(['', ''])],
            ['columns-3', 'Three columns', 'Layout', 'view-columns', cols(['', '', ''])],
            ['columns-wide', 'Wide and narrow', 'Layout', 'view-columns', cols([{ cls: 'pb-col--wide', html: '' }, ''])],
            ['spacer', 'Space', 'Layout', 'arrows-up-down', '<div class="pb-spacer" data-gjs-name="Space"></div>'],
            ['divider', 'Line', 'Layout', 'minus', '<hr class="pb-divider" data-gjs-name="Line">'],

            ['heading', 'Heading', 'Basics', 'h1', '<h2>A heading</h2>'],
            ['text', 'Text', 'Basics', 'bars-3-bottom-left', '<p>Double-click to write your text.</p>'],
            ['list', 'List', 'Basics', 'list-bullet', '<ul data-gjs-name="List"><li>First point</li><li>Second point</li><li>Third point</li></ul>'],
            ['button', 'Button', 'Basics', 'cursor-arrow-rays', buttons(button('Click here', '/shop/'))],
            ['picture', 'Picture', 'Basics', 'photo', { type: 'image', src: PICTURE, classes: ['pb-image', 'pb-image--full'], attributes: { alt: '' }, activate: true }],
            ['quote', 'Quote', 'Basics', 'chat-bubble-left-right', '<blockquote class="pb-quote" data-gjs-name="Quote"><p>“A quote from a happy customer.”</p><footer>Their name</footer></blockquote>'],
            ['video', 'Video', 'Basics', 'play-circle', '<div class="pb-embed" data-pb="video" data-url="" data-empty="Paste a YouTube or Vimeo link in Settings on the right"><iframe class="pb-video" title="Video" allow="autoplay; encrypted-media; picture-in-picture" allowfullscreen></iframe></div>'],
            ['map', 'Map', 'Basics', 'map', '<div class="pb-embed" data-pb="map" data-address="' + esc(cfg.mapAddress || '') + '" data-empty="Type an address in Settings on the right"><iframe class="pb-map" title="Map"'
                + (embedUrl.map(cfg.mapAddress) ? ' src="' + esc(embedUrl.map(cfg.mapAddress)) + '"' : '') + '></iframe></div>'],

            ['products', 'Products', 'Shop', 'shopping-bag', '<div class="pb-products" data-pb="products" data-source="best-sellers" data-limit="8" data-columns="4"></div>'],
            ['contact-form', 'Contact form', 'Shop', 'envelope', '<div class="pb-contact" data-pb="contact-form"></div>'],
        ];

        // ------------------------------------------------------------------------------------------- editor

        var size = function (label, value) { return { id: value, label: label }; };
        var editor = grapesjs.init({
            container: '#pb-canvas',
            height: '100%',
            width: 'auto',
            fromElement: false,
            storageManager: false,
            noticeOnUnload: false,
            protectedCss: '',
            plugins: [shopPlugin],
            projectData: cfg.project || { pages: [{ component: cfg.starter || '' }] },
            canvas: { styles: cfg.styles },
            canvasCss: [
                'body{min-height:100vh}',
                '.pb-section:empty,.pb-container:empty,.pb-col:empty{min-height:96px;outline:2px dashed #cbd5e1;outline-offset:-4px}',
                '.pb-container:empty::after,.pb-col:empty::after{content:"Drag blocks here";display:grid;place-items:center;min-height:96px;color:#94a3b8;font:500 14px system-ui,sans-serif}',
                '.pb-spacer{outline:1px dashed #e2e8f0}',
                '.pb-embed{position:relative}.pb-embed iframe{pointer-events:none}',
                '.pb-embed:has(iframe:not([src]))::before{content:attr(data-empty);display:grid;place-items:center;min-height:220px;padding:16px;border:2px dashed #cbd5e1;border-radius:8px;background:#f8fafc;color:#475569;font:500 15px system-ui,sans-serif;text-align:center}',
                '.pb-embed:has(iframe:not([src])) iframe{display:none}',
                '.pb-ph{padding:20px;border:2px dashed #cbd5e1;border-radius:12px;background:rgba(248,250,252,.92);color:#334155;font:14px system-ui,sans-serif}',
                '.pb-ph__label{display:flex;align-items:center;flex-wrap:wrap;gap:4px 10px;margin-bottom:14px;font-weight:600}',
                '.pb-ph__label svg{width:22px;height:22px}.pb-ph__label small{flex-basis:100%;font-weight:400;color:#64748b}',
                '.pb-ph__grid{display:grid;grid-template-columns:repeat(var(--cols),minmax(0,1fr));gap:14px}',
                '.pb-ph__tile{display:flex;flex-direction:column;gap:6px}.pb-ph__tile span{aspect-ratio:1;border-radius:8px;background:#e2e8f0}',
                '.pb-ph__tile i{height:10px;border-radius:4px;background:#e2e8f0}.pb-ph__tile i+i{width:50%}',
                '.pb-ph__field{display:block;height:40px;margin-bottom:10px;border-radius:6px;background:#e2e8f0}.pb-ph__field--tall{height:110px}',
                '.pb-ph__btn{display:block;width:120px;height:40px;border-radius:6px;background:#94a3b8}',
            ].join(''),
            selectorManager: { componentFirst: true },
            deviceManager: { devices: [
                { id: 'desktop', name: 'Computer', width: '' },
                { id: 'tablet', name: 'Tablet', width: '820px', widthMedia: '992px' },
                { id: 'phone', name: 'Phone', width: '390px', widthMedia: '767px' },
            ] },
            panels: { defaults: [] },
            blockManager: { appendTo: '#pb-blocks', blocks: blocks.map(function (b) {
                return { id: b[0], label: b[1], category: b[2], media: icon(b[3]), content: b[4], activate: b[0] === 'picture' };
            }) },
            layerManager: { appendTo: '#pb-layers' },
            traitManager: { appendTo: '#pb-traits' },
            richTextEditor: { actions: ['bold', 'italic', 'underline', 'link'] },
            styleManager: {
                appendTo: '#pb-styles',
                sectors: [
                    { id: 'text', name: 'Text', open: true, properties: [
                        { property: 'font-size', type: 'select', label: 'Size', default: '', options: [
                            size('Normal', ''), size('Small', '0.875rem'), size('Large', '1.25rem'), size('Extra large', '1.75rem'), size('Huge', '2.5rem'), size('Giant', '3.5rem'),
                        ] },
                        { property: 'font-weight', type: 'select', label: 'Weight', default: '', options: [size('Normal', ''), size('Light', '300'), size('Bold', '700'), size('Extra bold', '800')] },
                        { property: 'text-align', type: 'radio', label: 'Line up', default: '', options: [size('Left', 'left'), size('Centre', 'center'), size('Right', 'right')] },
                        { property: 'color', type: 'pb-color', label: 'Colour', default: '' },
                    ] },
                    { id: 'background', name: 'Background', open: false, properties: [
                        { property: 'background-color', type: 'pb-color', label: 'Colour', default: '' },
                    ] },
                    { id: 'spacing', name: 'Spacing', open: false, properties: [
                        { property: 'padding', type: 'select', label: 'Space inside', default: '', options: [
                            size('Standard', ''), size('None', '0'), size('Small', '12px'), size('Medium', '24px'), size('Large', '48px 24px'), size('Extra large', '96px 24px'),
                        ] },
                        { property: 'margin-bottom', type: 'select', label: 'Space below', default: '', options: [
                            size('Standard', ''), size('None', '0'), size('Small', '12px'), size('Medium', '24px'), size('Large', '48px'), size('Extra large', '96px'),
                        ] },
                    ] },
                    { id: 'shape', name: 'Size and shape', open: false, properties: [
                        { property: 'max-width', type: 'select', label: 'Width', default: '', options: [
                            size('Full', ''), size('Small', '320px'), size('Medium', '560px'), size('Large', '760px'), size('Half', '50%'),
                        ] },
                        { property: 'border-radius', type: 'select', label: 'Corners', default: '', options: [
                            size('Standard', ''), size('Square', '0'), size('Slightly rounded', '6px'), size('Rounded', '16px'), size('Round', '999px'),
                        ] },
                        { property: 'box-shadow', type: 'select', label: 'Shadow', default: '', options: [
                            size('None', ''), size('Soft', '0 2px 10px rgba(0,0,0,.12)'), size('Strong', '0 14px 36px rgba(0,0,0,.22)'),
                        ] },
                    ] },
                    { id: 'more', name: 'More options', open: false, properties: ['margin', 'width', 'min-height', 'line-height', 'letter-spacing', 'border', 'opacity'] },
                ],
            },
        });

        // the page's root (GrapesJS replaces it while loading the project, so always ask for the current one)
        var wrapper = function () { return editor.getWrapper(); };
        var um = editor.UndoManager;

        // ------------------------------------------------------------------------------------------- chrome

        // unsaved = GrapesJS counted a change, the undo history moved since the last save (a new picture isn't counted),
        // or a starter layout was chosen
        var state = { status: cfg.status, saving: false, changed: false, savedAt: 0 };
        var dirty = function () { return state.changed || editor.getDirtyCount() > 0 || um.getPointer() !== state.savedAt; };
        var markSaved = function () { editor.clearDirtyCount(); state.changed = false; state.savedAt = um.getPointer(); };

        function refreshBar() {
            var saved = $('[data-pb-saved]');
            saved.textContent = state.saving ? 'Saving…' : (dirty() ? 'Unsaved changes' : 'All changes saved');
            saved.classList.toggle('is-dirty', dirty() && !state.saving);
            $('[data-pb-status]').textContent = state.status === 'published' ? 'Published' : 'Draft';
            $('[data-pb-status]').classList.toggle('is-draft', state.status !== 'published');
            $('[data-pb-publish]').hidden = state.status === 'published';
            $('[data-pb-undo]').disabled = !um.hasUndo();
            $('[data-pb-redo]').disabled = !um.hasRedo();
        }

        function save(publish) {
            if (state.saving) return;
            state.saving = true;
            refreshBar();
            // styles set on the whole page are written for the page root's id, which the saved HTML doesn't contain:
            // write them for body, which the server turns into .pb-page (the element the design is printed in)
            var rootId = new RegExp('#' + wrapper().getId().replace(/[^\w-]/g, '\\$&') + '(?![\\w-])', 'g');
            var data = {
                project: JSON.stringify(editor.getProjectData()),
                html: wrapper().getInnerHTML(),
                css: (editor.getCss({ avoidProtected: true }) || '').replace(rootId, 'body'),
                publish: publish ? 1 : 0,
            };
            Admin.fetch(cfg.saveUrl, { method: 'PUT', data: data })
                .then(function (res) {
                    markSaved();
                    state.status = res.status || state.status;
                    Admin.toast(res.message || 'Design saved', 'success', { action: { label: 'View page', href: cfg.pageUrl } });
                })
                .catch(function (err) { Admin.toast(err.message || 'The design could not be saved. Please try again.', 'error'); })
                .then(function () { state.saving = false; refreshBar(); });
        }

        function selectPanel() {
            var c = editor.getSelected();
            $('[data-pb-empty]').hidden = !!c;
            $('[data-pb-selected]').hidden = !c;
            if (!c) return;
            $('[data-pb-name]').textContent = c.getName();
            var isPage = c === wrapper();
            $$('[data-pb-act]').forEach(function (b) { b.hidden = isPage; });
            var traits = c.getTraits().length;
            $('[data-pb-traits-empty]').hidden = traits > 0;
            showTab(traits > 0 ? (root.dataset.rightTab || 'settings') : 'style');
        }

        function showTab(tab) {
            root.dataset.rightTab = tab;
            $$('[data-pb-tab]').forEach(function (b) { b.classList.toggle('is-active', b.getAttribute('data-pb-tab') === tab); b.setAttribute('aria-selected', String(b.getAttribute('data-pb-tab') === tab)); });
            $('#pb-settings-panel').hidden = tab !== 'settings';
            $('#pb-style-panel').hidden = tab !== 'style';
        }

        function showLeft(tab) {
            $$('[data-pb-left]').forEach(function (b) { b.classList.toggle('is-active', b.getAttribute('data-pb-left') === tab); b.setAttribute('aria-selected', String(b.getAttribute('data-pb-left') === tab)); });
            $('#pb-blocks').hidden = tab !== 'add';
            $('#pb-layers').hidden = tab !== 'layers';
        }

        function moveBy(delta) {
            var c = editor.getSelected();
            var parent = c && c.parent();
            if (!parent) return;
            var i = c.index();
            var to = i + delta;
            if (to < 0 || to >= parent.components().length) return;
            c.move(parent, { at: delta > 0 ? to + 1 : to });
            editor.select(c);
        }

        root.addEventListener('click', function (ev) {
            var b = ev.target.closest('button, a');
            if (!b || !root.contains(b)) return;
            var c = editor.getSelected();
            if (b.hasAttribute('data-device')) {
                editor.setDevice(b.getAttribute('data-device'));
                $$('[data-device]').forEach(function (d) { d.classList.toggle('is-active', d === b); d.setAttribute('aria-pressed', String(d === b)); });
            } else if (b.hasAttribute('data-pb-tab')) {
                showTab(b.getAttribute('data-pb-tab'));
            } else if (b.hasAttribute('data-pb-left')) {
                showLeft(b.getAttribute('data-pb-left'));
            } else if (b.hasAttribute('data-pb-undo')) {
                um.undo();
            } else if (b.hasAttribute('data-pb-redo')) {
                um.redo();
            } else if (b.hasAttribute('data-pb-save')) {
                save(false);
            } else if (b.hasAttribute('data-pb-publish')) {
                save(true);
            } else if (b.hasAttribute('data-pb-preview')) {
                var on = !root.classList.contains('is-preview');
                root.classList.toggle('is-preview', on);
                b.classList.toggle('is-active', on);
                editor.select(null);
                if (on) editor.runCommand('core:preview'); else editor.stopCommand('core:preview');
                setTimeout(function () { editor.refresh(); }, 50);
            } else if (b.hasAttribute('data-pb-back') && dirty()) {
                ev.preventDefault();
                Admin.confirm({ title: 'Leave without saving?', message: 'Your latest changes to the design will be lost.', confirmText: 'Leave without saving', cancelText: 'Stay', danger: true })
                    .then(function (ok) { if (ok) { markSaved(); window.location.href = b.href; } });
            } else if (c && b.getAttribute('data-pb-act') === 'up') {
                moveBy(-1);
            } else if (c && b.getAttribute('data-pb-act') === 'down') {
                moveBy(1);
            } else if (c && b.getAttribute('data-pb-act') === 'parent' && c.parent()) {
                editor.select(c.parent());
            } else if (c && b.getAttribute('data-pb-act') === 'copy') {
                editor.runCommand('tlb-clone');
            } else if (c && b.getAttribute('data-pb-act') === 'delete') {
                editor.runCommand('tlb-delete');
            } else if (b.hasAttribute('data-pb-start')) {
                var choice = b.getAttribute('data-pb-start');
                if (choice !== 'current') {
                    wrapper().components(TEMPLATES[choice] || '');
                    um.clear();
                    state.savedAt = um.getPointer();
                }
                state.changed = true; // even "current text": the page isn't designed until it is saved
                $('[data-pb-starter]').hidden = true;
                refreshBar();
            }
        });

        editor.on('component:selected component:deselected', selectPanel);
        editor.on('update change:changesCount', refreshBar);
        // refreshBar only reads state, so refreshing after any edit (or a selection) is cheap and never marks a change itself
        editor.on('undo redo component:update component:add component:remove styleable:change', function () { setTimeout(refreshBar, 0); });
        // "Line up" on a button or a picture moves it, not just the words inside it: a button lines up its row of buttons
        // (.pb-buttons follows text-align), a picture or a button on its own gets auto side margins
        var lineUp = function (c) {
            var align = (c.getStyle() || {})['text-align'] || '';
            var row = c.parent();
            if (c.is('pb-button') && row && row.getClasses().indexOf('pb-buttons') !== -1) {
                if (((row.getStyle() || {})['text-align'] || '') !== align) {
                    if (align) row.addStyle({ 'text-align': align }); else row.removeStyle('text-align');
                }
            } else if (c.is('pb-button') || c.is('image')) {
                var sides = { left: ['0', 'auto'], center: ['auto', 'auto'], right: ['auto', '0'] }[align];
                var style = c.getStyle() || {};
                if (sides && (style['margin-left'] !== sides[0] || style['margin-right'] !== sides[1])) {
                    c.addStyle(Object.assign({ 'margin-left': sides[0], 'margin-right': sides[1] }, c.is('pb-button') ? { display: 'flex', width: 'fit-content' } : {}));
                } else if (!sides && style['margin-left'] !== undefined) {
                    ['margin-left', 'margin-right'].concat(c.is('pb-button') ? ['display', 'width'] : []).forEach(function (p) { c.removeStyle(p); });
                }
            }
        };
        editor.on('component:styleUpdate:text-align', lineUp);
        // GrapesJS leaves "Line up" showing the last choice when the next part selected has none, and clicking that choice then
        // does nothing: show the selected part's own value whenever the selection changes
        editor.on('style:target', function () {
            setTimeout(function () {
                var prop = editor.StyleManager.getProperty('text', 'text-align');
                var value = prop ? prop.getValue() : '';
                document.querySelectorAll('#pb-styles input[type=radio]').forEach(function (input) { input.checked = input.value === value; });
            }, 0);
        });
        editor.Keymaps.add('pb:save', '⌘+s, ctrl+s', function () { save(false); }, { prevent: true });
        window.addEventListener('beforeunload', function (ev) {
            if (dirty() && !state.saving) {
                ev.preventDefault();
                ev.returnValue = '';
            }
        });
        editor.on('load', function () {
            wrapper().set({ name: 'Page' });
            wrapper().addClass('pb-page'); // the site's .pb-page styles apply in the canvas too
            um.clear();
            markSaved();
            // designs saved before "Line up" moved buttons and pictures: put them where they were meant to be (shows as a change to save)
            wrapper().find('.pb-btn, img').filter(function (c) { return (c.getStyle() || {})['text-align']; }).forEach(lineUp);
            refreshBar();
            selectPanel();
            showLeft('add');
            if (!cfg.project) $('[data-pb-starter]').hidden = false;
        });
    }

    // after Alpine has started: content.js adds Admin.pickImage / pickLink on alpine:init, and the media library and
    // link search dialogs are Alpine components
    document.addEventListener('alpine:initialized', start, { once: true });
})();
