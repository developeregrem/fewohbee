<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\NoResultException;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Security\User\UserLoaderInterface;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use Symfony\Component\Security\Core\User\UserInterface;

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
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newEncodedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newEncodedPassword);
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }

    public function loadUserByIdentifier(string $username): ?UserInterface
    {
        $q = $this
            ->createQueryBuilder('u')
            ->select('u')
            ->where('u.username = :username')
            ->andWhere('u.active = :active')
            ->setParameter('username', $username)
            ->setParameter('active', true)
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

    public function findAll(): array
    {
        return $this->findBy([], ['id' => 'ASC']);
    }

    /**
     * Look up the account bound to an identity provider subject. Both parts of
     * the pair matter: the same "sub" from a different issuer is a different
     * person.
     */
    public function findOneByOidcIdentity(string $issuer, string $subject): ?User
    {
        return $this->createQueryBuilder('u')
            ->where('u.oidcIssuer = :issuer')
            ->andWhere('u.oidcSubject = :subject')
            ->setParameter('issuer', $issuer)
            ->setParameter('subject', $subject)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * All accounts carrying this e-mail address. The column has no unique
     * constraint, so duplicates are possible — callers that use the address to
     * identify a person must treat more than one hit as ambiguous rather than
     * picking the first.
     *
     * @return list<User>
     */
    public function findByEmailAddress(string $email): array
    {
        return $this->createQueryBuilder('u')
            ->where('LOWER(u.email) = LOWER(:email)')
            ->setParameter('email', $email)
            ->getQuery()
            ->getResult();
    }

    /**
     * Sagt aus, ob ein Nutzername bereits in Verwendung ist.
     */
    public function isUsernameAvailable(string $username): bool
    {
        $query = $this->createQueryBuilder('u')
            ->select('COUNT(u.username)')
            ->where('u.username = :un')
            ->setParameter('un', $username)
            ->getQuery();

        return 0 == $query->getSingleScalarResult();
    }
}
