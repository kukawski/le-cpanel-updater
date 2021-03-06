<?php
require 'vendor/autoload.php';

use LEClient\LEConnector;
use LEClient\LEFunctions;
use LEClient\LEAccount;
use LEClient\LEOrder;
use LEClient\LEAuthorization;
use LEClient\LEClient;
use Psr\Log\LoggerInterface;

class LEUpdaterConfig {
    protected $leAccountMail;
    protected $domains;
    protected $certDir;
    protected $cPanelApiURL;
    protected $cPanelUsername;
    protected $cPanelPassword;
    protected $logger;

    public function __construct(array $config) {
        foreach ($config as $key => $value) {
            $this->$key = $value;
        }
    }

    public function __get($key) {
        return $this->$key ?? NULL;
    }
}

class LEUpdater {
    protected $config;
    protected $logger;

    public function __construct(LEUpdaterConfig $config) {
        $this->config = $config;
        $this->logger = $config->logger ?? new class {
            public function debug($msg) { /* noop */ }
            public function error($msg) { /* noop */ }
            public function info($msg) { /* noop */ }
        };

        if (!function_exists('openssl_x509_parse')) {
            throw new Exception('openssl_x509_parse function is required for this script to work');
        }
    }

    public function checkIfLECertUpdateNeeded(int $maxSecondsToExpiration) {
        $update_needed = FALSE;

        $path = $this->config->certDir . '/certificate.crt';

        if (!file_exists($path)) {
            $this->logger->debug('Certificate file does not exist. Assume script\'s first run');
            $update_needed = TRUE;
        } else {
            $this->logger->debug('Old certificate file exists');
            $cert_info = openssl_x509_parse(file_get_contents($path));

            if (!isset($cert_info['validTo_time_t'])) {
                $this->logger->debug('validTo_time_t not set');
                $update_needed = TRUE;
            }

            if (!$update_needed AND ($cert_info['validTo_time_t'] - time() <= $maxSecondsToExpiration)) {
                $this->logger->debug('Old certificate is about to expire soon: ' . date('Y-m-d H:i:s', $cert_info['validTo_time_t']));
                $update_needed = TRUE;
            }
        }

        return $update_needed;
    }

    public function issueLECert() {
        // LEClient prints logs to stdout. Let's cache them
        ob_start();

        try {
            $mail = $this->config->leAccountMail;
            $domains = $this->config->domains;
            // take first domain
            $basename = $domains[0];

            $path = $this->config->certDir;

            $client = new LEClient($mail, LEClient::LE_PRODUCTION, LECLient::LOG_STATUS, $path);

            $order = $client->getOrCreateOrder($basename, $domains);

            if (!$order->allAuthorizationsValid()) {
                $pending = $order->getPendingAuthorizations(LEOrder::CHALLENGE_TYPE_HTTP);

                if (!empty($pending)) {
                    $folder = $_SERVER['DOCUMENT_ROOT'] . '/.well-known/acme-challenge/';

                    if(!file_exists($folder)) {
                        // create missing directories recursively
                        mkdir($folder, 0777, TRUE);
                    }

                    foreach ($pending as $challenge) {
                        file_put_contents($folder . $challenge['filename'], $challenge['content']);
                        $order->verifyPendingOrderAuthorization($challenge['identifier'], LEOrder::CHALLENGE_TYPE_HTTP);
                    }
                }
            }

            // check again, although now everything should be fine
            if ($order->allAuthorizationsValid()) {
                if (!$order->isFinalized()) {
                    $order->finalizeOrder();
                }

                if ($order->isFinalized()) {
                    $order->getCertificate();
                }
            }
        } catch (Exception $err) {
            $this->logger->error('Error occured while requesting certificate');
            $this->logger->error($err);
        }

        // log all cached messages. Should help debugging what went wrong
        $log = ob_get_clean();
        $this->logger->debug($log);
    }

    public function installLECert() {
        $this->logger->info('Starting installation of new certificate');

        $request_uri = $this->config->cPanelHost . '/execute/SSL/install_ssl';
        $username = $this->config->cPanelUsername;
        $password = $this->config->cPanelPassword;

        $path = $this->config->certDir;

        if (
            !file_exists($path . '/certificate.crt')
            OR !file_exists($path . '/private.pem')
            OR !file_exists($path . '/fullchain.crt')
        ) {
            $this->logger->debug('Missing certificate file, private key or full chain file');
            return FALSE;
        }

        $fullchain = file_get_contents($path . '/fullchain.crt');
        $cabundle = '';

        if (preg_match_all('~(-----BEGIN\sCERTIFICATE-----[\s\S]+?-----END\sCERTIFICATE-----)~i', $fullchain, $chains)) {
            $cabundle = join(PHP_EOL, array_slice($chains[0], 1));
        }

        if (!$cabundle) {
            $this->logger->debug('Getting cabundle failed, certificate installation will most likely fail');
        }

        $payload = [
            'domain' => $this->config->domains[0],
            'cert' => file_get_contents($path . '/certificate.crt'),
            'key' => file_get_contents($path . '/private.pem'),
            'cabundle' => $cabundle
        ];

        // make call to cPanel API
        $ch = curl_init($request_uri);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $username . ':' . $password);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);

        $curl_response = curl_exec($ch);
        curl_close($ch);

        $this->logger->debug($curl_response);

        // TODO: check if installation did really work

        return TRUE;
    }
}
?>