<?php
namespace fin1te\SafeCurl;

use CurlHandle;
use fin1te\SafeCurl\Exception;
use fin1te\SafeCurl\Exception\InvalidURLException;
use fin1te\SafeCurl\Exception\InvalidURLException\InvalidDomainException;
use fin1te\SafeCurl\Exception\InvalidURLException\InvalidIPException;
use fin1te\SafeCurl\Exception\InvalidURLException\InvalidPortException;
use fin1te\SafeCurl\Exception\InvalidURLException\InvalidSchemeException;

class SafeCurl {
    /**
     * cURL Handle
     *
     * @var resource|CurlHandle
     */
    private $curlHandle;

    /**
     * SafeCurl Options
     *
     * @var SafeCurl\Options
     */
    private $options;

    /**
     * Returns new instance of SafeCurl\SafeCurl
     *
     * @param $curlHandle resource         A valid cURL handle
     * @param $options    SafeCurl\Options optional
     */
    public function __construct($curlHandle, Options $options = null) {
        $this->setCurlHandle($curlHandle);

        if ($options === null) {
            $options = new Options();
        }
        $this->setOptions($options);
        $this->init();
    }

    /**
     * Returns cURL handle
     *
     * @return resource
     */
    public function getCurlHandle() {
        return $this->curlHandle;
    }

    /**
     * Sets cURL handle
     *
     * @param $curlHandle resource
     */
    public function setCurlHandle($curlHandle) {
        if (!((is_resource($curlHandle) && get_resource_type($curlHandle) === 'curl') || (class_exists('CurlHandle') && $curlHandle instanceof CurlHandle))) {
            throw new Exception("SafeCurl expects a valid cURL resource - '" . gettype($curlHandle) . "' provided.");
        }
        $this->curlHandle = $curlHandle;
    }

    /**
     * Gets Options
     *
     * @return Options
     */
    public function getOptions() {
        return $this->options;
    }

    /**
     * Sets Options
     *
     * @param $options Options
     */
    public function setOptions(Options $options) {
        $this->options = $options;
    }

    /**
     * Sets up cURL ready for executing
     */
    protected function init() {
        //To start with, disable FOLLOWLOCATION since we'll handle it
        curl_setopt($this->curlHandle, CURLOPT_FOLLOWLOCATION, false);

        //Always return the transfer
        curl_setopt($this->curlHandle, CURLOPT_RETURNTRANSFER, true);

        //Force IPv4, since this class isn't yet comptible with IPv6
        $curlVersion = curl_version();
        if ($curlVersion['features'] & CURLOPT_IPRESOLVE) {
            curl_setopt($this->curlHandle, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        }
    }

	public function prepare(string $url) {
        //Validate the URL
        $url = Url::validateUrl($url, $this->options);

        //Are there credentials, but we don't want to send them?
        if (!$this->options->getSendCredentials() &&
            (array_key_exists('user', $url) || array_key_exists('pass', $url))) {
            throw new InvalidURLException("Credentials passed in but 'sendCredentials' is set to false");
        }

        if ($this->options->getPinDns()) {
			$host = $url['host'];
			if (strpos($host, ':') !== false)
				throw new InvalidURLException("Malformed hostname: {$host}");
			$ips = implode(',', $url['ips']);
			$resolutions = array_map(function ($port) use ($host, $ips) {
				return "{$host}:{$port}:{$ips}";
			}, $this->options->getList('whitelist', 'port'));
			if (!curl_setopt($this->curlHandle, CURLOPT_RESOLVE, $resolutions))
				throw new Exception("Unable to override cURL DNS resolution");
        }

		curl_setopt($this->curlHandle, CURLOPT_URL, $url['cleanUrl']);
        $headers = $this->options->getHeaders();
		if ($headers !== null)
			curl_setopt($this->curlHandle, CURLOPT_HTTPHEADER, $headers);
	}

    /**
     * Exectutes a cURL request, whilst checking that the
     * URL abides by our whitelists/blacklists
     *
     * @param $url        string
     * @param $curlHandle resource         optional - Incase called on an object rather than statically
     * @param $options    Options optional
     * @return bool
     * @throws InvalidURLException
     * @throws \fin1te\SafeCurl\Exception
     */
    public static function execute($url, $curlHandle = null, Options $options = null) {
        $safeCurl = new SafeCurl($curlHandle, $options);

        //Backup the existing URL
        $originalUrl = $url;

        //Execute, catch redirects and validate the URL
        $redirected     = false;
        $redirectCount  = 0;
        $redirectLimit  = $safeCurl->getOptions()->getFollowLocationLimit();
        $followLocation = $safeCurl->getOptions()->getFollowLocation();
        do {
			$safeCurl->prepare($url);
            // in case of `CURLINFO_REDIRECT_URL` isn't defined
            curl_setopt($curlHandle, CURLOPT_HEADER, true);

            //Execute the cURL request
            $response = curl_exec($curlHandle);

            //Check for any errors
            if (curl_errno($curlHandle)) {
                throw new Exception("cURL Error: " . curl_error($curlHandle));
            }

            //Check for an HTTP redirect
            if ($followLocation) {
                $statusCode = curl_getinfo($curlHandle, CURLINFO_HTTP_CODE);
                switch ($statusCode) {
                    case 301:
                    case 302:
                    case 303:
                    case 307:
                    case 308:
                        if ($redirectLimit == 0 || ++$redirectCount < $redirectLimit) {
                            //Redirect received, so rinse and repeat
                            $url = curl_getinfo($curlHandle, CURLINFO_REDIRECT_URL);
                            $redirected = true;
                        } else {
                            throw new Exception("Redirect limit '$redirectLimit' hit");
                        }
                        break;
                    default:
                        $redirected = false;
                }
            }
        } while ($redirected);

        return $response;
    } 
}