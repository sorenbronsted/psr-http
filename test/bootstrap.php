<?php

require __DIR__ . '/../vendor/autoload.php';

// Function interception must be installed before any application classes load,
// otherwise the existing Brain Monkey tests depend on test execution order.
require_once __DIR__ . '/../vendor/antecedent/patchwork/Patchwork.php';
