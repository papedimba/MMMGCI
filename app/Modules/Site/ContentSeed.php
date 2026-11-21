<?php
declare(strict_types=1);

namespace App\Modules\Site;

use App\Core\Database;
use App\Core\Gate;
use App\Modules\Cms\MenuService;
use App\Modules\Cms\PageService;

/**
 * Semis du site public à partir du cahier des charges 2026–2027 (docs/conception/cahier-des-charges-2026-2027.txt), EN BROUILLON seulement :
 * le cahier n'est pas encore adopté (art. 21–23) ; relecture et publication sont des actions humaines. Rien n'est inventé : chaque bloc cite
 * sa source (champ interne « source », jamais rendu au public) et garde les réserves du texte. Idempotent par adresse : une page existante
 * (même modifiée) est ignorée telle quelle ; un menu non vide n'est jamais touché.
 */
final class ContentSeed
{
    private const RESERVE = 'Sous réserve des ressources disponibles, des textes applicables et des critères adoptés, la Mutuelle peut développer les domaines d’intervention suivants :';

    /** Rubriques sans source dans le cahier des charges : non créées, listées dans l'administration (« À fournir par la Mutuelle »). */
    public const TO_PROVIDE = [
        'Mot du Président' => 'Le cahier des charges ne contient aucun mot du Président : texte à fournir et à faire valider par la Mutuelle.',
        'Historique' => 'Aucun historique de la Mutuelle dans le cahier des charges : dates et faits à fournir par la Mutuelle.',
        'Mentions légales' => 'Éditeur, hébergeur et mentions obligatoires : à fournir par la Mutuelle (le cahier des charges n’en parle pas).',
        'Confidentialité' => 'Politique de confidentialité et texte de consentement du formulaire d’adhésion : à rédiger ou faire valider par la Mutuelle.',
        'Galerie' => 'Photographies et légendes : à fournir par la Mutuelle ; aucune image n’est semée.',
    ];

    public function __construct(private Database $db, private PageService $pages, private MenuService $menus, private Gate $gate)
    {
    }

    /**
     * Logique de bin/seed-site.php : --actor=<courriel> obligatoire (utilisateur actif ayant cms.pages.manage) ; --publish refusé.
     * @param list<string> $args @return array{int,string} code de sortie, message
     */
    public function cli(array $args): array
    {
        $actor = null;
        foreach ($args as $a) {
            if (str_starts_with($a, '--publish')) {
                return [1, 'Refusé : le semis ne publie jamais. Relire puis publier est une action humaine (Site web › Pages).'];
            }
            if (str_starts_with($a, '--actor=')) {
                $actor = trim(substr($a, 8));
            } else {
                return [1, "Option inconnue : $a. Usage : php bin/seed-site.php --actor=<courriel d'un administrateur>"];
            }
        }
        if ($actor === null || $actor === '') {
            return [1, "Usage : php bin/seed-site.php --actor=<courriel d'un administrateur actif>"];
        }
        $user = $this->db->fetch('SELECT id, is_active FROM users WHERE email = ?', [$actor]);
        if ($user === null || (int) $user['is_active'] !== 1 || !$this->gate->allows((int) $user['id'], 'cms.pages.manage')) {
            return [1, 'Acteur refusé : utilisateur inexistant, inactif ou sans droit de rédaction des pages.'];
        }
        $r = $this->run((int) $user['id']);
        return [0, "Semis terminé : {$r['created']} créé(s), {$r['skipped']} ignoré(s) déjà présent(s). Tout est en BROUILLON : relecture humaine avant publication."];
    }

    /** @return array{created:int, skipped:int} pages et menus créés / déjà présents (ignorés) */
    public function run(int $actor): array
    {
        $created = $skipped = 0;
        $ids = [];
        foreach ($this->definitions() as $slug => $def) {
            $existing = $this->db->fetchValue('SELECT id FROM cms_pages WHERE slug = ?', [$slug]);
            if ($existing !== null) {
                $ids[$slug] = (int) $existing;
                $skipped++;
                continue;
            }
            $data = $def($ids) + ['slug' => $slug];
            if (isset($data['parent'])) {
                $data['parent_id'] = $ids[$data['parent']] ?? null;
                unset($data['parent']);
            }
            $ids[$slug] = $this->pages->create($data, $actor);
            $created++;
        }
        foreach (['header' => $this->headerMenu($ids), 'footer' => $this->footerMenu($ids)] as $location => $items) {
            $this->menus->seed($location, $items, $actor) ? $created++ : $skipped++;
        }
        return ['created' => $created, 'skipped' => $skipped];
    }

