<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\Customer;
use App\Entity\CustomerAddresses;
use App\Entity\Enum\ApiScope;
use App\Mcp\Security\McpRequiresScope;
use App\Mcp\Security\McpToolException;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;

/**
 * Guest lookup, e.g. to book a returning guest. Needs guests:read: names and contact details leave
 * the installation.
 */
final class GuestTools
{
    private const MIN_QUERY_LENGTH = 3;
    private const MAX_RESULTS = 20;

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'search_guests',
        title: 'Search guests',
        description: 'Finds guests by name, company or email (at least 3 characters, at most 20 results). Returns the customer id for create_reservation and basic contact data.',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    #[McpRequiresScope(ApiScope::GUESTS_READ)]
    public function search(
        #[Schema(description: 'Part of the name, company or email address.', minLength: 3, maxLength: 100)]
        string $query,
    ): array {
        $query = trim($query);
        if (mb_strlen($query) < self::MIN_QUERY_LENGTH) {
            throw McpToolException::invalid(\sprintf("'query' needs at least %d characters.", self::MIN_QUERY_LENGTH));
        }

        // Escape LIKE wildcards so the query cannot enumerate every guest.
        $pattern = '%'.addcslashes(mb_substr($query, 0, 100), '%_\\').'%';

        /** @var list<Customer> $customers */
        $customers = $this->em->getRepository(Customer::class)->createQueryBuilder('c')
            ->leftJoin('c.customerAddresses', 'a')
            // id 1 is the shared "anonymous" customer that anonymized guests are moved to.
            ->where('c.id > 1')
            ->andWhere('c.lastname LIKE :q OR c.firstname LIKE :q OR a.company LIKE :q OR a.email LIKE :q')
            ->setParameter('q', $pattern)
            ->orderBy('c.lastname', 'ASC')
            ->addOrderBy('c.firstname', 'ASC')
            ->distinct()
            ->setMaxResults(self::MAX_RESULTS)
            ->getQuery()
            ->getResult();

        $guests = [];
        foreach ($customers as $customer) {
            $address = $customer->getCustomerAddresses()->first();
            $address = $address instanceof CustomerAddresses ? $address : null;
            $guests[] = [
                'customerId' => $customer->getId(),
                'firstname' => $customer->getFirstname(),
                'lastname' => $customer->getLastname(),
                'company' => $address?->getCompany(),
                'email' => $address?->getEmail(),
                'city' => $address?->getCity(),
                'country' => $address?->getCountry(),
            ];
        }

        return ['guests' => $guests, 'limit' => self::MAX_RESULTS];
    }
}
