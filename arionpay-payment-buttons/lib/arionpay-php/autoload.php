<?php

/**
 * For sites without Composer: require this file, then use \ArionPay\Client and \ArionPay\Webhook.
 *
 *     require __DIR__ . '/arionpay-php/autoload.php';
 */
spl_autoload_register(static function (string $class): void {
    if (strpos($class, 'ArionPay\\') !== 0) {
        return;
    }
    $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, 9)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
