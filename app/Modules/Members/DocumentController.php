<?php
declare(strict_types=1);

namespace App\Modules\Members;

use App\Core\Audit;
use App\Core\Database;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\NotFound;
use App\Core\Session;
use App\Core\UploadError;
use App\Core\Uploads;
use App\Modules\AuditLog\SensitiveAccess;

/**
 * Pièces et photo d'un adhérent, sur le disque privé : jamais d'URL directe, toujours un contrôle de périmètre puis un flux par le contrôleur.
 * Pièces : members.documents.view (posée par routes/admin.php) et consultation journalisée ; photo : members.view dans le périmètre.
 */
final class DocumentController
{
    public function __construct(private Database $db, private Session $session, private MemberScope $scope, private SensitiveAccess $sensitive,
        private Uploads $uploads, private Audit $audit)
    {
    }

    public function upload(Request $r): Response
    {
        $userId = (int) $r->attribute('user')['id'];
        $id = (int) $r->attribute('id');
        $this->scope->assertMayAccess($userId, $id);
        $back = Response::redirect("/admin/adherents/$id?onglet=pieces");
        $type = $r->post('type');
        if (!is_string($type) || $this->db->fetchValue('SELECT 1 FROM member_document_types WHERE code = ?', [$type]) === null) {
            $this->session->flash('error', 'Type de pièce invalide : choisissez-le dans la liste proposée.');
            return $back;
        }
        $file = $r->file('piece');
        try {
            if ($file === null || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                throw new UploadError('Choisissez un fichier à déposer.');
            }
            $this->db->transaction(function () use ($file, $type, $id, $userId): void {
                $fileId = $this->uploads->store($file, 'document', false, $userId);
                $docId = $this->db->insert('member_documents', ['member_id' => $id, 'type' => $type, 'file_id' => $fileId]);
                $this->audit->log($userId, 'member.document_added', 'member', $id, null, ['document_id' => $docId, 'type' => $type, 'file_id' => $fileId]);
            });
        } catch (UploadError $e) {
            $this->session->flash('error', $e->getMessage());
            return $back;
        }
        $this->session->flash('status', 'Pièce enregistrée.');
        return $back;
    }

    public function download(Request $r): Response
    {
        $userId = (int) $r->attribute('user')['id'];
        $id = (int) $r->attribute('id');
        $this->scope->assertMayAccess($userId, $id);
        // Le fichier doit être rattaché à CET adhérent : deviner l'identifiant d'un autre fichier donne 404.
        $file = $this->db->fetch('SELECT f.id, f.mime, f.original_name FROM member_documents d JOIN files f ON f.id = d.file_id WHERE d.member_id = ? AND d.file_id = ?',
            [$id, (int) $r->attribute('fid')]) ?? throw new NotFound('Pièce introuvable.');
        $res = $this->stream($file, 'attachment');
        $this->sensitive->record($userId, 'member', $id, 'document', $r->ip()); // après la lecture réussie du fichier
        return $res;
    }

    public function photo(Request $r): Response
    {
        $id = (int) $r->attribute('id');
        $this->scope->assertMayAccess((int) $r->attribute('user')['id'], $id);
        $file = $this->db->fetch('SELECT f.id, f.mime, f.original_name FROM members m JOIN files f ON f.id = m.photo_file_id WHERE m.id = ?', [$id])
            ?? throw new NotFound('Photo introuvable.');
        return $this->stream($file, 'inline');
    }

    /** @param array<string,mixed> $file */
    private function stream(array $file, string $disposition): Response
    {
        try {
            $body = (string) file_get_contents($this->uploads->path((int) $file['id']));
        } catch (\InvalidArgumentException) {
            throw new NotFound('Fichier introuvable.');
        }
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $file['original_name']) ?: 'piece';
        return new Response($body, 200, [
            'Content-Type' => (string) $file['mime'],
            'Content-Length' => (string) strlen($body),
            'Content-Disposition' => $disposition . '; filename="' . $name . '"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