    /** @return array<string,callable(array<string,int>):array<string,mixed>> slug => données de page (ordre = ordre de création) */
    private function definitions(): array
    {
        $titre = static fn (string $t, string $src, int $level = 2): array => ['type' => 'titre', 'text' => $t, 'level' => $level, 'source' => $src];
        $texte = static fn (string $html, string $src): array => ['type' => 'texte', 'html' => $html, 'source' => $src];
        $liste = static fn (array $items, string $src): array => ['type' => 'liste', 'items' => array_map(static fn (string $t): array => ['text' => $t], $items), 'source' => $src];
        $dyn = 'Liste dynamique du site (aucun texte du cahier des charges)';

        return [
            'contact' => fn (): array => ['title' => 'Contact', 'blocks' => [
                ['type' => 'formulaire', 'form_key' => 'contact', 'source' => 'Fonction du site (aucun texte du cahier des charges)'],
            ]],
            'adhesion' => fn (): array => ['title' => 'Adhésion', 'blocks' => [
                $titre('Conditions d’adhésion', 'CDC art. 5'),
                $texte('<p>Peuvent demander leur adhésion les marins de la marine marchande, les gens de mer, les personnels navigants et assimilés du secteur maritime, les anciens marins ainsi que toute autre catégorie admise par les statuts. L’admission est prononcée selon la procédure interne.</p>', 'CDC art. 5'),
                ['type' => 'dossier', 'title' => 'Constitution du dossier d’adhésion', 'intro' => '', 'source' => 'CDC art. 6',
                    // Seules les situations qui modifient réellement la liste (art. 6 : « lorsque disponible ou pertinent », « lorsque le dispositif le prévoit »).
                    'situations' => [['key' => 'livret', 'label' => 'Livret maritime disponible'], ['key' => 'ayants-droit', 'label' => 'Ayants droit à déclarer']],
                    'pieces' => [
                        ['label' => 'Une fiche individuelle d’adhésion dûment renseignée', 'situations' => ''],
                        ['label' => 'Une copie d’une pièce d’identité valide', 'situations' => ''],
                        ['label' => 'Une copie du livret maritime, lorsque disponible ou pertinent', 'situations' => 'livret'],
                        ['label' => 'Une photo d’identité, si elle est requise pour la carte', 'situations' => ''],
                        ['label' => 'Les coordonnées téléphoniques et autres moyens de contact', 'situations' => ''],
                        ['label' => 'Les informations relatives aux ayants droit, lorsque le dispositif le prévoit', 'situations' => 'ayants-droit']]],
                $titre('Carte de membre', 'CDC art. 7'),
                $texte('<p>Chaque adhérent régulièrement inscrit peut recevoir une carte de membre permettant son identification, la vérification de son statut, le suivi de ses cotisations et l’accès aux prestations auxquelles il est éligible. Les conditions de délivrance, de renouvellement et de remplacement sont fixées par les instances compétentes.</p>', 'CDC art. 7'),
                ['type' => 'formulaire', 'form_key' => 'adhesion', 'source' => 'CDC art. 5–6 (demande en ligne)'],
            ]],
            'la-mutuelle' => fn (): array => ['title' => 'La Mutuelle', 'blocks' => [
                $titre('Préambule', 'CDC préambule'),
                $texte('<p>La Mutuelle des Marins Marchands et Gens de Mer de Côte d’Ivoire est une organisation de solidarité, d’entraide et d’accompagnement social destinée aux professionnels du secteur maritime ainsi qu’à leurs familles.</p>'
                    . '<p>Face aux difficultés rencontrées par les marins marchands et gens de mer dans l’exercice de leur profession — notamment l’instabilité de l’emploi, les difficultés financières, les risques professionnels, les problèmes de santé et les situations sociales difficiles — la Mutuelle entend mettre en place un cadre organisé, transparent et durable de protection et d’assistance.</p>', 'CDC préambule'),
                $titre('Objectif général', 'CDC art. 1'),
                $texte('<p>Assurer la solidarité, l’entraide, l’accompagnement social et la contribution à l’amélioration des conditions de vie des marins marchands et gens de mer ainsi que de leurs familles.</p>', 'CDC art. 1'),
            ]],
            'missions' => fn (): array => ['title' => 'Missions et objectifs', 'parent' => 'la-mutuelle', 'blocks' => [
                $titre('Objectifs spécifiques', 'CDC art. 2'),
                $liste([
                    'Renforcer la solidarité entre les marins marchands et gens de mer.',
                    'Apporter une assistance aux membres confrontés à des difficultés sociales.',
                    'Accompagner les familles des marins en cas de décès, selon les règles et ressources disponibles.',
                    'Faciliter l’accès à l’information et à l’orientation sanitaire.',
                    'Contribuer à l’insertion professionnelle et au retour à l’emploi des marins.',
                    'Défendre les intérêts sociaux et professionnels des membres dans le respect des missions de la Mutuelle.',
                    'Développer des partenariats avec les armements, entreprises maritimes, syndicats, ONG et institutions.',
                    'Promouvoir la formation et le perfectionnement professionnel.',
                    'Accompagner les anciens marins et les ayants droit selon les dispositifs adoptés.'], 'CDC art. 2'),
            ]],
            'gouvernance' => fn (): array => ['title' => 'Organisation et gouvernance', 'parent' => 'la-mutuelle', 'blocks' => [
                $titre('Organes de la Mutuelle', 'CDC art. 3'),
                $texte('<p>La Mutuelle fonctionne à travers les organes prévus par ses statuts et son règlement intérieur. Selon les textes adoptés, ces organes peuvent comprendre :</p>', 'CDC art. 3'),
                $liste(['L’Assemblée générale', 'Le Conseil d’administration', 'Le Bureau exécutif', 'L’organe de contrôle', 'Les commissions techniques', 'Les délégations et points focaux'], 'CDC art. 3'),
            ]],
            'actions-sociales' => fn (): array => ['title' => 'Actions sociales', 'blocks' => [
                $titre('Domaines d’intervention', 'CDC art. 9'),
                $texte('<p>' . self::RESERVE . '</p>', 'CDC art. 9'),
                $this->domaines(),
                $titre('Fonds de solidarité', 'CDC art. 10'),
                $texte('<p>La Mutuelle peut constituer un fonds de solidarité distinctement suivi afin de répondre aux situations sociales urgentes.</p><p>Toute aide est soumise à des critères écrits, à une procédure d’examen et à une validation par l’organe compétent.</p>', 'CDC art. 10'),
            ]],
            'fonds-de-solidarite' => fn (): array => ['title' => 'Fonds de solidarité', 'parent' => 'actions-sociales', 'blocks' => [
                $texte('<p>La Mutuelle peut constituer un fonds de solidarité distinctement suivi afin de répondre aux situations sociales urgentes.</p>'
                    . '<p>Ses ressources peuvent provenir des cotisations affectées, contributions exceptionnelles autorisées, dons, legs, partenariats et subventions.</p>'
                    . '<p>Toute aide est soumise à des critères écrits, à une procédure d’examen et à une validation par l’organe compétent. Les décisions et décaissements sont documentés et justifiés.</p>', 'CDC art. 10'),
            ]],
            'emploi' => fn (array $ids): array => ['title' => 'Emploi', 'blocks' => [
                $texte('<p>' . self::RESERVE . '</p>', 'CDC art. 9'),
                $this->domaines(['Emploi']),
                $titre('Objectifs spécifiques', 'CDC art. 2'),
                $texte('<p>Contribuer à l’insertion professionnelle et au retour à l’emploi des marins.</p>', 'CDC art. 2'),
                ['type' => 'appel', 'title' => 'Nous écrire', 'text' => '', 'button_label' => 'Aller à la page Contact', 'page_id' => $ids['contact'], 'url' => '',
                    'source' => 'Fonction du site (aucun texte du cahier des charges)'],
            ]],
            'formation' => fn (): array => ['title' => 'Formation', 'blocks' => [
                $texte('<p>' . self::RESERVE . '</p>', 'CDC art. 9'),
                $this->domaines(['Formation']),
                $titre('Objectifs spécifiques', 'CDC art. 2'),
                $texte('<p>Promouvoir la formation et le perfectionnement professionnel.</p>', 'CDC art. 2'),
            ]],
            'partenaires' => fn (): array => ['title' => 'Partenariats', 'blocks' => [
                $titre('Objectifs spécifiques', 'CDC art. 2'),
                $texte('<p>Développer des partenariats avec les armements, entreprises maritimes, syndicats, ONG et institutions.</p>', 'CDC art. 2'),
                $titre('Partenariats stratégiques', 'CDC art. 12'),
                $texte('<p>La Mutuelle recherche des conventions avec les armements maritimes, sociétés de pêche industrielle, entreprises offshore, syndicats, associations professionnelles, établissements sanitaires, centres de formation et institutions publiques ou privées.</p>'
                    . '<p>Chaque partenariat fait l’objet d’un écrit précisant son objet, les engagements réciproques, sa durée, son suivi et ses modalités de fin.</p>', 'CDC art. 12'),
            ]],
            'accueil' => fn (array $ids): array => ['title' => 'Mutuelle des Marins Marchands et Gens de Mer de Côte d’Ivoire', 'template' => 'accueil',
                'excerpt' => 'La Mutuelle des Marins Marchands et Gens de Mer de Côte d’Ivoire est une organisation de solidarité, d’entraide et d’accompagnement social destinée aux professionnels du secteur maritime ainsi qu’à leurs familles.',
                'blocks' => [
                    ['type' => 'appel', 'title' => 'Adhésion', 'button_label' => 'Voir les conditions d’adhésion', 'page_id' => $ids['adhesion'], 'url' => '', 'source' => 'CDC art. 5',
                        'text' => 'Peuvent demander leur adhésion les marins de la marine marchande, les gens de mer, les personnels navigants et assimilés du secteur maritime, les anciens marins ainsi que toute autre catégorie admise par les statuts. L’admission est prononcée selon la procédure interne.'],
                    $titre('Domaines d’intervention', 'CDC art. 9'),
                    $texte('<p>' . self::RESERVE . '</p>', 'CDC art. 9'),
                    $this->domaines(),
                    $titre('Pièces du dossier d’adhésion', 'CDC art. 6'),
                    $liste(['Une fiche individuelle d’adhésion dûment renseignée', 'Une copie d’une pièce d’identité valide', 'Une copie du livret maritime, lorsque disponible ou pertinent',
                        'Une photo d’identité, si elle est requise pour la carte', 'Les coordonnées téléphoniques et autres moyens de contact',
                        'Les informations relatives aux ayants droit, lorsque le dispositif le prévoit'], 'CDC art. 6'),
                    ['type' => 'articles', 'count' => 3, 'source' => $dyn],
                    ['type' => 'evenements', 'count' => 3, 'source' => $dyn],
                ]],
        ];
    }

