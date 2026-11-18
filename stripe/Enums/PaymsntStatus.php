<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case PENDING = 'pending';
    case PAID = 'paid';
    case FAILED = 'failed';
    case EXPIRED = 'expired';
    case DISPUTED = 'disputed';
    case REFUNDED = 'refunded';
    case REJECTED = 'rejected';
    case PARTIALLY_REFUNDED = 'partially_refunded';
    case WAITING_FOR_CONFIRMATION = 'waiting_for_confirmation';

    /**
     * Czytelna nazwa statusu płatności
     */
    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Oczekuje na płatność',
            self::PAID => 'Opłacone',
            self::FAILED => 'Błąd płatności',
            self::EXPIRED => 'Wygasłe',
            self::PARTIALLY_REFUNDED => 'Częściowo zwrócone',
            self::DISPUTED => 'Spór / Reklamacja płatności',
            self::REFUNDED => 'Zwrócone',
            self::REJECTED => 'Płatność odrzucona przez sklep',
            self::WAITING_FOR_CONFIRMATION => 'Płatność oczekują na akceptacje sklepu',
        };
    }

    /**
     * Kolor odznaki (badge)
     */
    public function color(): string
    {
        return match ($this) {
            self::PENDING, self::WAITING_FOR_CONFIRMATION => 'warning',
            self::PAID => 'success',
            self::EXPIRED => 'gray',
            self::FAILED, self::PARTIALLY_REFUNDED, self::REFUNDED, self::DISPUTED, self::REJECTED => 'danger',
        };
    }

    /**
     * Czy środki zostały zaksięgowane
     */
    public function isPaid(): bool
    {
        return $this === self::PAID;
    }

    /**
     * Czy środki zostały w całości lub częściowo zwrócone
     */
    public function isRefunded(): bool
    {
        return in_array($this, [
            self::PARTIALLY_REFUNDED,
            self::REFUNDED,
        ], true);
    }

    /**
     * Czy środki czekaja na akceptację
     */
    public function isWaiting(): bool
    {
        return $this === self::WAITING_FOR_CONFIRMATION;
    }
}
