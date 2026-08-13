<?php

use think\facade\Route;

Route::group('console/v1/community_group_buy', function () {
    Route::get('', "\\addon\\community_group_buy\\controller\\clientarea\\IndexController@overview")
        ->append(['_plugin' => 'community_group_buy', '_controller' => 'index', '_action' => 'overview']);
    Route::get('groups', "\\addon\\community_group_buy\\controller\\clientarea\\IndexController@groups")
        ->append(['_plugin' => 'community_group_buy', '_controller' => 'index', '_action' => 'groups']);
    Route::get('group/:id', "\\addon\\community_group_buy\\controller\\clientarea\\IndexController@groupDetail")
        ->append(['_plugin' => 'community_group_buy', '_controller' => 'index', '_action' => 'group_detail']);
    Route::get('group/:id/share', "\\addon\\community_group_buy\\controller\\clientarea\\IndexController@share")
        ->append(['_plugin' => 'community_group_buy', '_controller' => 'index', '_action' => 'share']);
})
    ->middleware(\app\http\middleware\CheckHome::class)
    ->middleware(\app\http\middleware\ParamFilter::class);

Route::group('console/v1/community_group_buy', function () {
    Route::post('group', "\\addon\\community_group_buy\\controller\\clientarea\\IndexController@createGroup")
        ->append(['_plugin' => 'community_group_buy', '_controller' => 'index', '_action' => 'create_group']);
    Route::post('group/:id/join', "\\addon\\community_group_buy\\controller\\clientarea\\IndexController@joinGroup")
        ->append(['_plugin' => 'community_group_buy', '_controller' => 'index', '_action' => 'join_group']);
})
    ->middleware(\app\http\middleware\CheckHome::class)
    ->middleware(\app\http\middleware\ParamFilter::class)
    ->middleware(\app\http\middleware\RejectRepeatRequest::class);

Route::group(DIR_ADMIN . '/v1/community_group_buy', function () {
    Route::get('dashboard', "\\addon\\community_group_buy\\controller\\AdminController@dashboard")
        ->append(['_plugin' => 'community_group_buy', '_controller' => 'admin', '_action' => 'dashboard']);
    Route::get('economics', "\\addon\\community_group_buy\\controller\\AdminController@economics")
        ->append(['_plugin' => 'community_group_buy', '_controller' => 'admin', '_action' => 'economics']);
    Route::get('products', "\\addon\\community_group_buy\\controller\\AdminController@products")
        ->append(['_plugin' => 'community_group_buy', '_controller' => 'admin', '_action' => 'products']);
    Route::get('settings', "\\addon\\community_group_buy\\controller\\AdminController@settings")
        ->append(['_plugin' => 'community_group_buy', '_controller' => 'admin', '_action' => 'settings']);
    Route::get('groups', "\\addon\\community_group_buy\\controller\\AdminController@groups")
        ->append(['_plugin' => 'community_group_buy', '_controller' => 'admin', '_action' => 'groups']);
    Route::get('group/:id', "\\addon\\community_group_buy\\controller\\AdminController@groupDetail")
        ->append(['_plugin' => 'community_group_buy', '_controller' => 'admin', '_action' => 'group_detail']);
})
    ->middleware(\app\http\middleware\CheckAdmin::class)
    ->middleware(\app\http\middleware\ParamFilter::class);

Route::group(DIR_ADMIN . '/v1/community_group_buy', function () {
    Route::put('settings', "\\addon\\community_group_buy\\controller\\AdminController@saveSettings")
        ->append(['_plugin' => 'community_group_buy', '_controller' => 'admin', '_action' => 'save_settings']);
})
    ->middleware(\app\http\middleware\CheckAdmin::class)
    ->middleware(\app\http\middleware\ParamFilter::class)
    ->middleware(\app\http\middleware\RejectRepeatRequest::class);
