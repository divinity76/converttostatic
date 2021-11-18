<?php
declare(strict_types=1);
$cmd = implode(" ",array(
    'g++',
    '-Wall',
    '-Wextra',
    '-Wpedantic',
    '-Werror',
    'converttostatic.cpp'
));
echo "cmd: {$cmd}\n";
$return_var = null;
passthru($cmd,$return_var);
die($return_var);