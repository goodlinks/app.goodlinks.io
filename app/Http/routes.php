<?php

if (env('APP_DEBUG')) {
    ini_set('display_errors', 1);
}

$app->get( '/', 'IndexController@index');