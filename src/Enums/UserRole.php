<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * UserRole
 *
 * Typed replacement for the role strings used throughout the application.
 * Matches the values stored in users.role and compared via Auth::hasRole().
 */
enum UserRole: string
{
    case Admin          = 'admin';
    case VorstandIntern = 'vorstand_intern';
    case VorstandExtern = 'vorstand_extern';
    case VorstandFinanz = 'vorstand_finanzen';
    case Ressortleiter  = 'ressortleiter';
    case Mitglied       = 'mitglied';
    case Anwaerter      = 'anwaerter';
    case Ehrenmitglied  = 'ehrenmitglied';
    case AlumniVorstand = 'alumni_vorstand';
    case AlumniFinanz   = 'alumni_finanz';
    case Alumni         = 'alumni';
    case Manager        = 'manager';

    /** Human-readable German label */
    public function label(): string
    {
        return match ($this) {
            self::Admin          => 'Administrator',
            self::VorstandIntern => 'Vorstand Intern',
            self::VorstandExtern => 'Vorstand Extern',
            self::VorstandFinanz => 'Vorstand Finanzen',
            self::Ressortleiter  => 'Ressortleiter',
            self::Mitglied       => 'Mitglied',
            self::Anwaerter      => 'Anwärter',
            self::Ehrenmitglied  => 'Ehrenmitglied',
            self::AlumniVorstand => 'Alumni-Vorstand',
            self::AlumniFinanz   => 'Alumni-Finanzen',
            self::Alumni         => 'Alumni',
            self::Manager        => 'Manager',
        };
    }

    /** Returns true when this role has board-level privileges */
    public function isBoard(): bool
    {
        return in_array($this, [
            self::Admin,
            self::VorstandIntern,
            self::VorstandExtern,
            self::VorstandFinanz,
        ], true);
    }

    /** Returns true when this role can manage other users */
    public function canManageUsers(): bool
    {
        return in_array($this, [
            self::Admin,
            self::VorstandIntern,
            self::VorstandExtern,
            self::VorstandFinanz,
            self::Ressortleiter,
        ], true);
    }

    /**
     * Return all role values as an array of strings.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $r) => $r->value, self::cases());
    }
}
