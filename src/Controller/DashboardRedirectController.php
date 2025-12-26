<?php

declare(strict_types=1);

/*
 * This file is part of the guesthouse administration package.
 *
 * (c) Alexander Elchlepp <info@fewohbee.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

class DashboardRedirectController extends AbstractController
{
    #[Route('/dashboard', name: 'dashboard.redirect', methods: ['GET'])]
    public function __invoke(): RedirectResponse
    {
        $roleRouteMap = [
            'ROLE_RESERVATIONS' => 'start',
            'ROLE_CUSTOMERS' => 'customers.overview',
            'ROLE_INVOICES' => 'invoices.overview',
            'ROLE_REGISTRATIONBOOK' => 'registrationbook.overview',
            'ROLE_STATISTICS' => 'statistics.utilization',
            'ROLE_CASHJOURNAL' => 'cashjournal.overview',
            'ROLE_RESERVATIONS_RO' => 'start',
        ];

        foreach ($roleRouteMap as $role => $route) {
            if ($this->isGranted($role)) {
                return $this->redirectToRoute($route);
            }
        }

        throw new AccessDeniedException('No accessible section configured for this user.');
    }
}
