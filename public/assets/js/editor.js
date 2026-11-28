/* Éditeur riche (ui('rich-editor')) : tout contenu entrant passe par DOMParser + rebuild() ; le serveur renettoie. */
(function () {
  'use strict';
  var roots = document.querySelectorAll('[data-rich-editor]');
  if (!roots.length || !window.DOMParser) { return; }

  var ALLOWED = { H2: 1, H3: 1, H4: 1, P: 1, STRONG: 1, EM: 1, UL: 1, OL: 1, LI: 1, BLOCKQUOTE: 1, BR: 1, A: 1, IMG: 1 };
  var RENAME = { H1: 'H2', H5: 'H4', H6: 'H4', B: 'STRONG', I: 'EM', DIV: 'P' };
  var DROP = /^(SCRIPT|STYLE|IFRAME|OBJECT|EMBED|FORM|TEMPLATE|NOSCRIPT|SVG|MATH|TEXTAREA|SELECT|BUTTON|INPUT|LINK|META|BASE|TITLE)$/;
  var MEDIA = /^\/media\/(?:[A-Za-z0-9_-][A-Za-z0-9_.-]*\/)*[A-Za-z0-9_-][A-Za-z0-9_.-]*\.(?:jpg|jpeg|png|webp)$/;

  function safeHref(v) {
    v = String(v || '').replace(/^[\x00-\x20]+|[\x00-\x20]+$/g, '');
    return !/[\x00-\x1f]/.test(v) && /^(?:https?:|mailto:|tel:|\/(?![\/\\]))/i.test(v) ? v : null;
  }
  function safeSrc(s) { return MEDIA.test(s) && s.indexOf('..') < 0; }
  function el(tag, cls, text) { var x = document.createElement(tag); if (cls) { x.className = cls; } if (text) { x.textContent = text; } return x; }
  function attrs(x, o) { for (var k in o) { x.setAttribute(k, o[k]); } return x; }

  // Nœuds neufs uniquement : rien de la source n'est réinjecté tel quel.
  function rebuild(src, dst) {
    for (var n = src.firstChild; n; n = n.nextSibling) {
      if (n.nodeType === 3) { dst.appendChild(document.createTextNode(n.nodeValue)); continue; }
      var tag = n.nodeType === 1 ? n.nodeName.toUpperCase() : 'SCRIPT';
      if (DROP.test(tag)) { continue; }
      tag = RENAME[tag] || tag;
      if (!ALLOWED[tag]) { rebuild(n, dst); continue; }
      var x = el(tag);
      if (tag === 'A') {
        var href = safeHref(n.getAttribute('href'));
        if (href === null) { rebuild(n, dst); continue; }
        x.setAttribute('href', href);
        if (/^https?:/i.test(href)) { x.setAttribute('rel', 'noopener noreferrer'); }
      } else if (tag === 'IMG') {
        var s = n.getAttribute('src') || '', alt = (n.getAttribute('alt') || '').trim();
        if (safeSrc(s) && alt) { dst.appendChild(attrs(x, { src: s, alt: alt })); }
        continue;
      }
      rebuild(n, x);
      dst.appendChild(x);
    }
  }
  function fromHtml(html) {
    var frag = document.createDocumentFragment();
    rebuild(new DOMParser().parseFromString(String(html), 'text/html').body, frag);
    return frag;
  }
  function esc(s, q) {
    s = s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    return q ? s.replace(/"/g, '&quot;') : s;
  }
  function serialize(node) {
    var out = '';
    for (var n = node.firstChild; n; n = n.nextSibling) {
      if (n.nodeType === 3) { out += esc(n.nodeValue); continue; }
      var t = n.nodeName.toLowerCase();
      out += '<' + t;
      for (var i = 0; i < n.attributes.length; i++) { out += ' ' + n.attributes[i].name + '="' + esc(n.attributes[i].value, 1) + '"'; }
      out += '>' + (t === 'br' || t === 'img' ? '' : serialize(n) + '</' + t + '>');
    }
    return out;
  }

  var BUTTONS = [
    ['h2', 'Titre 2', 'T2', 'b'], ['h3', 'Titre 3', 'T3', 'b'], ['bold', 'Gras', 'G', 's'], ['italic', 'Italique', 'I', 's'],
    ['insertUnorderedList', 'Liste', '•', 's'], ['insertOrderedList', 'Liste numérotée', '1.', 's'], ['blockquote', 'Citation', '«', 'b'],
    ['link', 'Lien', 'Lien'], ['image', 'Image de la médiathèque', 'Image'], ['clear', 'Annuler la mise en forme', 'Effacer']
  ];

  [].forEach.call(roots, function (root) {
    var ta = root.querySelector('textarea'), label = root.querySelector('label'), help = root.querySelector('.rich-help');
    if (!ta || !label) { return; }
    var area = attrs(el('div', 'control rich-area'), { id: ta.id + '-area', contenteditable: 'true', role: 'textbox', 'aria-multiline': 'true', 'aria-labelledby': label.id });
    var described = (ta.getAttribute('aria-describedby') || '').split(' ').filter(function (id) { return !help || id !== help.id; }).join(' ');
    if (described) { area.setAttribute('aria-describedby', described); }
    if (ta.getAttribute('aria-invalid')) { area.setAttribute('aria-invalid', 'true'); }
    if (ta.required) { area.setAttribute('aria-required', 'true'); ta.required = false; } // masqué + requis = envoi bloqué
    area.appendChild(fromHtml(ta.value));

    var bar = attrs(el('div', 'rich-toolbar'), { role: 'toolbar', 'aria-label': 'Mise en forme' });
    var buttons = BUTTONS.map(function (b, i) {
      var btn = attrs(el('button', 'rich-btn', b[2]), { type: 'button', title: b[1], 'aria-label': b[1], 'data-cmd': b[0] });
      btn.tabIndex = i ? -1 : 0;
      if (b[3]) { btn.setAttribute('aria-pressed', 'false'); }
      return bar.appendChild(btn);
    });

    var saved = null;
    function sync() { var tmp = el('div'); rebuild(area, tmp); ta.value = serialize(tmp); }
    function restore() {
      area.focus();
      if (saved) { var sel = window.getSelection(); sel.removeAllRanges(); sel.addRange(saved); }
    }
    function refresh() {
      var sel = window.getSelection(), node = sel.rangeCount ? sel.getRangeAt(0).startContainer : null, block = '';
      if (!node || !area.contains(node)) { return; }
      saved = sel.getRangeAt(0).cloneRange();
      for (; node && node !== area && !block; node = node.parentNode) {
        if (/^(H2|H3|H4|P|BLOCKQUOTE|LI)$/.test(node.nodeName)) { block = node.nodeName; }
      }
      buttons.forEach(function (btn, i) {
        var b = BUTTONS[i];
        if (b[3]) { btn.setAttribute('aria-pressed', String(b[3] === 'b' ? block === b[0].toUpperCase() : document.queryCommandState(b[0]))); }
      });
    }
    function insertNode(node) {
      restore();
      var sel = window.getSelection(), range;
      if (!sel.rangeCount || !area.contains(sel.getRangeAt(0).startContainer)) {
        range = document.createRange(); range.selectNodeContents(area); range.collapse(false); sel.removeAllRanges(); sel.addRange(range);
      }
      range = sel.getRangeAt(0);
      range.deleteContents();
      var last = node.lastChild || node;
      range.insertNode(node);
      range.setStartAfter(last); range.collapse(true);
      sel.removeAllRanges(); sel.addRange(range);
      sync(); refresh();
    }

    // execCommand : bascules et annulation natives ; la sortie repasse toujours par rebuild().
    function run(cmd, btn) {
      if (cmd === 'image') { openPicker(); return; }
      restore();
      if (/^(h2|h3|blockquote)$/.test(cmd)) {
        document.execCommand('formatBlock', false, btn.getAttribute('aria-pressed') === 'true' ? '<p>' : '<' + cmd + '>');
      } else if (cmd === 'link') {
        var url = window.prompt('Lien (https://…, /page, mailto:, tel:) ; vide = retirer', '');
        if (url === null) { return; }
        var href = safeHref(url);
        if (url.trim() === '') { document.execCommand('unlink'); }
        else if (href === null) { window.alert('Adresse refusée.'); }
        else if (window.getSelection().isCollapsed) { insertNode(attrs(el('a', '', url.trim()), { href: href })); }
        else { document.execCommand('createLink', false, href); }
      } else if (cmd === 'clear') {
        document.execCommand('removeFormat'); document.execCommand('unlink'); document.execCommand('formatBlock', false, '<p>');
      } else {
        document.execCommand(cmd);
      }
      sync(); refresh();
    }

    bar.addEventListener('mousedown', function (ev) { ev.preventDefault(); }); // garde la sélection
    bar.addEventListener('click', function (ev) {
      var btn = ev.target.closest('button');
      if (btn) { run(btn.getAttribute('data-cmd'), btn); }
    });
    bar.addEventListener('keydown', function (ev) { // tabulation itinérante (role=toolbar)
      var i = buttons.indexOf(document.activeElement), n = buttons.length;
      var j = { ArrowRight: i + 1, ArrowLeft: i - 1, Home: 0, End: n - 1 }[ev.key];
      if (i < 0 || j === undefined) { return; }
      ev.preventDefault();
      j = (j + n) % n;
      buttons[i].tabIndex = -1; buttons[j].tabIndex = 0; buttons[j].focus();
    });
    area.addEventListener('input', sync);
    document.addEventListener('selectionchange', refresh);
    area.addEventListener('focus', function () { if (!area.firstChild) { area.appendChild(el('p')).appendChild(el('br')); } });
    area.addEventListener('paste', function (ev) {
      var data = ev.clipboardData;
      if (!data) { return; }
      ev.preventDefault();
      var html = data.getData('text/html'), frag = document.createDocumentFragment();
      if (html) { insertNode(fromHtml(html)); return; }
      data.getData('text/plain').split(/\r?\n/).forEach(function (line, k) {
        if (k) { frag.appendChild(el('br')); }
        frag.appendChild(document.createTextNode(line));
      });
      insertNode(frag);
    });
    area.addEventListener('drop', function (ev) { ev.preventDefault(); }); // pas de dépôt de contenu non nettoyé
    label.addEventListener('click', function (ev) { ev.preventDefault(); area.focus(); });
    if (ta.form) { ta.form.addEventListener('submit', sync); }

    // Médiathèque : seuls les data-media-* validés sont repris.
    var dlg = null, list, search, status, seq = 0;
    function load() {
      var url = root.getAttribute('data-picker-url') || '', mine = ++seq;
      list.textContent = '';
      status.textContent = 'Chargement…';
      if (!/^\/(?![\/\\])/.test(url)) { return; }
      fetch(url + '?q=' + encodeURIComponent(search.value), { credentials: 'same-origin' })
        .then(function (r) { if (!r.ok) { throw new Error(); } return r.text(); })
        .then(function (text) {
          if (mine !== seq) { return; }
          var found = new DOMParser().parseFromString(text, 'text/html').querySelectorAll('[data-media-url]'), n = 0;
          [].forEach.call(found, function (m) {
            var src = m.getAttribute('data-media-url') || '', alt = (m.getAttribute('data-media-alt') || '').trim();
            var t = m.parentNode.querySelector('.media-title');
            if (!safeSrc(src)) { return; }
            var b = attrs(el('button', 'rich-pick'), { type: 'button' });
            b.appendChild(attrs(el('img'), { src: src, alt: '' }));
            b.appendChild(el('span', '', t ? t.textContent : src));
            b.addEventListener('click', function () {
              var a = alt || (window.prompt('Texte alternatif (obligatoire) :', '') || '').trim();
              if (!a) { status.textContent = 'Texte alternatif obligatoire.'; return; }
              dlg.close();
              insertNode(attrs(el('img'), { src: src, alt: a }));
            });
            list.appendChild(el('li')).appendChild(b);
            n++;
          });
          status.textContent = n ? n + ' image(s).' : 'Aucune image trouvée.';
        })
        .catch(function () { status.textContent = 'Échec du chargement.'; });
    }
    function openPicker() {
      if (!dlg) {
        dlg = attrs(el('dialog', 'dialog rich-dialog'), { 'aria-labelledby': area.id + '-pt' });
        var form = attrs(el('form', 'rich-search'), { role: 'search' });
        search = attrs(el('input', 'control'), { type: 'search', id: area.id + '-q', maxlength: '100' });
        form.appendChild(attrs(el('label', '', 'Rechercher une image'), { for: search.id }));
        form.appendChild(search);
        form.appendChild(attrs(el('button', 'btn btn-secondary', 'Rechercher'), { type: 'submit' }));
        form.addEventListener('submit', function (ev) { ev.preventDefault(); load(); });
        status = attrs(el('p', 'field-hint'), { role: 'status' });
        list = el('ul', 'rich-picks');
        var close = attrs(el('button', 'btn btn-secondary', 'Fermer'), { type: 'button' });
        close.addEventListener('click', function () { dlg.close(); });
        dlg.addEventListener('close', restore);
        [attrs(el('h2', '', 'Choisir une image'), { id: area.id + '-pt' }), form, status, list, close].forEach(function (x) { dlg.appendChild(x); });
        document.body.appendChild(dlg);
      }
      dlg.showModal();
      load();
    }

    ta.hidden = true;
    if (help) { help.hidden = true; }
    root.insertBefore(bar, ta);
    root.insertBefore(area, ta);
    sync();
  });
})();
