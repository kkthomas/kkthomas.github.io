<?php
namespace TYPO3\CMS\Linkvalidator\Linktype;

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

use GuzzleHttp\Cookie\CookieJar;
use Mso\IdnaConvert\IdnaConvert;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * This class provides Check External Links plugin implementation
 */
class ExternalLinktype extends AbstractLinktype
{
    /**
     * Cached list of the URLs, which were already checked for the current processing
     *
     * @var array $urlReports
     */
    protected $urlReports = [];

    /**
     * Cached list of all error parameters of the URLs, which were already checked for the current processing
     *
     * @var array $urlErrorParams
     */
    protected $urlErrorParams = [];

    /**
     * List of headers to be used for matching an URL for the current processing
     *
     * @var array $additionalHeaders
     */
    protected $additionalHeaders = [];

    /**
     * @var RequestFactory
     */
    protected $requestFactory;

    /**
     * @var array $this->errorParams
     */
    protected $errorParams = [];

    public function __construct(RequestFactory $requestFactory = null)
    {
        $this->requestFactory = $requestFactory ?: GeneralUtility::makeInstance(RequestFactory::class);
    }

    /**
     * Checks a given URL for validity
     *
     * @param string $origUrl The URL to check
     * @param array $softRefEntry The soft reference entry which builds the context of that URL
     * @param \TYPO3\CMS\Linkvalidator\LinkAnalyzer $reference Parent instance
     * @return bool TRUE on success or FALSE on error
     * @throws \InvalidArgumentException
     */
    public function checkLink($origUrl, $softRefEntry, $reference)
    {
        // use URL from cache, if available
        if (isset($this->urlReports[$origUrl])) {
            $this->setErrorParams($this->urlErrorParams[$origUrl]);
            return $this->urlReports[$origUrl];
        }
        $options = [
            'cookies' => GeneralUtility::makeInstance(CookieJar::class),
            'allow_redirects' => ['strict' => true]
        ];
        $url = $this->preprocessUrl($origUrl);
        if (!empty($url)) {
            $isValidUrl = $this->requestUrl($url, 'HEAD', $options);
            if (!$isValidUrl) {
                // HEAD was not allowed or threw an error, now trying GET
                $options['headers']['Range'] = 'bytes=0-4048';
                $isValidUrl = $this->requestUrl($url, 'GET', $options);
            }
        }
        $this->urlReports[$origUrl] = $isValidUrl;
        $this->urlErrorParams[$origUrl] = $this->errorParams;
        return $isValidUrl;
    }

    /**
     * Check URL using the specified request methods
     *
     * @param string $url
     * @param string $method
     * @param array $options
     * @return bool
     */
    protected function requestUrl(string $url, string $method, array $options): bool
    {
        $this->errorParams = [];
        $isValidUrl = false;
        try {
            $response = $this->requestFactory->request($url, $method, $options);
            if ($response->getStatusCode() < 300) {
                $isValidUrl = true;
            } else {
                $this->errorParams['errorType'] = $response->getStatusCode();
                $this->errorParams['message'] = $this->getErrorMessage($this->errorParams);
            }
            $isValidUrl = true;
        } catch (\GuzzleHttp\Exception\TooManyRedirectsException $e) {
            // redirect loop or too many redirects
            // todo: change errorType to 'redirect' (breaking change)
            $this->errorParams['errorType'] = 'loop';
            $this->errorParams['exception'] = $e->getMessage();
            $this->errorParams['message'] = $this->getErrorMessage($this->errorParams);
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            if ($e->hasResponse()) {
                $this->errorParams['errorType'] = $e->getResponse()->getStatusCode();
            } else {
                $this->errorParams['errorType'] = 'unknown';
            }
            $this->errorParams['exception'] = $e->getMessage();
            $this->errorParams['message'] = $this->getErrorMessage($this->errorParams);
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            $this->errorParams['errorType'] = 'network';
            $this->errorParams['message'] = $this->getErrorMessage($this->errorParams);
        } catch (\Exception $e) {
            // Generic catch for anything else that may go wrong
            $this->errorParams['errorType'] = 'exception';
            $this->errorParams['exception'] = $e->getMessage();
            $this->errorParams['message'] = $this->getErrorMessage($this->errorParams);
        }
        return $isValidUrl;
    }

    /**
     * Generate the localized error message from the error params saved from the parsing
     *
     * @param array $errorParams All parameters needed for the rendering of the error message
     * @return string Validation error message
     */
    public function getErrorMessage($errorParams)
    {
        $lang = $this->getLanguageService();
        $errorType = $errorParams['errorType'];
        switch ($errorType) {
            case 300:
                $message = sprintf($lang->getLL('list.report.externalerror'), $errorType);
                break;
            case 403:
                $message = $lang->getLL('list.report.pageforbidden403');
                break;
            case 404:
                $message = $lang->getLL('list.report.pagenotfound404');
                break;
            case 500:
                $message = $lang->getLL('list.report.internalerror500');
                break;
            case 'loop':
                $message = sprintf(
                    $lang->getLL('list.report.redirectloop'),
                    $errorParams['exception'],
                    ''
                );
                break;
            case 'exception':
                $message = sprintf($lang->getLL('list.report.httpexception'), $errorParams['exception']);
                break;
            case 'network':
                $message = $lang->getLL('list.report.networkexception');
                break;
            default:
                $message = sprintf($lang->getLL('list.report.otherhttpcode'), $errorType, $errorParams['exception']);
        }
        return $message;
    }

    /**
     * Get the external type from the softRefParserObj result
     *
     * @param array $value Reference properties
     * @param string $type Current type
     * @param string $key Validator hook name
     * @return string Fetched type
     */
    public function fetchType($value, $type, $key)
    {
        preg_match_all('/((?:http|https))(?::\\/\\/)(?:[^\\s<>]+)/i', $value['tokenValue'], $urls, PREG_PATTERN_ORDER);
        if (!empty($urls[0][0])) {
            $type = 'external';
        }
        return $type;
    }

    /**
     * Convert given URL to punycode to handle domains with non-ASCII characters
     *
     * @param string $url
     * @return string
     */
    protected function preprocessUrl(string $url): string
    {
        try {
            return (new IdnaConvert())->encode($url);
        } catch (\Exception $e) {
            // in case of any error, return empty url.
            $this->errorParams['errorType'] = 'exception';
            $this->errorParams['exception'] = $e->getMessage();
            $this->errorParams['message'] = $this->getErrorMessage($this->errorParams);
            return '';
        }
    }
}
