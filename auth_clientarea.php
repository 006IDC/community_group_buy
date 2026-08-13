<?php

return [
    [
        'title' => 'clientarea_auth_community_group_buy',
        'url' => '',
        'child' => [
            [
                'title' => 'clientarea_auth_community_group_buy_access',
                'url' => '',
                'auth_rule' => [
                    'IndexController::overview', 'IndexController::groups', 'IndexController::groupDetail', 'IndexController::share',
                    'IndexController::createGroup', 'IndexController::joinGroup',
                ],
                'auth_rule_title' => [
                    'clientarea_auth_community_group_buy_overview',
                    'clientarea_auth_community_group_buy_groups',
                    'clientarea_auth_community_group_buy_group_detail',
                    'clientarea_auth_community_group_buy_share',
                    'clientarea_auth_community_group_buy_create',
                    'clientarea_auth_community_group_buy_join',
                ],
            ],
        ],
    ],
];
