<?php
declare(strict_types=1);

namespace App\Core\Http;

use App\Core\Audit;
use App\Core\View;
use App\Modules\Auth\Auth;

/** Fabrique paresseuse de RequirePermission dont la permission reste lisible sans rien construire (balayage des routes). */
final class PermissionGuard
{
    /** @param \Closure(): Auth $auth @param \Closure(): Audit $audit */
    public function __construct(public readonly string $permission, private \Closure $auth, private View $view, private \Closure $audit)
    {
    }

    public function __invoke(): RequirePermission
    {
        return new RequirePermission(($this->auth)(), $this->permission, $this->view, ($this->audit)());
    }
}
