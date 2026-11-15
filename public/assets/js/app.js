/* Comportements de l'interface : menu mobile, confirmations, double soumission, nom de fichier. Sans bibliothèque. */
(function () {
  'use strict';
  var menuToggle = null;

  function closeMenu() {
    if (!menuToggle) { return; }
    var menu = document.getElementById(menuToggle.getAttribute('aria-controls'));
    if (menu && menu.hasAttribute('data-open')) {
      menu.removeAttribute('data-open');
      menuToggle.setAttribute('aria-expanded', 'false');
      menuToggle.focus();
    }
  }

  document.addEventListener('click', function (ev) {
    var t = ev.target.closest ? ev.target.closest('[data-menu-toggle],[data-confirm-open],[data-confirm-close]') : null;
    if (!t) { return; }
    if (t.hasAttribute('data-menu-toggle')) {
      var menu = document.getElementById(t.getAttribute('aria-controls'));
      if (!menu) { return; }
      ev.preventDefault();
      menuToggle = t;
      var open = !menu.hasAttribute('data-open');
      if (open) { menu.setAttribute('data-open', ''); } else { menu.removeAttribute('data-open'); }
      t.setAttribute('aria-expanded', open ? 'true' : 'false');
    } else if (t.hasAttribute('data-confirm-open')) {
      var dlg = document.getElementById(t.getAttribute('data-confirm-open'));
      if (dlg && typeof dlg.showModal === 'function') { ev.preventDefault(); dlg.showModal(); } // sinon : le formulaire part directement
    } else {
      var d = t.closest('dialog');
      if (d) { d.close(); }
    }
  });

  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape') { closeMenu(); } // Échap ferme déjà les <dialog> nativement
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
