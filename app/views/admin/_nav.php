<?php declare(strict_types=1);
/**
 * Liste unique du menu d'administration : [groupe, libellé, href, permission (null = tous), icône].
 * Un lien n'apparaît que si la permission est détenue ET la route GET existe : ajouter un module = ajouter une ligne ici.
 */
return [
    ['', 'Tableau de bord', '/admin', null, 'home'],
    ['Adhérents', 'Adhérents', '/admin/adherents', 'members.view', 'users'],
    ['Adhérents', 'Référentiels', '/admin/adherents/referentiels', 'settings.manage', 'anchor'],
    ['Finances', 'Comptes', '/admin/finances', 'finance.view', 'coins'],
    ['Administration', 'Utilisateurs', '/admin/utilisateurs', 'users.manage', 'users'],
    ['Administration', 'Rôles', '/admin/roles', 'roles.manage', 'shield'],
    ['Administration', "Journal d'audit", '/admin/journal', 'audit.view', 'file'],
];
