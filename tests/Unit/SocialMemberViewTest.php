<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Social\SocialMemberView;
use App\Modules\Social\SocialWorkflow;
use Tests\DatabaseTestCase;
use Tests\Support\SocialFixtures;

final class SocialMemberViewTest extends DatabaseTestCase
{
    use SocialFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSocial();
    }

    public function test_member_status_mapping_is_pinned(): void
    {
        self::assertSame(['recue' => 'Reçue', 'en_instruction' => 'En cours d\'examen', 'en_attente_pieces' => 'Pièces demandées', 'soumise_decision' => 'En cours d\'examen',
            'accordee' => 'Décision rendue', 'refusee' => 'Décision rendue', 'en_paiement' => 'Décision rendue', 'cloturee' => 'Clôturée'], SocialMemberView::STATUS);
        self::assertSame(SocialWorkflow::STATUSES, array_keys(SocialMemberView::STATUS));
        foreach (SocialMemberView::STATUS as $internal => $label) {
            self::assertSame($label, SocialMemberView::statusOf($internal));
        }
        try {
            SocialMemberView::statusOf('etat_interne_inconnu');
            self::fail('exception attendue');
        } catch (\DomainException $e) {
            self::assertStringNotContainsString('etat_interne_inconnu', $e->getMessage());
        }
    }

    public function test_member_view_keys_are_a_fixed_whitelist(): void
    {
        $base = ['reference', 'type', 'received_at', 'status'];
        $ids = [];
        foreach (SocialWorkflow::STATUSES as $state) {
            if ($state !== 'en_paiement') {
                $ids[$state] = $this->requestIn($state);
            }
        }
        $ids['en_paiement'] = $this->requestIn('en_paiement');
        $expected = [
            'recue' => $base, 'en_instruction' => $base, 'soumise_decision' => $base,
            'en_attente_pieces' => [...$base, 'documents_requested'],
            'accordee' => [...$base, 'decision', 'message', 'amount_granted'],
            'en_paiement' => [...$base, 'decision', 'message', 'amount_granted'],
            'refusee' => [...$base, 'decision', 'message'],
            'cloturee' => [...$base, 'decision', 'message'], // aide non financière : pas de montant
        ];
        $rows = array_column((new SocialMemberView($this->db))->requestsOf($this->memberA), null, 'reference');
        self::assertCount(count($ids), $rows);
        foreach ($ids as $state => $id) {
            $ref = (string) $this->db->fetchValue('SELECT reference FROM social_requests WHERE id = ?', [$id]);
            self::assertSame($expected[$state], array_keys($rows[$ref]), $state);
            self::assertSame(SocialMemberView::STATUS[$state], $rows[$ref]['status']);
        }
        $granted = $rows[(string) $this->db->fetchValue('SELECT reference FROM social_requests WHERE id = ?', [$ids['accordee']])];
        self::assertSame(5_000_000, $granted['amount_granted']);
        self::assertSame('Accordée', $granted['decision']);
        self::assertSame([], (new SocialMemberView($this->db))->requestsOf($this->memberB), 'jamais les demandes d\'un autre membre');
    }

    public function test_member_view_never_contains_notes_reviews_staff_names_or_reason(): void
    {
        $staff = $this->socialUser('SENTINELLE-NOM-GESTIONNAIRE', ['so_manage']);
        $decider = $this->socialUser('SENTINELLE-NOM-DECIDEUR', ['so_decide']);
        $s = $this->socialSvc();
        $id = $this->registerRequest(['description' => 'SENTINELLE-DESCRIPTION']);
        $s->assign($id, $staff, $staff);
        $s->requestDocuments($id, 'Ordonnance à fournir', $staff);
        $s->resumeInstruction($id, $staff, 'SENTINELLE-COMMENTAIRE');
        $s->addReview($id, 'favorable', 4_000_000, 'SENTINELLE-AVIS', $staff);
        $s->submitForDecision($id, $staff);
        $s->decide($id, $this->grant(['reason' => 'SENTINELLE-MOTIF', 'decision_ref' => 'SENTINELLE-REF', 'deciding_body' => 'SENTINELLE-ORGANE',
            'member_message' => 'Votre aide est accordée.']), $decider);
        $this->db->insert('social_request_notes', ['social_request_id' => $id, 'author_id' => $staff, 'body' => 'SENTINELLE-NOTE']);
        $this->db->insert('social_request_medical', ['social_request_id' => $id, 'details' => 'SENTINELLE-SANTE', 'updated_by' => $staff]);
        $this->db->query("UPDATE social_requests SET closure_note = 'SENTINELLE-CLOTURE' WHERE id = ?", [$id]);
        $out = json_encode((new SocialMemberView($this->db))->requestsOf($this->memberA), JSON_UNESCAPED_UNICODE);
        self::assertStringNotContainsString('SENTINELLE', (string) $out);
        self::assertStringContainsString('Votre aide est accordée.', (string) $out);
        self::assertStringNotContainsString('Ordonnance', (string) $out, 'pièces demandées : seulement pendant l\'attente');
        self::assertStringNotContainsString('accordee', (string) $out, 'jamais un code interne');
    }
}
