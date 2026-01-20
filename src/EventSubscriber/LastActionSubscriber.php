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

namespace App\EventSubscriber;

use App\Entity\User;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Throwable;

/**
 * Subscription to update the last user action time.
 *
 * @author Alex
 */
class LastActionSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly ManagerRegistry $doctrine, private readonly TokenStorageInterface $tokenStorage)
    {
    }

    public static function getSubscribedEvents(): array
    {
        // return the subscribed events, their methods and priorities
        return [
            KernelEvents::FINISH_REQUEST => [
                ['updateLastAction', -10],
            ],
        ];
    }

    public function updateLastAction(FinishRequestEvent $event): void
    {
        $accessToken = $this->tokenStorage->getToken();
        if (null !== $accessToken) {
            /* @var $user User */
            $user = $accessToken->getUser();
            if ($user instanceof User) {
                $userId = $user->getId();
                if (null === $userId) {
                    return;
                }
                try {
                    $em = $this->doctrine->getManager('background');
                    if (!$em->isOpen()) {
                        $em = $this->doctrine->resetManager('background');
                    }

                    $em->createQuery('UPDATE App\Entity\User u SET u.lastAction = :ts WHERE u.id = :id')
                        ->setParameter('ts', new \DateTimeImmutable())
                        ->setParameter('id', $userId)
                        ->execute();
                } catch (Throwable) {
                    // best-effort: never block the request for lastAction updates
                }
            }
        }
    }
}
