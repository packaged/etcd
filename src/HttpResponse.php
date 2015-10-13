<?php
namespace Packaged\Etcd;

class HttpResponse
{
  private $_statusCode;
  private $_headers;
  private $_body;
  private $_jsonBody;

  /**
   * @param int    $statusCode
   * @param string $headersStr
   * @param string $body
   *
   * @return static
   */
  public static function createFromRaw($statusCode, $headersStr, $body)
  {
    $o = new static;
    $o->_statusCode = $statusCode;
    $o->_setRawHeaders($headersStr);
    $o->_body = $body;
    $o->_jsonBody = json_decode($body);
    return $o;
  }

  public function getStatusCode()
  {
    return $this->_statusCode;
  }

  public function getHeaders()
  {
    return $this->_headers;
  }

  public function getHeader($name, $default = null)
  {
    return isset($this->_headers[$name]) ? $this->_headers[$name] : $default;
  }

  public function getRawBody()
  {
    return $this->_body;
  }

  public function getJsonBody()
  {
    return $this->_jsonBody;
  }

  private function _setRawHeaders($headerStr)
  {
    $headers = [];
    $lines = preg_split('/(\r\n)|\n/', $headerStr);
    foreach($lines as $line)
    {
      $line = trim($line);
      if(strpos($line, ':') !== false)
      {
        list($name, $value) = explode(":", $line, 2);
        $headers[trim($name)] = trim($value);
      }
    }
    $this->_headers = $headers;
  }
}