    /** @param list<string>|null $only @return array<string,mixed> */
    private function domaines(?array $only = null): array
    {
        $rows = [
            ['Santé', 'Information, orientation sanitaire et dispositifs d’accès aux soins.'],
            ['Décès', 'Soutien à la famille du membre décédé selon les conditions prévues.'],
            ['Maladie', 'Assistance exceptionnelle après examen du dossier.'],
            ['Famille', 'Accompagnement des ayants droit dans les limites des règles adoptées.'],
            ['Emploi', 'Mise en relation avec les armements et diffusion d’opportunités.'],
            ['Formation', 'Information et orientation vers les formations professionnelles.'],
            ['Retraite', 'Accompagnement des anciens marins.'],
            ['Urgences', 'Étude des demandes d’aide en situation sociale urgente.'],
        ];
        $rows = array_values(array_filter($rows, static fn (array $r): bool => $only === null || in_array($r[0], $only, true)));
        return ['type' => 'domaines', 'source' => 'CDC art. 9', 'items' => array_map(static fn (array $r): array => ['name' => $r[0], 'nature' => $r[1], 'description' => ''], $rows)];
    }

    /** @param array<string,int> $ids @return list<array<string,mixed>> */
    private function headerMenu(array $ids): array
    {
        $page = static fn (string $label, string $slug, int $depth = 0): array => ['label' => $label, 'page_id' => $ids[$slug], 'depth' => $depth, 'is_active' => 1];
        return [$page('La Mutuelle', 'la-mutuelle'), $page('Missions', 'missions', 1), $page('Gouvernance', 'gouvernance', 1), $page('Adhésion', 'adhesion'),
            $page('Actions sociales', 'actions-sociales'), $page('Emploi', 'emploi'), $page('Formation', 'formation'),
            // /actualites est une route fixe (liste, état vide honnête), pas une page : lien inactif, à activer par la Mutuelle quand elle publie.
            ['label' => 'Actualités', 'url' => '/actualites', 'depth' => 0, 'is_active' => 0], $page('Contact', 'contact')];
    }

    /** @param array<string,int> $ids @return list<array<string,mixed>> */
    private function footerMenu(array $ids): array
    {
        return [['label' => 'Documents', 'url' => '/documents', 'depth' => 0, 'is_active' => 0],
            ['label' => 'Partenaires', 'page_id' => $ids['partenaires'], 'depth' => 0, 'is_active' => 1]];
    }
}
