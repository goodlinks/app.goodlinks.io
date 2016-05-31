<?php

if (env('APP_DEBUG')) {
    ini_set('display_errors', 1);
}

$app->get( '/',                                     'IndexController@index');
$app->get( '/team',                                 'IndexController@team');
$app->get( '/project/{project_id}',                 'IndexController@project');
$app->get( '/import',                               'IndexController@import');
$app->get( '/importIncremental',                    'IndexController@importBuzzstreamIncremental');
$app->get( '/processFeedInitial',                   'IndexController@importBuzzstreamInitial');
$app->get( '/history-item/{buzzstream_id}/ignore',  'IndexController@ignoreHistoryItem');
