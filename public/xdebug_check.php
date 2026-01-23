<?php
if (extension_loaded('xdebug')) {
    echo "Xdebug is installed and loaded.\n";
    echo "Version: " . phpversion('xdebug') . "\n";
    echo "Mode: " . ini_get('xdebug.mode') . "\n";
    echo "Client Host: " . ini_get('xdebug.client_host') . "\n";
    echo "Client Port: " . ini_get('xdebug.client_port') . "\n";
} else {
    echo "Xdebug is NOT loaded.\n";
    phpinfo();
}
