# Let's Encrypt certificate renewal on shared webhosting with cPanel

This script was created for automating the process of renewal of the Let's Encrypt SSL certificate for a shared webhosting that offers cPanel but no plugin to issue the certificate in a automatic manner.

## Requirements

The script requires curl and openssl extensions.
This script also requires a logger that implements the [PSR-3 LoggerInterface](https://www.php-fig.org/psr/psr-3/).

## Usage

The library is not (yet) published to the Composer repository. For now, easiest to just copy the files and run `composer install`.

Next, include the `le-cpanel-updater.php` file.

```php
<?php
// execute only if query params have cron key, just a pseudo-protection
// replace with own measures if needed
if (!isset($_GET['cron'])) {
    die();
}

require 'le-cpanel-updater.php';

use Psr\Log\LoggerInterface;

class EchoLogger implements LoggerInterface {
    public function error ($msg, $context = []) {
        echo $msg . PHP_EOL;
    }
    public function warn ($msg, $context = []) {
        echo $msg . PHP_EOL;
    }
    public function info ($msg, $context = []) {
        echo $msg . PHP_EOL;
    }
    public function debug ($msg, $context = []) {
        echo $msg . PHP_EOL;
    }
}

$logger = new EchoLogger();

$le = new LEUpdater(new LEUpdaterConfig([
    'leAccountMail' => 'mail@example.org',
    'domains' => [ 'example.org', 'www.example.org', 'mail.example.org' ],
    'certDir' => __DIR__ . '/.keys',
    'cPanelHost' => 'https://INSERT_YOUR_CPANEL_URL:2083',
    'cPanelUsername' => 'INSERT_YOUR_CPANEL_USERNAME',
    'cPanelPassword' => 'INSERT_YOUR_CPANEL_TOKEN',
    'logger' => $logger
]));

$TWO_DAYS = 172800; // 2 days in seconds

if ($le->certificateExpiresWithinSeconds($TWO_DAYS)) {
    try {
        $le->issueLECert();
        $le->installLECert();
        $logger->info("Successfully updated the certificate");
    } catch (Exception $err) {
        $logger->error("Certificate update failed");
        $logger->debug($err);
    }
} else {
    $logger->info("Certificate valid longer than checked time, skipping update");
}
?>
```

Last step is to configure cron. Example command

```
wget -O /dev/null -o /dev/null https://example.org/acme.php?cron=1
```

Note: at the moment the script requires to be called using a HTTP request, not through CLI.
This limitation will be removed asap.

Warning: I strongly suggest blocking access to the keys folder using .htaccess.


