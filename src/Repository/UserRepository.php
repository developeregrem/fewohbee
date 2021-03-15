<?php

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Bridge\Doctrine\Security\User\UserLoaderInterface;

/**
 * @method User|null find($id, $lockMode = null, $lockVersion = null)
 * @method User|null findOneBy(array $criteria, array $orderBy = null)
 * @method User[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface, UserLoaderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * Used to upgrade (rehash) the user's password automatically over time.
     */
    public function upgradePassword(UserInterface $user, string $newEncodedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', \get_class($user)));
        }

        $user->setPassword($newEncodedPassword);
        $this->_em->persist($user);
        $this->_em->flush();
    }

    public function loadUserByUsername(string $username) {
        $q = $this
            ->createQueryBuilder('u')
            ->select('u')
            ->where('u.username = :username')
            ->andWhere('u.active = :active')
            ->setParameter('username', $username)
            ->setParameter("active", true)
            ->getQuery();

        try {
            // The Query::getSingleResult() method throws an exception
            // if there is no record matching the criteria.
            $user = $q->getSingleResult();
        } catch (NoResultException $e) {
            $message = sprintf(
                'Unable to find an active User object identified by "%s".',
                $username
            );
            throw new BadCredentialsException($message, 0, $e);
        }

        return $user;
    }
    
    public function findAll()
    {
        return $this->findBy(array(), array('id' => 'ASC'));
    }

    /**
     * Sagt aus, ob ein Nutzername bereits in Verwendung ist
     * @param string $username
     * @return boolean
     */
    public function isUsernameAvailable($username) {
        $query = $this->createQueryBuilder('u')
            ->select('COUNT(u.username)')
            ->where('u.username = :un')
            ->setParameter('un', $username)
            ->getQuery();

        return ($query->getSingleScalarResult() == 0 ? true : false);
    }
}
