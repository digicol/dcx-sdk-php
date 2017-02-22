<?php

namespace Digicol\DcxSdk;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\CookieJarInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Psr\Http\Message\ResponseInterface;


/**
 * DC-X JSON API HTTP Client
 *
 * A PHP client for the DC-X DAM system JSON API.
 * At DC, we’re using this client for automated integration tests,
 * so while it’s far from finished, it’s already working.
 * 
 * @package Digicol\DcxSdk
 */
class DcxApiClient
{
    const HTTP_TIMEOUT = 30.0;
    const HTTP_CONNECT_TIMEOUT = 5.0;

    /** @var string Base URL */
    protected $url;
    
    /** @var string DC-X username */
    protected $username;
    
    /** @var string DC-X password */
    protected $password;

    /** @var array HTTP headers to add to each request */
    protected $customHttpHeaders = [];
    
    /** @var string HTTP User-Agent string */
    protected $httpUserAgent = 'Digicol-DcxApiClient/2.0 (http://www.digicol.de/)';

    /** @var CookieJarInterface */
    protected $cookieJar;


    /**
     * DcxApiClient constructor.
     *
     * @param string $url
     * @param string $username
     * @param string $password
     * @param array $options
     */
    public function __construct($url, $username, $password, $options = [])
    {
        if (substr($url, -1) !== '/') {
            $url .= '/';
        }

        $this->url = $url;
        $this->username = $username;
        $this->password = $password;

        $this->guzzleClient = new Client
        (
            [
                'base_uri' => $this->url
            ]
        );

        $this->cookieJar = new CookieJar();

        if (isset($options['http_headers']) && is_array($options['http_headers'])) {
            $this->customHttpHeaders = $options['http_headers'];
        }

        if (isset($options['http_useragent'])) {
            $this->httpUserAgent = $options['http_useragent'];
        }
    }


    /**
     * @param CookieJarInterface $cookieJar
     */
    public function setCookieJar(CookieJarInterface $cookieJar)
    {
        $this->cookieJar = $cookieJar;
    }


    /**
     * @param array $data
     * @return int HTTP status code
     */
    public function getContext(&$data)
    {
        $data = [];
        $url = $this->fullUrl('_context');

        $cache_filename = '/tmp/dcx_api_client_context_' . md5($url) . '.ser';

        // Refresh JSON-LD context cache after 1 hour
        $cache_maxage = (60 * 60 * 1);

        if
        (
            file_exists($cache_filename)
            && (filesize($cache_filename) > 0)
            && ((time() - filemtime($cache_filename)) < $cache_maxage)
        ) {
            $data = unserialize(file_get_contents($cache_filename));

            return 200;
        }

        try {
            $response = $this->guzzleClient->get
            (
                $url,
                $this->getRequestOptions(['query' => $this->mergeQuery($url, [])])
            );
        } catch (RequestException $exception) {
            if ($exception->hasResponse()) {
                $response = $exception->getResponse();
            } else {
                return 500;
            }
        }

        if ($this->isJsonResponse($response)) {
            $data = json_decode($response->getBody(), true);
        }

        $statusCode = $response->getStatusCode();

        if (! is_array($data)) {
            $data = [];

            return $statusCode;
        }

        if (! isset($data['@context'])) {
            $data = [];

            return $statusCode;
        }

        $data = $data['@context'];

        file_put_contents($cache_filename, serialize($data));

        return $statusCode;
    }


    /**
     * @param string $url
     * @param array $query
     * @param array $data
     * @return int HTTP status code
     */
    public function getObject($url, array $query, &$data)
    {
        return $this->request
        (
            'GET',
            $this->fullUrl($url),
            $this->getRequestOptions(['query' => $this->mergeQuery($url, $query)]),
            $data
        );
    }


    /**
     * @param string $url
     * @param array $query
     * @param array $data
     * @return int HTTP status code
     */
    public function getObjects($url, array $query, &$data)
    {
        return $this->getObject($url, $query, $data);
    }


    /**
     * @param string $url
     * @param array $query
     * @param array $data
     * @param array $responseBody
     * @return int HTTP status code
     */
    public function createObject($url, array $query, array $data, &$responseBody)
    {
        return $this->request
        (
            'POST',
            $this->fullUrl($url),
            $this->getRequestOptions(['query' => $this->mergeQuery($url, $query), 'json' => $data]),
            $responseBody
        );
    }


