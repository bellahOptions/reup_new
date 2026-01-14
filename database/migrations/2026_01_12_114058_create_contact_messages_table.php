<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateContactMessagesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('contact_messages', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('user_id')->nullable();
    $table->string('name');
    $table->string('email');
    $table->string('subject');
    $table->text('message');
    $table->boolean('is_read')->default(false);
    $table->text('admin_reply')->nullable();
    $table->boolean('is_responded')->default(false);
    $table->timestamp('responded_at')->nullable();
    $table->foreignId('replied_by')->nullable()->constrained('users')->onDelete('set null');
    $table->timestamps();
    
    $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
});
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('contact_messages');
    }
}
