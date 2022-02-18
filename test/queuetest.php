<?php

$q = new SplQueue();
foreach (range(1,5) as $i) {
    $q->enqueue($i);
}

foreach ($q as $i => $value) {
    $n = count($q);
    echo "{$i} {$value} {$n}\n";
    if ($i == 1 || $i == 2) {
        unset($q[$i]);
    }
}