    /**
     * @param string $url
     * @param array $query
     * @param array $data
     * @param array $responseBody
     * @return int HTTP status code
     */
    public function setObject($url, array $query, array $data, &$responseBody)
    {
        return $this->request
        (
            'PUT',
            $this->fullUrl($url),
            $this->getRequestOptions(['query' => $this->mergeQuery($url, $query), 'json' => $data]),
            $responseBody
        );
    }


    /**
     * @param string $url
     * @param array $query
     * @param array $responseBody
     * @return int HTTP status code
     */
    public function deleteObject($url, array $query, &$responseBody)
    {
        return $this->request
        (
            'DELETE',
            $this->fullUrl($url),
            $this->getRequestOptions(['query' => $this->mergeQuery($url, $query)]),
            $responseBody
        );
    }


    /**
     * @param string $filename
     * @param array $params
     * @param array $responseBody
     * @return int HTTP status code
     */
    public function uploadFile($filename, array $params, &$responseBody)
    {
        if (! file_exists($filename)) {
            return -1;
        }

        if (empty($params['content_type'])) {
            $params['content_type'] = 'application/octet-stream';
        }

        if (empty($params['slug'])) {
            $params['slug'] = basename($filename);
        }

        $fp = fopen($filename, 'r');

        return $this->request
        (
            'POST',
            $this->fullUrl('_file_upload'),
            $this->getRequestOptions
            (
                [
                    'body' => $fp,
                    'headers' =>
                        [
                            'Content-Type' => $params['content_type'],
                            'Slug' => $params['slug']
                        ]
                ]
            ),
            $responseBody
        );
    }


    /**
     * @param string $uploadconfig_id
     * @param array $params
     * @param array $response_body
     * @return int HTTP status code
     */
    public function upload($uploadconfig_id, array $params, &$response_body)
    {
        $this->flattenPostfields($params, $flattened);

        return $this->request
        (
            'POST',
            $this->fullUrl('_upload/' . urlencode($uploadconfig_id)),
            $this->getRequestOptions(['multipart' => $flattened]),
            $response_body
        );
    }


    /**
     * @param string $type Example: "dcx:document"
     * @param string $objectId Example: "doc123"
     * @return string Example: "http://example.com/dcx/api/document/doc123"
     */
    public function objectIdToUrl($type, $objectId)
    {
        return $this->fullUrl(sprintf
        (
            '%s/%s',
            substr($type, 4),
            urlencode($objectId)
        ));
    }


    /**
     * @param string $url
     * @param string $objectType
     * @param string $objectId
     * @return int 1
     */
    public function urlToObjectId($url, &$objectType, &$objectId)
    {
        $path = parse_url($url, PHP_URL_PATH);

        $objectId = basename($path);
        $objectType = basename(dirname($path));

        return 1;
    }


    /**
     * @param string $type Example: "dcx:document"
     * @return string Example: "http://example.com/dcx/api/document"
     */
    public function typeToCollectionUrl($type)
    {
        return $this->fullUrl(substr($type, 4));
    }


    /**
     * @param string $incompleteUrl Example: "document/doc123"
     * @return string Example: "http://example.com/dcx/api/document/doc123"
     */
    public function fullUrl($incompleteUrl)
    {
        return (string)UriResolver::resolve(new Uri($this->url), new Uri($incompleteUrl));
    }


    /**
     * @param string $method HTTP method
     * @param string $url
     * @param array $options
     * @param array $responseData
     * @return int HTTP status code
     */
    protected function request($method, $url, array $options, &$responseData)
    {
        $responseData = [];

        try {
            $response = $this->guzzleClient->request
            (
                $method,
                $url,
                $options
            );
        } catch (RequestException $exception) {
            if ($exception->hasResponse()) {
                $response = $exception->getResponse();
            } else {
                return 500;
            }
        }

        if ($this->isJsonResponse($response)) {
            $responseData = $this->decodeJson($response->getBody());
        }

        return $response->getStatusCode();
    }


