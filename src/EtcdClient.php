<?php
namespace Packaged\Etcd;

use Packaged\Etcd\Exceptions\EtcdException;
use Packaged\Etcd\Exceptions\IncorrectTypeException;
use Packaged\Etcd\Exceptions\NotFoundException;

class EtcdClient
{
  private $_hosts = [];
  private $_defaultPort;

  /**
   * @param string|array $hosts       A list of hosts to connect to.
   *                                  Can be a string containing a comma-separated list of host[:port]
   *                                  or an array of ["host[:port]"]
   * @param int          $defaultPort The port to use when connecting to hosts in the list
   *                                  that don't have a port specified
   */
  public function __construct($hosts = null, $defaultPort = 2379)
  {
    $this->_defaultPort = $defaultPort;
    if($hosts)
    {
      $this->setHosts($hosts);
    }
  }

  /**
   * @param string|array $hosts       A list of hosts to connect to.
   *                                  Can be a string containing a comma-separated list of host[:port]
   *                                  or an array of ["host[:port]"]
   */
  public function setHosts($hosts)
  {
    if(!is_array($hosts))
    {
      $hosts = explode(",", $hosts);
    }

    foreach($hosts as $host)
    {
      $parts = explode(':', trim($host), 2);

      if(isset($parts[1]) && is_numeric($parts[1]))
      {
        $port = (int)$parts[1];
      }
      else
      {
        $port = $this->_defaultPort;
      }

      $this->_hosts[] = ['host' => trim($parts[0]), 'port' => $port];
    }
  }

  /**
   * Create a key if it doesn't already exist
   *
   * @param string $key
   * @param string $value
   * @param int    $ttl
   */
  public function mk($key, $value, $ttl = 0)
  {
    $this->_doKeyWrite($key, $value, $ttl, false);
  }

  /**
   * Set the value for a key
   *
   * @param string $key
   * @param string $value
   * @param int    $ttl
   */
  public function set($key, $value, $ttl = 0)
  {
    $this->_doKeyWrite($key, $value, $ttl, null);
  }

  /**
   * Set the value for an existing key
   *
   * @param string $key
   * @param string $value
   * @param int    $ttl
   */
  public function update($key, $value, $ttl = 0)
  {
    $this->_doKeyWrite($key, $value, $ttl, true);
  }

  /**
   * Get a key's value and return the default value if it was not found
   *
   * @param string $key
   * @param string|null $default
   *
   * @return string
   * @throws EtcdException
   */
  public function tryGet($key, $default = null)
  {
    try
    {
      return $this->get($key);
    }
    catch(NotFoundException $e)
    {
      return $default;
    }
  }

  /**
   * @param string      $key
   *
   * @return string
   * @throws NotFoundException
   * @throws EtcdException
   */
  public function get($key)
  {
    $response = $this->_doRequest('GET', $this->_urlPathForKey($key));
    if($response->getStatusCode() == 404)
    {
      throw new NotFoundException($key);
    }

    $result = $response->getJsonBody();
    if(isset($result->node->dir) && $result->node->dir)
    {
      throw new IncorrectTypeException('Path is not an etcd file: ' . $key);
    }
    if(isset($result->node) && isset($result->node->value))
    {
      return $result->node->value;
    }

    throw new EtcdException(
      "Unknown error getting key " . $key . ". Full repsonse:\n"
      . $response->getRawBody()
    );
  }

  /**
   * Delete a key
   *
   * @param string $key
   *
   * @throws EtcdException
   */
  public function rm($key)
  {
    $this->_doRequest('DELETE', $this->_urlPathForKey($key));
  }

  /**
   * List a directory
   *
   * @param string $path
   * @param bool   $recursive
   *
   * @return array
   * @throws EtcdException
   */
  public function ls($path, $recursive = false)
  {
    $url = $this->_urlPathForKey($path);
    if($recursive)
    {
      $url .= '?recursive=true';
    }
    $response = $this->_doRequest('GET', $url);

    if($response->getStatusCode() == 404)
    {
      throw new NotFoundException($path);
    }

    $result = $response->getJsonBody();
    if(!isset($result->node))
    {
      throw new EtcdException(
        "Unknown error getting key " . $path . ". Full repsonse:\n"
        . $response->getRawBody()
      );
    }

    if(isset($result->node->nodes))
    {
      return $this->_parseLsNodes($result->node->nodes);
    }

    if(isset($result->node->value))
    {
      throw new IncorrectTypeException('Not an etcd directory: ' . $path);
    }

    throw new EtcdException(
      "Unable to parse etcd response:\n" . $response->getRawBody()
    );
  }

