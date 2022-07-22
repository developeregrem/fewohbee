<?php

/*
 * This file is part of the guesthouse administration package.
 *
 * (c) Alexander Elchlepp <info@fewohbee.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\EventSubscriber;

use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Doctrine\ORM\EntityManagerInterface;

use App\Entity\User;

/**
 * Subscription to update the last user action time
 *
 * @author Alex
 */
class LastActionSubscriber implements EventSubscriberInterface {
    private EntityManagerInterface $em;
    
    public function __construct(private readonly ManagerRegistry $doctrine, private readonly TokenStorageInterface $tokenStorage) {
        $this->em = $doctrine->getManager(('background'));
    }

    public static function getSubscribedEvents(): array {
        // return the subscribed events, their methods and priorities
        return array(
           KernelEvents::FINISH_REQUEST => array(
               array('updateLastAction', -10),
           )
        );
    }

    public function updateLastAction(FinishRequestEvent $event) {
        $accessToken = $this->tokenStorage->getToken();
        if($accessToken !== null) {
            /* @var $user User */
            $user = $accessToken->getUser();
            if($user instanceof User) {
                // load user using a different entity manager
                // this prevents unintended behavior e.g. when a user is edited with violations this flush here would course that the fields are updated anyway
                $user2 = $this->em->getRepository(User::class)->find($user->getId());

                if($this->em->isOpen()) {
                    $user2->setLastAction(new \DateTime());
                    $this->em->persist($user2);
                    $this->em->flush();
                }
            }
        }
    }
}