    /**
     * @param array $addOptions
     * @return array
     */
    protected function getRequestOptions(array $addOptions)
    {
        $defaultOptions =
            [
                'timeout' => self::HTTP_TIMEOUT,
                'connect_timeout' => self::HTTP_CONNECT_TIMEOUT,
                'auth' => [$this->username, $this->password],
                'cookies' => $this->cookieJar,
                'headers' =>
                    [
                        'User-Agent' => $this->httpUserAgent,
                        'Accept' => 'application/json'
                    ]
            ];

        $defaultOptions['headers'] = array_merge($defaultOptions['headers'], $this->customHttpHeaders);
        
        return array_merge_recursive
        (
            $defaultOptions,
            $addOptions
        );
    }


    /**
     * @param string $url
     * @param array $query
     * @return array
     */
    protected function mergeQuery($url, array $query)
    {
        // Combine URL query parameters and the $query array
        // XXX Hack? We don't try to merge recursively, simply
        // overwrite identically-named top-level query parameters

        $urlQueryString = (new Uri($url))->getQuery();

        parse_str($urlQueryString, $urlQuery);

        return array_merge($urlQuery, $query);
    }


    /**
     * @param array $values
     * @param array $result
     * @param string $fieldNamePrefix
     */
    protected function flattenPostfields(array $values, &$result, $fieldNamePrefix = '')
    {
        // Curl / Guzzle is too dumb to understand nested arrays on multipart/form-data.
        // Flatten them (assuming CurlFile is used for files).
        // See http://stackoverflow.com/questions/3772096/posting-multidimensional-array-with-php-and-curl

        if (! is_array($result)) {
            $result = [];
        }

        foreach ($values as $key => $value) {
            if ($fieldNamePrefix === '') {
                $fieldName = $key;
            } else {
                $fieldName = sprintf('%s[%s]', $fieldNamePrefix, $key);
            }

            if (is_array($value)) {
                $this->flattenPostfields($value, $result, $fieldName);
            } elseif (is_object($value) && ($value instanceof \CURLFile)) {
                $result[] =
                    [
                        'name' => $fieldName,
                        'contents' => fopen($value->getFilename(), 'r'),
                        'filename' => $value->getFilename()
                    ];
            } else {
                $result[] =
                    [
                        'name' => $fieldName,
                        'contents' => $value
                    ];
            }
        }
    }


    /**
     * @param ResponseInterface $response
     * @return bool
     */
    protected function isJsonResponse(ResponseInterface $response)
    {
        return $this->isJson($response->getHeader('Content-Type')[0]);
    }


    /**
     * @param string $contentType HTTP Content-Type header
     * @return bool
     */
    protected function isJson($contentType)
    {
        // Accept "application/json", "application/problem+json; charset=UTF-8"
        // XXX a regular expression might be better!

        list($contentType,) = array_map('trim', explode(';', $contentType));

        $parts = explode('/', $contentType);

        if ($parts[0] !== 'application') {
            return false;
        }

        return (($parts[1] === 'json') || (substr($parts[1], -5) === '+json'));
    }


    /**
     * @param string $jsonStr
     * @return mixed
     */
    protected function decodeJson($jsonStr)
    {
        $result = json_decode($jsonStr, true);

        if (! is_array($result)) {
            return $result;
        }

        $this->resolveCompactUrls($result, $this->getCompactUrlPrefixes());

        return $result;
    }


    /**
     * @param array $arr
     * @param array $prefixes
     */
    protected function resolveCompactUrls(&$arr, array $prefixes)
    {
        foreach ($arr as $key => $value) {
            if (is_array($value)) {
                $this->resolveCompactUrls($arr[$key], $prefixes);
                continue;
            }

            if ($key === '_id') {
                $arr['_id_url'] = $this->resolveCompactUrl($value, $prefixes);
            }

            ksort($arr);
        }
    }


    /**
     * @param string $url
     * @param array $prefixes
     * @return string
     */
    protected function resolveCompactUrl($url, array $prefixes)
    {
        $parts = explode(':', $url, 2);

        if (count($parts) === 1) {
            return $url;
        }

        list($prefix, $suffix) = $parts;

        if (substr($suffix, 0, 2) === '//') {
            return $url;
        }

        if (! isset($prefixes[$prefix])) {
            return $url;
        }

        return $prefixes[$prefix] . $suffix;
    }


    /**
     * @return array
     */
    protected function getCompactUrlPrefixes()
    {
        $result = [];

        $this->getContext($context);

        if (! is_array($context)) {
            return $result;
        }

        foreach ($context as $key => $value) {
            if (is_string($value)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
