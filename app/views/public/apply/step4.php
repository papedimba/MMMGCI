<?php declare(strict_types=1);
/**
 * Étape 4 : pièces (art. 6), toutes facultatives à l'envoi ; disque privé, liées au brouillon ; nom du fichier jamais conservé.
 * @var array<string,string> $errors @var list<array{file_id:int,type:string,size:int}> $files @var array<string,string> $types @var list<string> $flags
 */
$max = \App\Modules\Site\ApplyFlow::MAX_FILES;
?>
<p>Vous pouvez joindre dès maintenant une copie de votre pièce d'identité<?= e(in_array('livret', $flags, true) ? ', de votre livret maritime' : '') ?> et une photo d'identité. Les pièces sont facultatives à cette étape : la Mutuelle pourra vous les demander ensuite.</p>
<?php if ($files !== []): ?>
<h2 class="apply-sub">Pièces jointes</h2>
<ul class="apply-files">
<?php foreach ($files as $file): ?>
<li><span><?= e($types[$file['type']] ?? $file['type']) ?> <span class="pub-meta">(<?= (int) max(1, intdiv($file['size'], 1024)) ?> Ko)</span></span>
<button type="submit" class="btn btn-ghost" name="op" value="remove-<?= (int) $file['file_id'] ?>" formnovalidate><span>Retirer<span class="visually-hidden"> : <?= e($types[$file['type']] ?? $file['type']) ?></span></span></button></li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
<?php if (count($files) < $max): ?>
<h2 class="apply-sub">Ajouter une pièce</h2>
<?= ui('field', ['name' => 'type', 'label' => 'Type de pièce', 'type' => 'select', 'options' => $types, 'value' => in_array('livret', $flags, true) && $files !== [] ? 'livret_maritime' : 'identite']) ?>
<?= ui('file-input', ['name' => 'piece', 'label' => 'Fichier', 'accept' => '.pdf,.jpg,.jpeg,.png,.webp', 'hint' => 'PDF, JPG ou PNG ; photo : JPG, PNG ou WebP. ' . $max . ' pièces au maximum, 15 Mo en tout.', 'error' => $errors['piece'] ?? '']) ?>
<p><button type="submit" class="btn btn-secondary" name="op" value="upload"><span>Ajouter la pièce</span></button></p>
<?php else: ?>
<p class="pub-empty"><?= e($max . ' pièces au maximum : retirez-en une pour en ajouter une autre.') ?></p>
<?php endif; ?>
