<?php

namespace App\Enums;

enum OrderStatus: string
{
    case PENDING = 'pending';
    case CONFIRMED = 'confirmed';
    case REJECTED = 'rejected';
    case PACKING = 'packing';
    case READY_FOR_PICKUP = 'ready_for_pickup';
    case SHIPPED = 'shipped';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';

    /**
     * Czytelna nazwa statusu do wyświetlenia w panelu i dla klienta
     */
    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Nowe (oczekuje)',
            self::CONFIRMED => 'Przyjęte do realizacji',
            self::REJECTED => 'Odrzucone przez sklep',
            self::PACKING => 'W trakcie pakowania',
            self::READY_FOR_PICKUP => 'Gotowe do odbioru',
            self::SHIPPED => 'Wysłane / W drodze',
            self::COMPLETED => 'Zrealizowane',
            self::CANCELLED => 'Anulowane',
        };
    }

    /**
     * Kolor odznaki (badge)
     */
    public function color(): string
    {
        return match ($this) {
            self::PENDING => 'gray',
            self::CONFIRMED => 'info',
            self::PACKING => 'warning',
            self::READY_FOR_PICKUP, self::SHIPPED => 'primary',
            self::COMPLETED => 'success',
            self::REJECTED, self::CANCELLED => 'danger',
        };
    }

    /**
     * Czy zamówienie czeka na podjęcie przez magazyn / pakowacza
     */
    public function canBePacked(): bool
    {
        return in_array($this, [
            self::PENDING,
            self::CONFIRMED,
        ], true);
    }

    /**
     * Czy ktoś już fizycznie zajmuje się tym zamówieniem
     */
    public function isInProgress(): bool
    {
        return in_array($this, [
            self::PACKING,
            self::READY_FOR_PICKUP,
            self::SHIPPED,
        ], true);
    }

    /**
     * Czy zamówienie jest w stanie końcowym (zamknięte)
     */
    public function isFinal(): bool
    {
        return in_array($this, [
            self::COMPLETED,
            self::CANCELLED,
            self::REJECTED,
        ], true);
    }
}
