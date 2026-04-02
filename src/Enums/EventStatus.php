<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * EventStatus
 *
 * Typed replacement for the string literals used in the events.status column.
 * DB column: VARCHAR / ENUM('planned','open','closed','cancelled','completed')
 */
enum EventStatus: string
{
    case Planned   = 'planned';
    case Open      = 'open';
    case Closed    = 'closed';
    case Cancelled = 'cancelled';
    case Completed = 'completed';

    /** Human-readable German label */
    public function label(): string
    {
        return match ($this) {
            self::Planned   => 'Geplant',
            self::Open      => 'Anmeldung offen',
            self::Closed    => 'Anmeldung geschlossen',
            self::Cancelled => 'Abgesagt',
            self::Completed => 'Abgeschlossen',
        };
    }

    /** Tailwind CSS badge colour classes */
    public function badgeClass(): string
    {
        return match ($this) {
            self::Planned   => 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300',
            self::Open      => 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300',
            self::Closed    => 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300',
            self::Cancelled => 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300',
            self::Completed => 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300',
        };
    }

    /** Returns true for statuses that show as "active" (upcoming / open) */
    public function isActive(): bool
    {
        return match ($this) {
            self::Planned, self::Open, self::Closed => true,
            default                                  => false,
        };
    }
}
