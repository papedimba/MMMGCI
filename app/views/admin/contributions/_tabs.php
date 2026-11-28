<?php declare(strict_types=1);
/** @var string $current */
echo ui('tabs', ['label' => 'Cotisations', 'items' => [['Types', '/admin/cotisations/types', $current === 'types'], ['Appels', '/admin/cotisations/appels', $current === 'appels'], ['Impayés', '/admin/cotisations/impayes', $current === 'impayes']]]);
