<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Transactions extends Model
{
    use HasFactory;

    protected $table = 'transactions';

    protected $fillable = [
        'user_id',
        'reference',
        'type',
        'service_type',
        'description',
        'amount',
        'service_fee',
        'total_amount',
        'balance_before',
        'balance_after',
        'recipient',
        'provider',
        'plan_name',
        'plan_type',
        'payment_method',
        'payment_reference',
        'payment_status',
        'status',
        'status_message',
        'api_reference',
        'api_response',
        'meta',
        'paid_at',
        'completed_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'service_fee' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'balance_before' => 'decimal:2',
        'balance_after' => 'decimal:2',
        'api_response' => 'array',
        'meta' => 'array',
        'paid_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    protected $appends = [
        'formatted_amount',
        'formatted_total_amount',
        'service_type_badge',
        'status_badge',
    ];

    // Relationships
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Accessors
    public function getFormattedAmountAttribute()
    {
        return '₦' . number_format($this->amount, 2);
    }

    public function getFormattedTotalAmountAttribute()
    {
        return '₦' . number_format($this->total_amount, 2);
    }

    public function getServiceTypeBadgeAttribute()
    {
        $badges = [
            'airtime' => 'bg-blue-100 text-blue-800',
            'data' => 'bg-purple-100 text-purple-800',
            'funding' => 'bg-green-100 text-green-800',
            'cable-tv' => 'bg-red-100 text-red-800',
            'electricity' => 'bg-yellow-100 text-yellow-800',
            'exam' => 'bg-indigo-100 text-indigo-800',
            'transfer' => 'bg-gray-100 text-gray-800',
        ];

        return $badges[$this->service_type] ?? 'bg-gray-100 text-gray-800';
    }

    public function getStatusBadgeAttribute()
    {
        $badges = [
            'pending' => 'bg-yellow-100 text-yellow-800',
            'processing' => 'bg-blue-100 text-blue-800',
            'success' => 'bg-green-100 text-green-800',
            'failed' => 'bg-red-100 text-red-800',
            'cancelled' => 'bg-gray-100 text-gray-800',
        ];

        return $badges[$this->status] ?? 'bg-gray-100 text-gray-800';
    }

    // Methods - Make this STATIC
    public static function calculateServiceFee($serviceType, $amount)
    {
        return match($serviceType) {
            'airtime' => $amount * 0.02, // 2%
            'data' => 50, // Fixed ₦50 for data
            default => 0,
        };
    }

    // Instance methods
    public function markAsProcessing()
    {
        $this->update(['status' => 'processing']);
    }

    public function markAsSuccess($apiReference = null, $apiResponse = null)
    {
        $this->update([
            'status' => 'success',
            'api_reference' => $apiReference,
            'api_response' => $apiResponse,
            'completed_at' => now(),
        ]);
    }

    public function markAsFailed($message = null)
    {
        $this->update([
            'status' => 'failed',
            'status_message' => $message,
        ]);
    }

    public function updatePaymentStatus($status, $reference = null)
    {
        $this->update([
            'payment_status' => $status,
            'payment_reference' => $reference ?? $this->payment_reference,
            'paid_at' => $status === 'success' ? now() : $this->paid_at,
        ]);
    }

    // Generate unique transaction reference - STATIC
    public static function generateReference($prefix = 'TXN')
    {
        do {
            $reference = $prefix . '-' . strtoupper(uniqid());
        } while (self::where('reference', $reference)->exists());

        return $reference;
    }

    // Scope queries
    public function scopeSuccessful($query)
    {
        return $query->where('status', 'success');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeRecent($query, $limit = 10)
    {
        return $query->orderBy('created_at', 'desc')->limit($limit);
    }
}