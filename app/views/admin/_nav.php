<?php declare(strict_types=1);
/**
 * Liste unique du menu d'administration : [groupe, libellé, href, permission (null = tous ; liste = l'une d'elles), icône].
 * Un lien n'apparaît que si la permission est détenue ET la route GET existe : ajouter un module = ajouter une ligne ici.
 */
return [
    ['', 'Tableau de bord', '/admin', null, 'home'],
    ['Adhérents', 'Adhérents', '/admin/adherents', 'members.view', 'users'],
    ['Adhérents', 'Référentiels', '/admin/adherents/referentiels', 'settings.manage', 'anchor'],
    ['Cotisations', 'Types', '/admin/cotisations/types', 'contributions.view', 'coins'],
    ['Cotisations', 'Appels', '/admin/cotisations/appels', 'contributions.view', 'file'],
    ['Cotisations', 'Impayés', '/admin/cotisations/impayes', 'contributions.view', 'file'],
    ['Finances', 'Comptes', '/admin/finances', 'finance.view', 'coins'],
    ['Finances', 'Décaissements', '/admin/decaissements', ['finance.view', 'finance.control', 'disbursement.initiate', 'disbursement.approve', 'disbursement.pay'], 'file'],
    ['Finances', 'Contrôle', '/admin/controle', 'finance.control', 'shield'],
    ['Finances', 'Fonds de solidarité', '/admin/solidarite', 'solidarity.view', 'heart'],
    ['Site web', 'Pages', '/admin/site/pages', ['cms.pages.manage', 'cms.publish'], 'file'],
    ['Site web', 'Articles', '/admin/site/articles', ['cms.pages.manage', 'cms.publish'], 'file'],
    ['Site web', 'Évènements', '/admin/site/evenements', ['cms.pages.manage', 'cms.publish'], 'file'],
    ['Site web', 'Documents officiels', '/admin/site/documents', ['cms.pages.manage', 'cms.publish'], 'file'],
    ['Site web', 'Galeries', '/admin/site/galeries', ['cms.pages.manage', 'cms.publish'], 'file'],
    ['Site web', 'Bannières', '/admin/site/bannieres', ['cms.pages.manage', 'cms.publish'], 'file'],
    ['Site web', 'Témoignages', '/admin/site/temoignages', ['cms.pages.manage', 'cms.publish'], 'file'],
    ['Site web', 'Médias', '/admin/site/medias', ['cms.media.manage', 'cms.pages.manage'], 'file'],
    ['Site web', 'Formulaires reçus', '/admin/site/formulaires', 'cms.forms.manage', 'file'],
    ['Site web', 'Réglages du site', '/admin/site/reglages', ['cms.settings.manage', 'settings.validate'], 'file'],
    ['Site web', 'Menus', '/admin/site/menus', 'cms.publish', 'file'],
    ['Administration', 'Utilisateurs', '/admin/utilisateurs', 'users.manage', 'users'],
    ['Administration', 'Rôles', '/admin/roles', 'roles.manage', 'shield'],
    ['Administration', "Journal d'audit", '/admin/journal', 'audit.view', 'file'],
];
