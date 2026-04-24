<?php

declare(strict_types=1);

namespace App\Workflow;

use App\Entity\Reservation;
use App\Event\CalendarImportBookingCreatedEvent;
use App\Event\InvoiceCreatedEvent;
use App\Event\InvoiceStatusChangedEvent;
use App\Event\OnlineBookingCreatedEvent;
use App\Event\ReservationCreatedEvent;
use App\Event\ReservationStatusChangedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Listens to domain events and routes them to the WorkflowEngine.
 */
class WorkflowEventSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly WorkflowEngine $engine,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            OnlineBookingCreatedEvent::class => 'onOnlineBookingCreated',
            CalendarImportBookingCreatedEvent::class => 'onCalendarImportBookingCreated',
            ReservationCreatedEvent::class => 'onReservationCreated',
            ReservationStatusChangedEvent::class => 'onReservationStatusChanged',
            InvoiceCreatedEvent::class => 'onInvoiceCreated',
            InvoiceStatusChangedEvent::class => 'onInvoiceStatusChanged',
        ];
    }

    public function onOnlineBookingCreated(OnlineBookingCreatedEvent $event): void
    {
        $first = $event->reservations[0] ?? null;
        if (!$first instanceof Reservation) {
            return;
        }

        $this->engine->processEvent('online_booking.created', $first, [
            'booker' => $event->booker,
            'allReservations' => $event->reservations,
        ]);
    }

    public function onCalendarImportBookingCreated(CalendarImportBookingCreatedEvent $event): void
    {
        $this->engine->processEvent('calendar_import.created', $event->reservation);
    }

    public function onReservationCreated(ReservationCreatedEvent $event): void
    {
        $first = $event->reservations[0] ?? null;
        if (!$first instanceof Reservation) {
            return;
        }

        $this->engine->processEvent('reservation.created', $first, [
            'allReservations' => $event->reservations,
        ]);
    }

    public function onReservationStatusChanged(ReservationStatusChangedEvent $event): void
    {
        $this->engine->processEvent('reservation.status_changed', $event->reservation, [
            'previousStatus' => $event->previousStatus,
        ]);
    }

    public function onInvoiceCreated(InvoiceCreatedEvent $event): void
    {
        $this->engine->processEvent('invoice.created', $event->invoice);
    }

    public function onInvoiceStatusChanged(InvoiceStatusChangedEvent $event): void
    {
        $this->engine->processEvent('invoice.status_changed', $event->invoice, [
            'previousStatus' => $event->previousStatus,
        ]);
    }
}
