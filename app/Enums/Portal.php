<?php

namespace App\Enums;

/**
 * A front end that users sign in through.
 *
 * Roles answer "who is this person"; portals answer "which application did
 * this session start from". They are deliberately separate: every token is
 * stamped with the portal that minted it, so a session cannot be replayed
 * against an application it was never issued for.
 */
enum Portal: string
{
    case Patient = 'patient';
    case Clinic = 'clinic';
    case Admin = 'admin';

    /**
     * Roles allowed to sign in through this portal.
     *
     * A doctor and their secretary share the clinic dashboard, so they share
     * a portal. The role on the returned user still tells the dashboard which
     * screens to draw.
     *
     * @return array<int, UserRole>
     */
    public function roles(): array
    {
        return match ($this) {
            self::Patient => [UserRole::Patient],
            self::Clinic => [UserRole::Doctor, UserRole::Secretary],
            self::Admin => [UserRole::Admin],
        };
    }

    public function admits(UserRole $role): bool
    {
        return in_array($role, $this->roles(), true);
    }

    /**
     * The Sanctum ability stamped onto tokens issued by this portal.
     */
    public function ability(): string
    {
        return 'portal:'.$this->value;
    }

    /**
     * The one portal a role signs in through.
     */
    public static function forRole(UserRole $role): self
    {
        return match ($role) {
            UserRole::Patient => self::Patient,
            UserRole::Doctor, UserRole::Secretary => self::Clinic,
            UserRole::Admin => self::Admin,
        };
    }

    /**
     * Route middleware admitting a token from any of the given portals, e.g.
     * `Portal::middlewareAllowing(Portal::Clinic, Portal::Admin)`.
     */
    public static function middlewareAllowing(self ...$portals): string
    {
        return 'ability:'.implode(',', array_map(
            static fn (self $portal): string => $portal->ability(),
            $portals,
        ));
    }

    /**
     * Base URL of this portal's front end, used for password reset links.
     * Falls back to the patient application while the other front ends are
     * still unbuilt, so no reset email dead ends on an unset variable.
     */
    public function frontendUrl(): string
    {
        $configured = config('nadara.portals.'.$this->value.'.frontend_url');

        return rtrim((string) ($configured ?: config('app.frontend_url')), '/');
    }
}
