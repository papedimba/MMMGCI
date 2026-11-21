/* Dessine le QR de la carte : l'adresse de vérification est lue dans data-qr-url (aucun script en ligne, CSP stricte). */
(function () {
  'use strict';
  if (typeof qrcode !== 'function') { return; }
  document.querySelectorAll('[data-qr-url]').forEach(function (el) {
    var qr = qrcode(0, 'M');
    qr.addData(el.getAttribute('data-qr-url'));
    qr.make();
    var svg = new DOMParser().parseFromString(qr.createSvgTag({ scalable: true, margin: 0 }), 'image/svg+xml').documentElement;
    el.appendChild(document.importNode(svg, true));
    el.classList.add('is-ready');
  });
})();
