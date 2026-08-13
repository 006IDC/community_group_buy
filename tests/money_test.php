<?php

require dirname(__DIR__) . '/lib/Money.php';

use addon\community_group_buy\lib\Money;

$cases = [
    [Money::mulBps('100.00', 9000), '90.00', '3人团'],
    [Money::mulBps('100.00', 8000), '80.00', '5人团'],
    [Money::mulBps('100.00', 7000), '70.00', '10人团'],
    [Money::sub(Money::mulBps('100.00', 6500), '0.10'), '64.90', '0.1元获客亏损'],
    [Money::normalize(bcmul(Money::sub(Money::mulBps('156.00', 6500), '0.10'), '10', 2)), '1013.00', '10人全0.1元预留亏损'],
    [Money::min('68.00', '70.00'), '68.00', '保留已有更低优惠'],
];
foreach ($cases as $case) {
    if ($case[0] !== $case[1]) {
        fwrite(STDERR, 'FAIL ' . $case[2] . ': ' . $case[0] . ' != ' . $case[1] . "\n");
        exit(1);
    }
}
echo "money_test: OK\n";
