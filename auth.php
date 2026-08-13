<?php

$controller = 'addon\\community_group_buy\\controller\\AdminController::';

return [
    [
        'title' => 'auth_community_group_buy',
        'url' => '',
        'description' => '商品拼团',
        'parent' => 'auth_product',
        'child' => [
            [
                'title' => 'auth_community_group_buy_view',
                'url' => 'index',
                'description' => '查看团购白名单、拼团和补贴概览',
                'auth_rule' => [
                    $controller . 'dashboard', $controller . 'economics', $controller . 'products',
                    $controller . 'settings', $controller . 'groups', $controller . 'groupDetail',
                ],
            ],
            [
                'title' => 'auth_community_group_buy_manage',
                'url' => '',
                'description' => '管理拼团商品白名单与0.1元补贴开关',
                'auth_rule' => [
                    $controller . 'saveSettings',
                ],
            ],
        ],
    ],
];
