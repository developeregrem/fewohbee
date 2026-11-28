<?php

declare(strict_types=1);

namespace App\Workflow\Trigger;

/**
 * Where in the operator's day a trigger belongs, along the way of a guest: the list of
 * automations is grouped by it. The order of the cases is the order of the groups.
 */
enum WorkflowTriggerGroup: string
{
    /** A booking comes in or changes: new, from a channel, its status, its confirmation. */
    case BOOKINGS = 'bookings';
    /** Before arrival, the online check-in, after departure. */
    case STAY = 'stay';
    /** Invoices and their payment. */
    case INVOICES = 'invoices';
    /** On a fixed day, independent of a single booking. */
    case SCHEDULE = 'schedule';
    /** A workflow whose trigger is no longer known. */
    case OTHER = 'other';

    public function labelKey(): string
    {
        return 'workflow.group.'.$this->value;
    }
}
