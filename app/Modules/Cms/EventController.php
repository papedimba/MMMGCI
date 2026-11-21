<?php
declare(strict_types=1);

namespace App\Modules\Cms;

use App\Core\Csrf;
use App\Core\Gate;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Session;
use App\Core\View;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;

/** Écrans des évènements (/admin/site/evenements). Le droit de publier est vérifié par EventService::transition. */
final class EventController extends CmsScreen
{
    private const BASE = '/admin/site/evenements';
    private const FIELDS = ['title', 'slug', 'description', 'starts_at', 'ends_at', 'location', 'featured_media_id'];
    private const KINDS = ['brouillon' => 'neutral', 'en_relecture' => 'warn', 'publie' => 'ok', 'programme' => 'info', 'archive' => 'neutral'];

    public function __construct(View $view, Csrf $csrf, Session $session, Gate $gate, private EventService $events)
    {
        parent::__construct($view, $csrf, $session, $gate);
    }

    public function index(Request $r): Response
    {
        $fmt = static fn (?string $d): string => $d === null ? '—' : (new DateTimeImmutable($d))->format('d/m/Y H:i');
        $columns = [
            ['title', 'Titre', '', false, static fn (array $a): string => '<a href="' . e(self::BASE . '/' . $a['id']) . '">' . e($a['title']) . '</a> <span class="page-slug">/' . e($a['slug']) . '</span>'],
            ['starts_at', 'Début', '', false, static fn (array $a): string => e($fmt($a['starts_at']))],
            ['ends_at', 'Fin', '', false, static fn (array $a): string => e($fmt($a['ends_at']))],
            ['location', 'Lieu'],
            ['status', 'État', '', false, static fn (array $a): string => ui('badge', ['kind' => self::KINDS[$a['status']] ?? 'neutral', 'label' => EventService::STATUS_LABELS[$a['status']] ?? $a['status']]), 'status'],
        ];
        return $this->render($r, 'admin/cms/simple-index', ['title' => 'Évènements', 'subtitle' => 'Agenda du site public. Un évènement n\'est visible du public qu\'une fois publié.',
            'base' => self::BASE, 'newLabel' => 'Nouvel évènement', 'rows' => $this->events->all(), 'columns' => $columns, 'notice' => '']);
    }

    public function createForm(Request $r): Response
    {
        return $this->form($r, null, [], []);
    }

    public function create(Request $r): Response
    {
        return $this->save($r, null);
    }

    public function editForm(Request $r): Response
    {
        return $this->found(function () use ($r): Response {
            $e = $this->events->get($this->id($r));
            return $this->form($r, $e, $this->valuesOf($e), []);
        });
    }

    public function update(Request $r): Response
    {
        return $this->found(fn (): Response => $this->save($r, $this->events->get($this->id($r))));
    }

    public function transition(Request $r): Response
    {
        return $this->found(function () use ($r): Response {
            $id = $this->id($r);
            $to = is_string($r->post('to')) ? $r->post('to') : '';
            try {
                if (!in_array($to, ContentWorkflow::STATUSES, true)) {
                    throw new ContentTransitionInvalid('Action inconnue.');
                }
                $seen = is_string($r->post('version')) && ctype_digit($r->post('version')) ? (int) $r->post('version') : -1;
                $at = null;
                if ($to === 'programme') {
                    $raw = $r->post('at');
                    $at = is_string($raw) ? DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $raw, new DateTimeZone('Africa/Abidjan')) : false;
                    $at = $at === false ? null : $at;
                }
                $this->events->transition($id, $to, $this->actor($r), $at, $seen);
            } catch (DomainException $e) {
                return $this->editWithError($r, $id, $e->getMessage());
            }
            $this->session->flash('status', 'État de l\'évènement : ' . strtolower(EventService::STATUS_LABELS[(string) $this->events->get($id)['status']]) . '.');
            return Response::redirect(self::BASE . '/' . $id);
        });
    }

    public function delete(Request $r): Response
    {
        return $this->found(function () use ($r): Response {
            $id = $this->id($r);
            try {
                $this->events->delete($id, $this->actor($r));
            } catch (DomainException $e) {
                return $this->editWithError($r, $id, $e->getMessage());
            }
            $this->session->flash('status', 'Évènement supprimé.');
            return Response::redirect(self::BASE);
        });
    }

    /** @param array<string,mixed>|null $event */
    private function save(Request $r, ?array $event): Response
    {
        if ($r->post('_form_complete') !== '1') {
            return $this->form($r, $event, $event === null ? [] : $this->valuesOf($event), ['form' => 'Le formulaire est arrivé incomplet (trop de champs). Rien n\'a été enregistré.'], 422);
        }
        $values = [];
        foreach (self::FIELDS as $k) {
            $v = $r->post($k, '');
            $values[$k] = is_string($v) ? $v : '';
        }
        try {
            if ($event === null) {
                $id = $this->events->create($values, $this->actor($r));
            } else {
                $id = (int) $event['id'];
                $this->events->update($id, $values, $this->actor($r));
            }
        } catch (PageInvalid $e) {
            return $this->form($r, $event, $values, $e->errors, 422);
        } catch (DomainException $e) {
            return $this->form($r, $event, $values, ['form' => $e->getMessage()], 422);
        }
        $this->session->flash('status', 'Évènement enregistré.');
        return Response::redirect(self::BASE . '/' . $id);
    }

    /** @param array<string,mixed> $e @return array<string,string> */
    private function valuesOf(array $e): array
    {
        $v = [];
        foreach (self::FIELDS as $k) {
            $v[$k] = (string) ($e[$k] ?? '');
        }
        $v['starts_at'] = CmsContent::inputDate($e['starts_at'] === null ? null : (string) $e['starts_at']);
        $v['ends_at'] = CmsContent::inputDate($e['ends_at'] === null ? null : (string) $e['ends_at']);
        return $v;
    }

    private function editWithError(Request $r, int $id, string $message): Response
    {
        $e = $this->events->get($id);
        return $this->form($r, $e, $this->valuesOf($e), ['form' => $message], 422);
    }

    /** @param array<string,mixed>|null $event @param array<string,string> $values @param array<string,string> $errors */
    private function form(Request $r, ?array $event, array $values, array $errors, int $status = 200): Response
    {
        $mayPublish = $this->mayPublish($r);
        return $this->render($r, 'admin/cms/events/form', [
            'title' => $event === null ? 'Nouvel évènement' : 'Modifier l\'évènement', 'base' => self::BASE, 'event' => $event, 'values' => $values, 'errors' => $errors,
            'errorSummary' => self::summary($errors), 'images' => ['' => '— Aucune —'] + $this->events->mediaChoices(GalleryService::IMAGES), 'mayPublish' => $mayPublish,
            'locked' => $event !== null && $event['published_by'] !== null && !$mayPublish,
            'transitions' => $event === null ? [] : ContentWorkflow::transitionsFor((string) $event['status'], $mayPublish),
        ], $status);
    }
}
