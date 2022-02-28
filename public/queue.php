<?php

$next = [];
$n = rand(1,9);
for($i = 0; $i < $n; $i++) {
    $next[] = rand(1,9);
}
echo "\n";
print_r($next);

echo "\n";
$current = $next;
$n = count($current);
$next = [];
for($i = 0; $i < $n; $i++) {
    // terminated
    if (rand(0,1)) {
        echo "{$current[$i]} terminated\n";
        continue;
    }
    // resumed
    echo "{$current[$i]} resumed\n";
    $next[] = $current[$i];

    // new
    if (rand(0,1)) {
        $v = rand(1,9);
        echo "queued $v\n";
        $next[] = $v;
    }
}
echo "\n";
print_r($next);