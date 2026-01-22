<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Appartment;
use App\Entity\RoomDayStatus;
use App\Entity\User;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Builds CSV exports for housekeeping day/week views.
 */
class HousekeepingExportService
{
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly HousekeepingViewService $viewService
    ) {
    }

    /**
     * Build the CSV response for a day view export.
     *
     * @param array{
     *     date: \DateTimeImmutable,
     *     rows: array<int, array{
     *         apartment: Appartment,
     *         occupancyType: string,
     *         guestCount: int|null,
     *         reservationSummary: string|null,
     *         status: RoomDayStatus|null
     *     }>
     * } $dayView
     */
    public function buildDayCsvResponse(array $dayView, string $subsidiaryId, string $locale): StreamedResponse
    {
        $filename = sprintf('housekeeping_%s.csv', $dayView['date']->format('Y-m-d'));
        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
        ];

        $response = new StreamedResponse(function () use ($dayView, $locale) {
            $handle = fopen('php://output', 'w');
            $occupancyLabels = $this->viewService->getOccupancyLabels();
            $statusLabels = $this->viewService->getStatusLabels();
            fputcsv($handle, [
                $this->translator->trans('housekeeping.date', [], 'Housekeeping', $locale),
                $this->translator->trans('housekeeping.room', [], 'Housekeeping', $locale),
                $this->translator->trans('housekeeping.occupancy', [], 'Housekeeping', $locale),
                $this->translator->trans('housekeeping.guests', [], 'Housekeeping', $locale),
                $this->translator->trans('housekeeping.reservation', [], 'Housekeeping', $locale),
                $this->translator->trans('housekeeping.status', [], 'Housekeeping', $locale),
                $this->translator->trans('housekeeping.assigned_to', [], 'Housekeeping', $locale),
                $this->translator->trans('housekeeping.note', [], 'Housekeeping', $locale),
            ], ';');

            foreach ($dayView['rows'] as $row) {
                $status = $row['status'];
                $assigned = $status instanceof RoomDayStatus ? $status->getAssignedTo() : null;
                $occupancyLabel = $this->translator->trans(
                    $occupancyLabels[$row['occupancyType']] ?? $row['occupancyType'],
                    [],
                    'Housekeeping',
                    $locale
                );
                $statusLabel = $status instanceof RoomDayStatus
                    ? $this->translator->trans(
                        $statusLabels[$status->getHkStatus()->value] ?? $status->getHkStatus()->value,
                        [],
                        'Housekeeping',
                        $locale
                    )
                    : '';

                fputcsv($handle, [
                    $dayView['date']->format('Y-m-d'),
                    $this->formatApartmentLabel($row['apartment']),
                    $occupancyLabel,
                    $row['guestCount'] ?? '',
                    $row['reservationSummary'] ?? '',
                    $statusLabel,
                    $assigned instanceof User ? $this->formatUserName($assigned) : '',
                    $status instanceof RoomDayStatus ? ($status->getNote() ?? '') : '',
                ], ';');
            }

            fclose($handle);
        }, Response::HTTP_OK, $headers);

        $response->headers->set('X-Selected-Subsidiary', $subsidiaryId);

        return $response;
    }

    /**
     * Build the CSV response for a week view export.
     *
     * @param array{
     *     start: \DateTimeImmutable,
     *     days: \DateTimeImmutable[],
     *     rows: array<int, array{
     *         apartment: Appartment,
     *         days: array<string, array{
     *             occupancyType: string,
     *             guestCount: int|null,
     *             reservationSummary: string|null,
     *             status: RoomDayStatus|null
     *         }>
     *     }>
     * } $weekView
     */
    public function buildWeekCsvResponse(array $weekView, string $subsidiaryId, string $locale): StreamedResponse
    {
        $filename = sprintf('housekeeping_week_%s.csv', $weekView['start']->format('Y-m-d'));
        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
        ];

        $response = new StreamedResponse(function () use ($weekView, $locale) {
            $handle = fopen('php://output', 'w');
            $header = [$this->translator->trans('housekeeping.room', [], 'Housekeeping', $locale)];
            foreach ($weekView['days'] as $day) {
                $header[] = sprintf('%s %s', $this->formatWeekdayLabel($day, $locale), $day->format('Y-m-d'));
            }
            fputcsv($handle, $header, ';');

            $occupancyLabels = $this->viewService->getOccupancyLabels();
            $statusLabels = $this->viewService->getStatusLabels();
            foreach ($weekView['rows'] as $row) {
                $line = [$this->formatApartmentLabel($row['apartment'])];
                foreach ($weekView['days'] as $day) {
                    $dateKey = $day->format('Y-m-d');
                    $cell = $row['days'][$dateKey] ?? null;
                    if (null === $cell) {
                        $line[] = '';
                        continue;
                    }
                    $statusValue = $cell['status'] instanceof RoomDayStatus ? $cell['status']->getHkStatus()->value : '';
                    $statusLabel = '' === $statusValue
                        ? ''
                        : $this->translator->trans(
                            $statusLabels[$statusValue] ?? $statusValue,
                            [],
                            'Housekeeping',
                            $locale
                        );
                    $occupancyLabel = $this->translator->trans(
                        $occupancyLabels[$cell['occupancyType']] ?? $cell['occupancyType'],
                        [],
                        'Housekeeping',
                        $locale
                    );
                    $guestCount = $cell['guestCount'] ?? '';
                    $line[] = trim(sprintf('%s / %s / %s', $occupancyLabel, $statusLabel, $guestCount));
                }
                fputcsv($handle, $line, ';');
            }

            fclose($handle);
        }, Response::HTTP_OK, $headers);

        $response->headers->set('X-Selected-Subsidiary', $subsidiaryId);

        return $response;
    }

    /**
     * Format a user name for display and exports.
     */
    private function formatUserName(User $user): string
    {
        return trim(sprintf('%s %s', $user->getFirstname(), $user->getLastname()));
    }

    /**
     * Format the apartment label for tables and exports.
     */
    private function formatApartmentLabel(Appartment $apartment): string
    {
        $label = trim(sprintf('%s %s', $apartment->getNumber(), $apartment->getDescription()));

        return '' === $label ? (string) $apartment->getId() : $label;
    }

    /**
     * Format a localized short weekday label for CSV exports.
     */
    private function formatWeekdayLabel(\DateTimeImmutable $date, string $locale): string
    {
        if (!class_exists(\IntlDateFormatter::class)) {
            return $date->format('D');
        }

        $formatter = new \IntlDateFormatter(
            $locale,
            \IntlDateFormatter::FULL,
            \IntlDateFormatter::NONE,
            'UTC',
            null,
            'EEE'
        );

        return $formatter->format($date) ?: $date->format('D');
    }
}
