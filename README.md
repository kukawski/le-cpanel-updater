# Let's Encrypt certificate renewal on shared webhosting with cPanel

This script was created for automating the process of renewal of the Let's Encrypt SSL certificate for a shared webhosting that offers cPanel but no plugin to issue the certificate in a automatic manner.

## Requirements

The script requires curl and openssl extensions.
This script also requires a logger that implements following methods: `error($msg)`, `warn($msg)`, `info($msg)`, `debug($msg)`.

## Usage

The library is not (yet) published to the Composer repository. For now, easiest to just copy the files and run `composer install`.

Next, include the `le-cpanel-updater.php` file.

```php
<?php
require 'le-cpanel-updater.php';

class NoopLogger {
    public function error ($msg) {}
    public function warn ($msg) {}
    public function info ($msg) {}
    public function debug ($msg) {}
}

$le = new LEUpdater(new LEUpdaterConfig([
    'leAccountMail' => 'mail@example.org',
    'domains' => [ 'example.org', 'www.example.org', 'mail.example.org' ],
    'certDir' => __DIR__ . '/.keys',
    'cPanelHost' => 'https://INSERT_YOUR_CPANEL_URL:2083',
    'cPanelUsername' => 'INSERT_YOUR_CPANEL_USERNAME',
    'cPanelPassword' => 'INSERT_YOUR_CPANEL_PASSWORD',
    'logger' => new NoopLogger()
]));

$TWO_DAYS = 172800; // 2 days in seconds

if ($le->checkIfLECertUpdateNeeded($TWO_DAYS)) {
    $le->issueLECert();
    $le->installLECert();
}
?>
```