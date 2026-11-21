/* Comportements de l'interface : menu mobile, confirmations, double soumission, nom de fichier. Sans bibliothèque. */
(function () {
  'use strict';
  var menuToggle = null;

  function closeAccount(except) {
    var all = document.querySelectorAll('details.account[open]');
    for (var i = 0; i < all.length; i++) { if (all[i] !== except) { all[i].removeAttribute('open'); } }
  }

  function closeMenu() {
    if (!menuToggle) { return; }
    var menu = document.getElementById(menuToggle.getAttribute('aria-controls'));
    if (menu && menu.hasAttribute('data-open')) {
      menu.removeAttribute('data-open');
      menuToggle.setAttribute('aria-expanded', 'false');
      menuToggle.setAttribute('aria-label', 'Ouvrir le menu');
      menuToggle.focus();
    }
  }

  document.addEventListener('click', function (ev) {
    closeAccount(ev.target.closest ? ev.target.closest('details.account') : null);
    var t = ev.target.closest ? ev.target.closest('[data-menu-toggle],[data-confirm-open],[data-confirm-close],[data-print]') : null;
    if (!t) { return; }
    if (t.hasAttribute('data-print')) { window.print(); return; }
    if (t.hasAttribute('data-menu-toggle')) {
      var menu = document.getElementById(t.getAttribute('aria-controls'));
      if (!menu) { return; }
      ev.preventDefault();
      menuToggle = t;
      var open = !menu.hasAttribute('data-open');
      if (open) { menu.setAttribute('data-open', ''); } else { menu.removeAttribute('data-open'); }
      t.setAttribute('aria-expanded', open ? 'true' : 'false');
      t.setAttribute('aria-label', open ? 'Fermer le menu' : 'Ouvrir le menu');
      if (open) { var first = menu.querySelector('nav a[href]'); if (first) { first.focus(); } } // le menu précède le bouton dans le DOM : sans cela, Tab part vers la page
    } else if (t.hasAttribute('data-confirm-open')) {
      var dlg = document.getElementById(t.getAttribute('data-confirm-open'));
      if (dlg && typeof dlg.showModal === 'function') { ev.preventDefault(); dlg.showModal(); } // sinon : le formulaire part directement
    } else {
      var d = t.closest('dialog');
      if (d) { d.close(); }
    }
  });

  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape') {
      var acc = document.querySelector('details.account[open]');
      if (acc) { acc.removeAttribute('open'); acc.querySelector('summary').focus(); return; }
      closeMenu();
    } // Échap ferme déjà les <dialog> nativement
  });

  document.addEventListener('submit', function (ev) {
    if (ev.defaultPrevented) { return; }
    var form = ev.target;
    if (form.target === '_blank' || form.hasAttribute('download')) { return; } // la page reste ouverte : ne pas verrouiller le bouton
    // Différé : désactiver tout de suite retirerait la valeur du bouton déclencheur de l'envoi.
    setTimeout(function () {
      var buttons = form.querySelectorAll('button[type="submit"]');
      for (var i = 0; i < buttons.length; i++) {
        buttons[i].disabled = true;
        buttons[i].setAttribute('aria-busy', 'true');
      }
    }, 0);
  });

  window.addEventListener('pageshow', function (ev) { // retour arrière : le formulaire doit redevenir utilisable
    if (!ev.persisted) { return; }
    var buttons = document.querySelectorAll('button[type="submit"][aria-busy="true"]');
    for (var i = 0; i < buttons.length; i++) {
      buttons[i].disabled = false;
      buttons[i].removeAttribute('aria-busy');
    }
  });

  // Filtres du journal : ouverts partout sans JS ; repliés sur téléphone tant qu'aucun filtre n'est actif.
  var filters = document.querySelector('details.filters[data-collapse-narrow="1"]');
  if (filters && window.matchMedia) {
    var narrow = window.matchMedia('(max-width: 639.98px)');
    if (narrow.matches) { filters.removeAttribute('open'); }
    // Rotation en paysage : le résumé disparaît, les filtres doivent donc se rouvrir.
    var reopen = function (m) { if (!m.matches) { filters.setAttribute('open', ''); } };
    if (narrow.addEventListener) { narrow.addEventListener('change', reopen); } else if (narrow.addListener) { narrow.addListener(reopen); }
  }

  document.addEventListener('change', function (ev) {
    var input = ev.target;
    if (!input.hasAttribute || !input.hasAttribute('data-file-input')) { return; }
    var out = document.getElementById(input.id + '-name');
    if (!out) { return; }
    var f = input.files && input.files[0];
    if (!f) { out.textContent = 'Aucun fichier choisi'; return; }
    var kb = f.size / 1024;
    out.textContent = f.name + ' (' + (kb >= 1024 ? (kb / 1024).toFixed(1) + ' Mo' : Math.max(1, Math.round(kb)) + ' Ko') + ')';
  });
})();
