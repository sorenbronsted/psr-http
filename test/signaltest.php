<?php
echo "Installing signal handler...\n";
pcntl_signal(SIGHUP,  function($signo) {
    echo "signal handler called\n";
});

//echo "Generating signal SIGHUP to self...\n";
//posix_kill(posix_getpid(), SIGHUP);

echo "Dispatching...\n";
pcntl_signal_dispatch();

echo "Done\n";
