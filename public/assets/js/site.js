/* Site public : « Préparer mon dossier ». Les cases de situation filtrent la liste des pièces ; rien n'est envoyé ni stocké.
   Sans ce script, la liste complète reste affichée (les cases sont masquées hors html.js). */
(function () {
  'use strict';
  var blocks = document.querySelectorAll('[data-dossier]');
  Array.prototype.forEach.call(blocks, function (block) {
    var boxes = block.querySelectorAll('[data-situation]');
    var pieces = block.querySelectorAll('.pub-dossier-pieces li');
    var count = block.querySelector('[data-dossier-count]');
    if (!boxes.length) { return; }
    function update() {
      var chosen = [];
      Array.prototype.forEach.call(boxes, function (b) { if (b.checked) { chosen.push(b.value); } });
      var shown = 0;
      Array.prototype.forEach.call(pieces, function (li) {
        var keys = (li.getAttribute('data-for') || '').split(' ').filter(Boolean);
        // Aucune case cochée : toute la liste ; sinon les pièces communes et celles des situations choisies.
        var visible = !chosen.length || !keys.length || keys.some(function (k) { return chosen.indexOf(k) !== -1; });
        li.hidden = !visible;
        if (visible) { shown++; }
      });
      if (chosen.length) { block.setAttribute('data-filtered', ''); } else { block.removeAttribute('data-filtered'); }
      if (count) {
        count.textContent = chosen.length ? shown + (shown > 1 ? ' pièces à préparer pour votre situation.' : ' pièce à préparer pour votre situation.') : '';
      }
    }
    Array.prototype.forEach.call(boxes, function (b) { b.addEventListener('change', update); });
    update();
  });
})();
