<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\InvoiceAppartment;
use App\Entity\InvoicePosition;
use Doctrine\Common\Collections\Collection;

/**
 * The arithmetic behind an invoice's totals, on its own so that more than one
 * service can reach it.
 *
 * It used to live in InvoiceService, which is where every caller still reaches
 * it through - InvoiceService::calculateSums() hands straight over to this. It
 * had to come out because OriginFeeCalculator needs the same sums while
 * InvoiceService needs the fees, and constructor injection has no room for a
 * circle. The calculation is pure arithmetic over the given collections and
 * held no state of the service it came from.
 */
class InvoiceSumCalculator
{
    /**
     * Calculates the sums and vats for an invoice.
     *
     * @param Collection<int, InvoiceAppartment>      $apps            The invoice positions for apartment prices
     * @param Collection<int, InvoicePosition>        $poss            The invoice positions for miscellaneous prices
     * @param array<array-key, array<string, string|float>> $vats      Returns array of all vat values
     * @param float                                   $brutto          Returns the total price including vat
     * @param float                                   $netto           Returns the toal price for all vats
     * @param float                                   $appartmentTotal Returns the total sum for all apartment prices
     * @param float                                   $miscTotal       Returns the total price for all miscellaneous prices
     */
    public function calculate(Collection $apps, Collection $poss, array &$vats, float &$brutto, float &$netto, float &$appartmentTotal, float &$miscTotal): void
    {
        $vats = [];
        $brutto = 0.0;
        $netto = 0.0;
        $appartmentTotal = 0.0;
        $miscTotal = 0.0;

        foreach ($apps as $apartment) {
            $apartmentPrice = ($apartment->getIsFlatPrice() ? $apartment->getPrice() : $apartment->getAmount() * $apartment->getPrice());

            if ($apartment->getIncludesVat()) { // price includes vat
                $vatAmount = (($apartmentPrice * $apartment->getVat()) / (100 + $apartment->getVat()));
                $bruttoAmount = $apartmentPrice;
            } else { // price does not include vat
                $vatAmount = (($apartmentPrice * $apartment->getVat()) / 100);
                $bruttoAmount = $apartmentPrice + $vatAmount;
            }

            // An array key cannot be a float: PHP would truncate 5.5 to 5 and
            // merge that rate with another one. As a string, 19.0 still becomes
            // the key 19, so whole rates are grouped as before.
            $rate = (string) $apartment->getVat();
            $vats[$rate]['brutto'] = ($vats[$rate]['brutto'] ?? 0.0) + $bruttoAmount;
            $vats[$rate]['netto'] = ($vats[$rate]['netto'] ?? 0.0) + $vatAmount;
            $vats[$rate]['netSum'] = ($vats[$rate]['netSum'] ?? 0.0) + $bruttoAmount - $vatAmount;
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

            $rate = (string) $pos->getVat();
            $vats[$rate]['brutto'] = ($vats[$rate]['brutto'] ?? 0.0) + $bruttoAmount;
            $vats[$rate]['netto'] = ($vats[$rate]['netto'] ?? 0.0) + $vatAmount;
            $vats[$rate]['netSum'] = ($vats[$rate]['netSum'] ?? 0.0) + $bruttoAmount - $vatAmount;
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
     * The gross total of the given parts, for callers that want nothing else.
     *
     * @param Collection<int, InvoiceAppartment> $apps The invoice positions for apartment prices
     * @param Collection<int, InvoicePosition>   $poss The invoice positions for miscellaneous prices
     */
    public function grossTotal(Collection $apps, Collection $poss): float
    {
        $vats = [];
        $brutto = 0.0;
        $netto = 0.0;
        $appartmentTotal = 0.0;
        $miscTotal = 0.0;

        $this->calculate($apps, $poss, $vats, $brutto, $netto, $appartmentTotal, $miscTotal);

        return $brutto;
    }
}
