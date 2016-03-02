<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateHistoryItems extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('history_items', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('buzzstream_id');
            $table->unique('buzzstream_id');

            $table->string('type');
            $table->string('summary');
            $table->dateTime('buzzstream_created_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        //
    }
}
