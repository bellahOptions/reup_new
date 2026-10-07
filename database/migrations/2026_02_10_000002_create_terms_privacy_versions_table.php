<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Immutable version history for the legal documents.
 *
 * The admin console has always exposed version history and rollback
 * (AdminController::termsHistory / restoreVersion), but `terms_privacies` is
 * unique on `type` — one row per document — so there was nothing to restore
 * from and those endpoints 500'd on a missing model method.
 *
 * Legal documents need a defensible audit trail, so versions are append-only:
 * publishing a new revision inserts a snapshot rather than overwriting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('terms_privacy_versions', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20)->index();
            $table->longText('content');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('version_date')->useCurrent();
            $table->string('checksum', 64);
            $table->timestamps();

            $table->index(['type', 'version_date']);
        });

        // Seed from the current live documents so history starts populated.
        if (Schema::hasTable('terms_privacies')) {
            foreach (DB::table('terms_privacies')->get() as $document) {
                DB::table('terms_privacy_versions')->insert([
                    'type' => $document->type,
                    'content' => $document->content,
                    'updated_by' => $document->updated_by,
                    'version_date' => $document->version_date ?? now(),
                    'checksum' => hash('sha256', (string) $document->content),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('terms_privacy_versions');
    }
};