  private function _parseLsNodes(array $nodes)
  {
    $listing = [];
    foreach($nodes as $node)
    {
      if(isset($node->dir) && $node->dir)
      {
        if(isset($node->nodes))
        {
          $listing[$node->key] = $this->_parseLsNodes($node->nodes);
        }
        else
        {
          $listing[$node->key] = [];
        }
      }
      else if(isset($node->value))
      {
        $listing[$node->key] = $node->value;
      }
    }
    return $listing;
  }

  private function _urlPathForKey($key)
  {
    return '/v2/keys/' . ltrim($key, '/');
  }

  private function _makeParams($params, $ttl)
  {
    if($ttl > 0)
    {
      $params['ttl'] = $ttl;
    }
    else if(isset($params['ttl']))
    {
      unset($params['ttl']);
    }
    return $params;
  }

  /**
   * Perform a mk, set or update operation
   *
   * @param string    $key
   * @param string    $value
   * @param int       $ttl
   * @param bool|null $prevExist
   *
   * @throws EtcdException
   */
  private function _doKeyWrite($key, $value, $ttl, $prevExist)
  {
    $params = ['value' => $value];
    switch(true)
    {
      case $prevExist === true:
        $params['prevExist'] = 'true';
        break;
      case $prevExist === false:
        $params['prevExist'] = 'false';
        break;
    }

    $this->_doRequest(
      'PUT',
      $this->_urlPathForKey($key),
      $this->_makeParams($params, $ttl)
    );
  }

  /**
   * @param string $method GET, PUT or DELETE
   * @param string $url
   * @param array  $postVars
   *
   * @return HttpResponse
   * @throws EtcdException
   */
  private function _doRequest($method, $url, array $postVars = [])
  {
    $ch = curl_init();
    // TODO: Make timeout configurable
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $method = strtoupper(trim($method));
    if(($method == 'PUT') || ($method == 'DELETE'))
    {
      curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
      if($postVars)
      {
        $postStrs = [];
        foreach($postVars as $k => $v)
        {
          $postStrs[] = rawurlencode($k) . '=' . rawurlencode($v);
        }
        $postStr = implode('&', $postStrs);
        curl_setopt(
          $ch,
          CURLOPT_HTTPHEADER,
          ['Content-length' => strlen($postStr)]
        );
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postStr);
      }
    }
    else if($method != 'GET')
    {
      throw new EtcdException('Invalid HTTP method: ' . $method);
    }

    curl_setopt($ch, CURLOPT_HEADER, true);
    $rawResponse = $this->_performClusterRequest($ch, $url);

    if(!$rawResponse)
    {
      throw new EtcdException(
        "Error performing etcd request: " . $method . " " . $url
      );
    }

    // Parse the response
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    return HttpResponse::createFromRaw(
      curl_getinfo($ch, CURLINFO_HTTP_CODE),
      substr($rawResponse, 0, $headerSize),
      substr($rawResponse, $headerSize)
    );
  }

  /**
   * Perform an HTTP request, attempting all hosts in the cluster until a
   * response is received or we hit the retry limit
   *
   * @param resource $ch
   * @param string   $url
   *
   * @return bool|mixed
   */
  private function _performClusterRequest($ch, $url)
  {
    $hosts = $this->_hosts;
    shuffle($hosts);

    // TODO: Make retries configurable
    // Try each host twice until we get a meaningful response
    $rawResponse = false;
    for($i = 0; $i < 2; $i++)
    {
      if($i > 0)
      {
        // Force a new connection on the second attempt
        curl_setopt($ch, CURLOPT_FRESH_CONNECT, true);
      }
      foreach($hosts as $host)
      {
        curl_setopt(
          $ch,
          CURLOPT_URL,
          'http://' . $host['host'] . ':' . $host['port']
          . '/' . ltrim($url, '/')
        );
        $rawResponse = curl_exec($ch);

        if($rawResponse)
        {
          break;
        }
      }
      if($rawResponse)
      {
        break;
      }
    }
    return $rawResponse;
  }
}
