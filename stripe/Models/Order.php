<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\User;
use App\Models\Invoice;
use App\Support\VatCalculator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Order extends Model
{
    protected $fillable = [
        'user_id',
        'status',
        'payment_status',
        'payment_method',
        'payment_error',
        'payment_session_id',
        'payment_intent_id',
        'paid_at',
        'refunded_at',
        'coupon_code',
        'subtotal',
        'discount_amount',
        'shipping_amount',
        'pack_amount',
        'total',
        'customer_name',
        'customer_email',
        'customer_phone',
        'company_name',
        'company_nip',
        'shipping_tax_rate',
        'shipping_method',
        'shipping_locker_code',
        'shipping_locker_details',
        'shipping_address',
        'shipping_city',
        'shipping_zip',
        'notes',
    ];

    protected $casts = [
        'status' => OrderStatus::class,
        'payment_status' => PaymentStatus::class,
        'subtotal'          => 'integer',
        'discount_amount'   => 'integer',
        'shipping_amount'   => 'integer',
        'shipping_vat_rate' => 'integer',
        'total'             => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (Order $order) {
            $order->uuid ??= (string) Str::uuid();
            $order->order_number ??= self::generateUniqueOrderNumber();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id')->withTrashed();
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'order_id');
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class, 'order_id');
    }

    private static function generateUniqueOrderNumber(): string
    {
        $yearMonth = now()->format('Y-m'); // np. 2026-11
        $timeSuffix = substr(strtoupper(base_convert((string) time(), 10, 36)), -5);
        $random = strtoupper(Str::random(4));

        return "ZAM-{$yearMonth}-{$timeSuffix}{$random}"; // np. ZAM-2026-11-4K6X9A1B
    }

    public function getVatSummary(): array
    {
        $lines = $this->items->map(fn($item) => [
            'gross' => $item->total,
            'rate'  => $item->tax_rate,
        ])->toArray();

        if (($this->shipping_amount ?? 0) > 0) {
            // Bierzemy stawkę VAT najdroższej grupy produktów w zamówieniu (zamiast sztywnego 2300)
            $dominantRate = $this->items
                ->groupBy('tax_rate')
                ->map(fn($group) => $group->sum('total'))
                ->sortDesc()
                ->keys()
                ->first() ?? 2300;

            $lines[] = [
                'gross' => $this->shipping_amount,
                'rate'  => (int) $dominantRate,
            ];
        }

        return VatCalculator::summarizeByRates($lines);
    }
}
