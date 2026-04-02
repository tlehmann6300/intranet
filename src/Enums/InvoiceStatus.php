<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * InvoiceStatus
 *
 * Typed replacement for the ad-hoc string literals used in the invoices table.
 * DB column: VARCHAR / ENUM('draft','pending','approved','rejected','paid')
 */
enum InvoiceStatus: string
{
    case Draft    = 'draft';
    case Pending  = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Paid     = 'paid';

    /** Human-readable German label */
    public function label(): string
    {
        return match ($this) {
            self::Draft    => 'Entwurf',
            self::Pending  => 'Ausstehend',
            self::Approved => 'Genehmigt',
            self::Rejected => 'Abgelehnt',
            self::Paid     => 'Bezahlt',
        };
    }

    /** Tailwind CSS badge colour classes for the status pill */
    public function badgeClass(): string
    {
        return match ($this) {
            self::Draft    => 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300',
            self::Pending  => 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300',
            self::Approved => 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300',
            self::Rejected => 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300',
            self::Paid     => 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300',
        };
    }
}
