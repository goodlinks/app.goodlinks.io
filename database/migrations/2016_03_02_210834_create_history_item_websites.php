<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateHistoryItemWebsites extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('history_item_websites', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('history_item_id');
            $table->foreign('history_item_id')->references('id')->on('history_items');

            $table->unsignedInteger('buzzstream_website_id')->nullable();
            $table->unique(array('history_item_id', 'buzzstream_website_id'), 'history_item_website_id');

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
