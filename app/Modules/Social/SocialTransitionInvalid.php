<?php
declare(strict_types=1);

namespace App\Modules\Social;

use DomainException;

/** Passage d'état non prévu par SocialWorkflow::TRANSITIONS. Message en français, sûr à afficher. */
final class SocialTransitionInvalid extends DomainException
{
}
