<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class TermsPrivacy extends Model
{
    use HasFactory;

    protected $table = 'terms_privacies';
    
    protected $fillable = [
        'type', // 'terms' or 'privacy'
        'content',
        'updated_by',
        'version_date',
        'is_active'
    ];

    protected $casts = [
        'version_date' => 'datetime',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    /**
     * The user who last updated this document
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Scope for active documents
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope for specific type
     */
    public function scopeType($query, $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Get the active terms of service
     */
    public static function getTerms()
    {
        return Cache::remember('terms_of_service_active', 3600, function () {
            return self::where('type', 'terms')
                ->active()
                ->orderBy('version_date', 'desc')
                ->first();
        });
    }

    /**
     * Get the active privacy policy
     */
    public static function getPrivacy()
    {
        return Cache::remember('privacy_policy_active', 3600, function () {
            return self::where('type', 'privacy')
                ->active()
                ->orderBy('version_date', 'desc')
                ->first();
        });
    }

    /**
     * Get all active documents
     */
    public static function getAllActive()
    {
        return Cache::remember('terms_privacy_all_active', 3600, function () {
            return self::active()
                ->orderBy('type')
                ->get()
                ->keyBy('type');
        });
    }

    /**
     * Create or update a document
     */
       public static function updateOrCreateDocument($type, $content, $userId)
    {
        DB::beginTransaction();
        
        try {
            // First, deactivate all previous documents of this type
            self::where('type', $type);
            
            // Check if a record with this type already exists
            $existing = self::where('type', $type)->first();
            
            if ($existing) {
                // UPDATE the existing record
                $existing->update([
                    'content' => $content,
                    'updated_by' => $userId,
                    'version_date' => now(),
                    'is_active' => true
                ]);
                
                DB::commit();
                return $existing->fresh(); // Return refreshed instance
            } else {
                // CREATE a new record (first time)
                $document = self::create([
                    'type' => $type,
                    'content' => $content,
                    'updated_by' => $userId,
                    'version_date' => now(),
                    'is_active' => true
                ]);
                
                DB::commit();
                return $document;
            }
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }
    
    
    
    public function getTypeNameAttribute()
    {
        return $this->type == 'terms' ? 'Terms of Service' : 'Privacy Policy';
    }
    
    public function getFormattedVersionDateAttribute()
    {
        return $this->version_date ? $this->version_date->format('F j, Y') : 'N/A';
    }
}
