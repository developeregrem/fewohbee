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

namespace App\Service;

use App\Entity\Customer;
use App\Entity\CustomerAddresses;
use App\Entity\Invoice;
use App\Entity\InvoiceAppartment;
use App\Entity\InvoicePosition;
use App\Entity\InvoiceSettingsData;
use App\Entity\Price;
use App\Entity\Reservation;
use App\Entity\Template;
use App\Entity\Enum\InvoiceStatus;
use App\Service\EInvoice\EInvoiceExportService;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use horstoeko\zugferd\ZugferdDocumentPdfMerger;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;
use App\Service\AppSettingsService;

class InvoiceService
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly PriceService $ps, private readonly TranslatorInterface $translator, private readonly AppSettingsService $appSettingsService)
    {
    }

    /**
     * Calculates the sums and vats for an invoice.
     *
     * @param array $apps            The invoice positions for apartment prices
     * @param array $poss            The invoice positions for miscellaneous prices
     * @param array $vats            Returns array of all vat values
     * @param float $brutto          Returns the total price including vat
     * @param float $netto           Returns the toal price for all vats
     * @param float $appartmentTotal Returns the total sum for all apartment prices
     * @param float $miscTotal       Returns the total price for all miscellaneous prices
     */
    public function calculateSums(Collection $apps, Collection $poss, array &$vats, float &$brutto, float &$netto, float &$appartmentTotal, float &$miscTotal): void
    {
        $vats = [];
        $brutto = 0.0;
        $netto = 0.0;
        $appartmentTotal = 0.0;
        $miscTotal = 0.0;

        /* @var $apartment InvoiceAppartment */
        // $apps = $invoice->getAppartments();
        // $poss = $invoice->getPositions();
        foreach ($apps as $apartment) {
            $apartmentPrice = ($apartment->getIsFlatPrice() ? $apartment->getPrice() : $apartment->getAmount() * $apartment->getPrice());

            if ($apartment->getIncludesVat()) { // price includes vat
                $vatAmount = (($apartmentPrice * $apartment->getVat()) / (100 + $apartment->getVat()));
                $bruttoAmount = $apartmentPrice;
            } else { // price does not include vat
                $vatAmount = (($apartmentPrice * $apartment->getVat()) / 100);
                $bruttoAmount = $apartmentPrice + $vatAmount;
            }

            $vats[$apartment->getVat()]['brutto'] = ($vats[$apartment->getVat()]['brutto'] ?? 0) + $bruttoAmount;
            $vats[$apartment->getVat()]['netto'] = ($vats[$apartment->getVat()]['netto'] ?? 0) + $vatAmount;
            $vats[$apartment->getVat()]['netSum'] = ($vats[$apartment->getVat()]['netSum'] ?? 0) + $bruttoAmount - $vatAmount;
            $appartmentTotal += $apartmentPrice;
        }

        foreach ($poss as $pos) {
            $miscPrice = ($pos->getIsFlatPrice() ? $pos->getPrice() : $pos->getAmount() * $pos->getPrice());

            if ($pos->getIncludesVat()) { // price includes vat
                $vatAmount = (($miscPrice * $pos->getVat()) / (100 + $pos->getVat()));
                $bruttoAmount = $miscPrice;
            } else { // price does not include vat
                $vatAmount = (($miscPrice * $pos->getVat()) / 100);
                $bruttoAmount = $miscPrice + $vatAmount;
            }

            $vats[$pos->getVat()]['brutto'] = ($vats[$pos->getVat()]['brutto'] ?? 0) + $bruttoAmount;
            $vats[$pos->getVat()]['netto'] = ($vats[$pos->getVat()]['netto'] ?? 0) + $vatAmount;
            $vats[$pos->getVat()]['netSum'] = ($vats[$pos->getVat()]['netSum'] ?? 0) + $bruttoAmount - $vatAmount;
            $miscTotal += $miscPrice;
        }

        foreach ($vats as $key => $vat) {
            $brutto += round($vat['brutto'], 2);
            $netto += round($vat['netto'], 2);
            $vats[$key]['nettoFormated'] = number_format(round($vat['netto'], 2), 2, ',', '.');
        }
        ksort($vats);
    }

    /**
     * Loops through all Periods and removes dublicate (same) entries.
     *
     * @param Invoice $invoice
     *
     * @return array
     */
    public function getUniqueReservationPeriods($invoice)
    {
        $arr = [];
        $i = 0;
        foreach ($invoice->getAppartments() as $appartment) {
            $found = false;
            foreach ($arr as $periods) {
                if ($periods['startDate'] == $appartment->getStartDate() && $periods['endDate'] == $appartment->getEndDate()) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $arr[$i]['startDate'] = $appartment->getStartDate();
                $arr[$i]['endDate'] = $appartment->getEndDate();
                ++$i;
            }
        }

        return $arr;
    }

    /**
     * Loops through all Appartments and removes dublicate (same) entries (by number).
     *
     * @param Invoice $invoice
     *
     * @return array
     */
    public function getUniqueAppartmentsNumber($invoice)
    {
        $arr = [];
        foreach ($invoice->getAppartments() as $appartment) {
            $found = false;
            foreach ($arr as $numbers) {
                if ($numbers['number'] == $appartment->getNumber()) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $arr[]['number'] = $appartment->getNumber();
            }
        }

        return $arr;
    }

    /**
     * Delets Invoice and all dependencies.
     *
     * @param type $id The ID of the invoice
     *
     * @return bool
     */
    public function deleteInvoice($id)
    {
        $invoice = $this->em->getRepository(Invoice::class)->find($id);

        if ($invoice instanceof Invoice) {
            $reservations = $invoice->getReservations();
            foreach ($reservations as $reservation) {
                $reservation->removeInvoice($invoice);
                $this->em->persist($reservation);
            }
            $positions = $invoice->getPositions();
            foreach ($positions as $position) {
                $this->em->remove($position);
            }
            $appartments = $invoice->getAppartments();
            foreach ($appartments as $appartment) {
                $this->em->remove($appartment);
            }
            $this->em->persist($invoice);

            $this->em->remove($invoice);
            $this->em->flush();

            return true;
        } else {
            return false;
        }
    }

    /**
     * Build all invoice variables required by template rendering.
     */
    public function buildTemplateRenderParams(Template $template, Invoice $invoice): array
    {
        $vatSums = [];
        $brutto = 0;
        $netto = 0;
        $appartmantTotal = 0;
        $miscTotal = 0;
        // calculate needed values for template
        $this->calculateSums(
            $invoice->getAppartments(),
            $invoice->getPositions(),
            $vatSums,
            $brutto,
            $netto,
            $appartmantTotal,
            $miscTotal
        );

        $periods = $this->getUniqueReservationPeriods($invoice);
        $appartmentNumbers = $this->getUniqueAppartmentsNumber($invoice);

        $params = [
            'invoice' => $invoice,
            'vats' => $vatSums,
            'brutto' => $brutto,
            'netto' => $netto,
            'bruttoFormated' => number_format($brutto, 2, ',', '.'),
            'nettoFormated' => number_format($brutto - $netto, 2, ',', '.'),
            'periods' => $periods,
            'numbers' => $appartmentNumbers,
            'appartmentTotal' => number_format($appartmantTotal, 2, ',', '.'),
            'miscTotal' => number_format($miscTotal, 2, ',', '.'),
        ];

        return $params;
    }

    public function generateInvoicePdfXml(TemplatesService $ts, EInvoiceExportService $einvoice, Invoice $invoice, Template $template, InvoiceSettingsData $invoiceSettings): string
    {
        $templateOutput = $ts->renderTemplate($template->getId(), $invoice->getId());
        $pdfOutput = $ts->getPDFOutput($templateOutput, $this->buildInvoiceExportFilename($invoice, true), $template, true);
        $xml = $einvoice->generateInvoiceData($invoice, $invoiceSettings);

        return (new ZugferdDocumentPdfMerger($xml, $pdfOutput))
            ->generateDocument()
            ->downloadString();
    }

    /**
     * Builds a sanitized invoice export filename (without extension) from the configured pattern.
     */
    public function buildInvoiceExportFilename(Invoice $invoice, bool $appendEinvoiceSuffix = false): string
    {
        $fileNamePattern = $this->appSettingsService->getSettings()->getInvoiceFilenamePattern();
        $pattern = trim($fileNamePattern);
        if ('' === $pattern) {
            $pattern = $this->translator->trans('invoice.number.short') . '-<number>';
        }

        $statusLabel = '';
        $statusEnum = InvoiceStatus::fromStatus($invoice->getStatus());
        if (null !== $statusEnum) {
            $statusLabel = $this->translator->trans($statusEnum->labelKey());
        }

        $paymentLabel = '';
        $paymentMeans = $invoice->getPaymentMeans();
        if (null !== $paymentMeans) {
            $paymentLabel = $this->translator->trans($paymentMeans->name);
        }

        $replacements = [
            'company' => (string) $invoice->getCompany(),
            'lastname' => (string) $invoice->getLastname(),
            'firstname' => (string) $invoice->getFirstname(),
            'status' => $statusLabel,
            'payment' => $paymentLabel,
            'paymentmeans' => $paymentLabel,
            'payment_means' => $paymentLabel,
            'number' => (string) $invoice->getNumber(),
            'date' => $invoice->getDate()->format('Y-m-d'),
        ];

        $filename = preg_replace_callback('/<([a-zA-Z_|]+)>/', static function (array $matches) use ($replacements): string {
            $keys = array_filter(array_map('trim', explode('|', strtolower($matches[1]))));
            foreach ($keys as $key) {
                $value = $replacements[$key] ?? '';
                if ('' !== trim((string) $value)) {
                    return (string) $value;
                }
            }

            return '';
        }, $pattern);

        if ($appendEinvoiceSuffix) {
            $filename .= '_einvoice';
        }

        return $this->sanitizeFilename($filename);
    }

    /**
     * Converts a raw filename to a safe ASCII-only filename.
     */
    public function sanitizeFilename(string $value): string
    {
        $value = $this->replaceGermanUmlauts($value);
        $value = transliterator_transliterate('Any-Latin; Latin-ASCII', $value);
        $value = preg_replace('/\s+/', '_', $value);
        $value = preg_replace('/[^A-Za-z0-9._-]/', '', $value);
        $value = preg_replace('/_+/', '_', $value);
        $value = preg_replace('/-+/', '-', $value);
        $value = trim($value, "._-");

        return '' !== $value ? $value : 'invoice';
    }

    /**
     * Replaces German umlauts and Eszett with ASCII equivalents.
     */
    private function replaceGermanUmlauts(string $value): string
    {
        return strtr($value, [
            'ä' => 'ae',
            'ö' => 'oe',
            'ü' => 'ue',
            'Ä' => 'Ae',
            'Ö' => 'Oe',
            'Ü' => 'Ue',
            'ß' => 'ss',
        ]);
    }

    /**
     * Retrieves valid prices for each day of stay and prefills the apartment position for the reservation
     * each day has exactly one valid price category.
     */
    public function prefillAppartmentPositions(Reservation $reservation, RequestStack $requestStack): void
    {
        foreach ($this->buildAppartmentPositions($reservation) as $position) {
            $this->saveNewAppartmentPosition($position, $requestStack);
        }
    }

    /**
     * Build apartment invoice positions for a reservation without session dependency.
     *
     * @return InvoiceAppartment[]
     */
    public function buildAppartmentPositions(Reservation $reservation): array
    {
        $prices = $this->ps->getPricesForReservationDays($reservation, 2);
        $days = $this->getDateDiff($reservation->getStartDate(), $reservation->getEndDate());

        $positions = [];
        $curDate = clone $reservation->getStartDate();
        $lastPrice = (null === $prices[0] ? null : $prices[0][0]);
        $start = clone $reservation->getStartDate();

        for ($i = 0; $i <= $days; ++$i) {
            // here we need to ignore the price of the last day because it's not the valid price e.g. booked from 01.01 - 02.01 we need to use the price for 01.01.
            // thats why we apply the prevois price for the last loop
            if ($i < $days) {
                $price = (null === $prices[$i] ? null : $prices[$i][0]);
            } else {
                $price = $lastPrice;
            }

            $curDate = (clone $curDate)->add(new \DateInterval('P'.(0 === $i ? 0 : 1).'D'));
            if (null !== $price && null !== $lastPrice && ($lastPrice->getId() !== $price->getId() || $i == $days)) {
                $positions[] = $this->makeAparmtentPosition($start, $curDate, $reservation, $lastPrice);

                $start = clone $curDate;
            }
            $lastPrice = $price;
        }    // loop must run one more time to add the position for the last day of stay

        return $positions;
    }

    /**
     * Retrieves valid prices for each day of stay and prefills the miscellaneous position for the reservation
     * each day can have more than one active price category.
     *
     * @param array $reservations      a list of reservations
     * @param bool  $useExistingPrices whether to use existing prices of the reservation or load prices based on price categories
     */
    public function prefillMiscPositionsWithReservations(array $reservations, RequestStack $requestStack, bool $useExistingPrices = false): void
    {
        $this->prefillMiscPositions($reservations, $requestStack, $useExistingPrices);
    }

    /**
     * Retrieves valid prices for each day of stay and prefills the miscellaneous position for the reservation
     * each day can have more than one active price category.
     *
     * @param array $reservationIds    a list of reservation IDs
     * @param bool  $useExistingPrices whether to use existing prices of the reservation or load prices based on price categories
     */
    public function prefillMiscPositionsWithReservationIds(array $reservationIds, RequestStack $requestStack, bool $useExistingPrices = false): void
    {
        $reservations = [];
        foreach ($reservationIds as $resId) {
            $reservation = $this->em->getRepository(Reservation::class)->find($resId);
            if ($reservation instanceof Reservation) {
                $reservations[] = $reservation;
            }
        }
        $this->prefillMiscPositions($reservations, $requestStack, $useExistingPrices);
    }

    /**
     * Retrieves valid prices for each day of stay and prefills the miscellaneous position for the reservation
     * each day can have more than one active price category.
     *
     * @param bool $useExistingPrices whether to use existing prices of the reservation or load prices based on price categories
     */
    private function prefillMiscPositions(array $reservations, RequestStack $requestStack, bool $useExistingPrices = false): void
    {
        $tmpMiscArr = $this->computeMiscPriceAggregates($reservations, $useExistingPrices);
        $this->makeMiscPositions($tmpMiscArr, $requestStack);
    }

    /**
     * Build miscellaneous invoice positions without writing to the session.
     *
     * @return InvoicePosition[]
     */
    public function buildMiscPositions(array $reservations, bool $useExistingPrices = false): array
    {
        $tmpMiscArr = $this->computeMiscPriceAggregates($reservations, $useExistingPrices);

        return $this->createMiscPositionsFromAggregates($tmpMiscArr);
    }

    /**
     * Aggregates price amounts across all reservations and days into a keyed array, without any session involvement.
     */
    private function computeMiscPriceAggregates(array $reservations, bool $useExistingPrices = false): array
    {
        $tmpMiscArr = [];
        $existingPrices = null;
        // loop over all selected reservations, this avoids dublicate entries in the result, prices that are equal will be aggregated
        foreach ($reservations as $reservation) {
            if (!$reservation instanceof Reservation) {
                continue;
            }

            if ($useExistingPrices) {
                $existingPrices = $reservation->getPrices()->filter(static fn (Price $price) => $price->getActive());
            }
            $prices = $this->ps->getPricesForReservationDays($reservation, 1, $existingPrices);

            $days = max(1, $this->getDateDiff($reservation->getStartDate(), $reservation->getEndDate()));

            // loop through each day and create the position based on the retrieved prices for this day
            for ($i = 1; $i <= $days; ++$i) {
                if (null === $prices[$i]) {
                    continue;
                }
                foreach ($prices[$i] as $price) {
                    $amount = ($price->getIsFlatPrice() || $price->getIsPerRoom() ? 1 : $reservation->getPersons());

                    // if key exists, add the current amount to the existing one, to have only one entry in the results list
                    // with the same price id but a total amount if the same price category occurs more than once
                    if (array_key_exists($price->getId(), $tmpMiscArr)) {
                        // add amount to an existing one only when it is not flat price or for another reservation (same flat price only once per reservation)
                        if (!$price->getIsFlatPrice() || ($price->getIsFlatPrice() && $tmpMiscArr[$price->getId()]['reservationId'] !== $reservation->getId())) {
                            $tmpMiscArr[$price->getId()]['amount'] += $amount;
                            $tmpMiscArr[$price->getId()]['reservationId'] = $reservation->getId();
                        }
                    } else {
                        $tmpMiscArr[$price->getId()] = [
                            'price' => $price,
                            'amount' => $amount,
                            'reservationId' => $reservation->getId(),
                        ];
                    }
                }
            }
        }

        return $tmpMiscArr;
    }

    /**
     * Stores a new apartment position in the session.
     */
    public function saveNewAppartmentPosition(InvoiceAppartment $position, RequestStack $requestStack): void
    {
        $newInvoicePositionsAppartmentsArray = $requestStack->getSession()->get('invoicePositionsAppartments', new ArrayCollection());
        $newInvoicePositionsAppartmentsArray[] = $position;

        $requestStack->getSession()->set('invoicePositionsAppartments', $newInvoicePositionsAppartmentsArray);
    }

    /**
     * Stores a new miscellaneous position in the session.
     */
    public function saveNewMiscPosition(InvoicePosition $position, RequestStack $requestStack): void
    {
        $newInvoicePositionsMiscArray = $requestStack->getSession()->get('invoicePositionsMiscellaneous', new ArrayCollection());
        $newInvoicePositionsMiscArray[] = $position;

        $requestStack->getSession()->set('invoicePositionsMiscellaneous', $newInvoicePositionsMiscArray);
    }

    /**
     * Sets the default customer.
     */
    public function setDefaultCustomer(Customer $customer, RequestStack $requestStack): void
    {
        $invoice = $this->getInvoiceInCreation($requestStack);
        $invoice->setSalutation($customer->getSalutation());
        $invoice->setFirstname($customer->getFirstname());
        $invoice->setLastname($customer->getLastname());

        $address = $this->getPreferredAddress($customer);
        // set first address as default address for invoice
        if ($address) {
            $invoice->setCompany($address->getCompany());
            $invoice->setAddress($address->getAddress());
            $invoice->setZip($address->getZip());
            $invoice->setCity($address->getCity());
            $invoice->setCountry($address->getCountry());
            $invoice->setPhone($address->getPhone());
            $invoice->setEmail($address->getEmail());
            $invoice->setBuyerVatId($address->getBuyerVatId());
            $invoice->setBuyerReference($address->getBuyerReference());
            $invoice->setCustomerIBAN($address->getCustomerIBAN());
        }
        $requestStack->getSession()->set('newInvoice', $invoice);
    }

    /**
     * Get the preferred address for a customer (busniness address preferred, fallback to first).
     */
    private function getPreferredAddress(Customer $customer): ?CustomerAddresses
    {
        $addresses = $customer->getCustomerAddresses();
        foreach ($addresses as $address) {
            if ('CUSTOMER_ADDRESS_TYPE_BUSINESS' === $address->getType()) {
                return $address;
            }
        }

        return $addresses->first() ?: null;
    }

    /**
     * Returns the stored invoice used during creation process from session.
     */
    public function getInvoiceInCreation(RequestStack $requestStack): Invoice
    {
        if (!$requestStack->getSession()->has('newInvoice')) {
            $requestStack->getSession()->set('newInvoice', new Invoice());
        }

        return $requestStack->getSession()->get('newInvoice');
    }

    /**
     * Reset session variables used during invoice creation process.
     */
    public function unsetInvoiceInCreation(RequestStack $requestStack): void
    {
        $requestStack->getSession()->remove('invoiceInCreation');
        $requestStack->getSession()->remove(ReservationService::SESSION_SELECTED_RESERVATIONS);
        $requestStack->getSession()->remove('invoicePositionsMiscellaneous');
        $requestStack->getSession()->remove('invoicePositionsAppartments');
        $requestStack->getSession()->remove('new-invoice-id');
        $requestStack->getSession()->remove('invoiceDate');
        $requestStack->getSession()->remove('newInvoice');
    }

    /**
     * Returns a list of reservations that a connected with the current invoice creation process.
     *
     * @return Resrvation[]
     */
    public function getInvoiceReservationsInCreation(RequestStack $requestStack): array
    {
        $reservationIds = $requestStack->getSession()->get(ReservationService::SESSION_SELECTED_RESERVATIONS, []);

        // legacy support if old session key is still present
        if (0 === count($reservationIds)) {
            $reservationIds = $requestStack->getSession()->get('invoiceInCreation', []);
        }
        $reservations = [];
        foreach ($reservationIds as $reservationId) {
            $reservations[] = $this->em->getRepository(Reservation::class)->find($reservationId);
        }

        return $reservations;
    }

    /**
     * Collect all unique bookers and customers for recommendation list.
     *
     * @param Reservation[] $reservations
     *
     * @return Customer[]
     */
    public function getCustomersForRecommendation(array|Collection $reservations): array
    {
        $result = [];
        foreach ($reservations as $reservation) {
            $booker = $reservation->getBooker();
            $customers = $reservation->getCustomers();
            foreach ($customers as $customer) {
                if (!array_key_exists($customer->getId(), $result)) {
                    $result[$customer->getId()] = $customer;
                }
            }
            if (!array_key_exists($booker->getId(), $result)) {
                $result[$booker->getId()] = $booker;
            }
        }

        return $result;
    }

    /**
     * Creates a new InvoicePosition object based on the input.
     */
    private function makeAparmtentPosition(\DateTime $start, \DateTime $end, Reservation $reservation, Price $price): InvoiceAppartment
    {
        $positionAppartment = new InvoiceAppartment();
        $positionAppartment->setDescription($reservation->getAppartment()->getDescription());
        $positionAppartment->setNumber($reservation->getAppartment()->getNumber());
        $positionAppartment->setStartDate($start);
        $positionAppartment->setEndDate($end);
        $positionAppartment->setVat($price->getVat());
        $positionAppartment->setPrice($price->getPrice());
        $positionAppartment->setPersons($reservation->getPersons());
        $positionAppartment->setBeds($reservation->getAppartment()->getBedsMax());
        $positionAppartment->setIncludesVat($price->getIncludesVat());
        $positionAppartment->setIsFlatPrice($price->getIsFlatPrice());
        $positionAppartment->setIsPerRoom($price->getIsPerRoom());
        $positionAppartment->setRevenueAccount($price->getRevenueAccount());

        return $positionAppartment;
    }

    private function makeMiscPositions(array $tmpPricesArr, RequestStack $requestStack): void
    {
        foreach ($this->createMiscPositionsFromAggregates($tmpPricesArr) as $position) {
            $this->saveNewMiscPosition($position, $requestStack);
        }
    }

    /**
     * @return InvoicePosition[]
     */
    private function createMiscPositionsFromAggregates(array $tmpPricesArr): array
    {
        $positions = [];
        foreach ($tmpPricesArr as $tmpPrice) {
            $price = $tmpPrice['price'];
            if ($price instanceof Price && $price->isPackage()) {
                foreach ($this->expandPackageAggregate($tmpPrice) as $packagePos) {
                    $positions[] = $packagePos;
                }
                continue;
            }

            $position = new InvoicePosition();
            $position->setAmount($tmpPrice['amount']);
            $position->setDescription($price->getDescription());
            $position->setPrice($price->getPrice());
            $position->setVat($price->getVat());
            $position->setIncludesVat($price->getIncludesVat());
            $position->setIsFlatPrice($price->getIsFlatPrice());
            $position->setIsPerRoom($price->getIsPerRoom());
            $position->setRevenueAccount($price->getRevenueAccount());
            $positions[] = $position;
        }

        return $positions;
    }

    /**
     * Expand a single package-price aggregate into one InvoicePosition per component, preserving
     * quantity + flags. Component unit prices are derived via PriceService::expandPackage().
     *
     * @return InvoicePosition[]
     */
    private function expandPackageAggregate(array $tmpPrice): array
    {
        /** @var Price $price */
        $price = $tmpPrice['price'];
        $expanded = $this->ps->expandPackage(
            $price,
            (float) $price->getPrice(),
            (int) $tmpPrice['amount'],
            (bool) $price->getIncludesVat(),
        );

        $positions = [];
        foreach ($expanded as $component) {
            $position = new InvoicePosition();
            $position->setAmount($component['amount']);
            $position->setDescription($price->getDescription().' – '.$component['component']->getDescription());
            $position->setPrice(number_format($component['unitPrice'], 2, '.', ''));
            $position->setVat($component['component']->getVat());
            $position->setIncludesVat($component['includesVat']);
            $position->setIsFlatPrice($price->getIsFlatPrice());
            $position->setIsPerRoom($price->getIsPerRoom());
            $position->setRevenueAccount($component['component']->getRevenueAccount() ?? $price->getRevenueAccount());
            $positions[] = $position;
        }

        return $positions;
    }

    /**
     * Helper function to get number of days between two dates.
     */
    private function getDateDiff(\DateTime $start, \DateTime $end): int
    {
        $interval = date_diff($start, $end);

        // return number of days
        return (int) $interval->format('%a');
    }
}
