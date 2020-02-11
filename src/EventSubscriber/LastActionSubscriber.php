<?php

/*
 * This file is part of the guesthouse administration package.
 *
 * (c) Alexander Elchlepp <alex.pensionsverwaltung@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\EventSubscriber;

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
    private $em;
    private $tokenStorage;
    
    public function __construct(EntityManagerInterface $em, TokenStorageInterface $tokenStorage) {
        $this->em = $em;
        $this->tokenStorage = $tokenStorage;
    }
    public static function getSubscribedEvents()
    {
        // return the subscribed events, their methods and priorities
        return array(
           KernelEvents::FINISH_REQUEST => array(
               array('updateLastAction', -10),
           )
        );
    }

    public function updateLastAction(FinishRequestEvent $event)
    { 
        $accessToken = $this->tokenStorage->getToken();
        if($accessToken !== null) {
            /* @var $user User */
            $user = $accessToken->getUser();
            if($user instanceof User) {
                $user->setLastAction(new \DateTime());
                $this->em->persist($user);
            }
        }
    }
}
