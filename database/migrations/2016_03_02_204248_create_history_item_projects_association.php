<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateHistoryItemProjectsAssociation extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('history_item_projects', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('history_item_id');
            $table->foreign('history_item_id')->references('id')->on('history_items');

            $table->unsignedInteger('buzzstream_project_id')->nullable();
            $table->unique(array('history_item_id', 'buzzstream_project_id'), 'history_item_project_id');

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
