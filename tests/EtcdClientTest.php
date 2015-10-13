<?php
namespace Packaged\Etcd\Tests;

use Packaged\Etcd\EtcdClient;

class EtcdClientTest extends \PHPUnit_Framework_TestCase
{
  private function _getClient()
  {
    return new EtcdClient('127.0.0.1');
  }

  public function testLs()
  {
    $client = $this->_getClient();

    foreach(['testA' => 'ValueA', 'testB' => 'ValueB', 'testC' => 'ValueC'] as $key => $value)
    {
      $client->set('/phpunit/ls/' . $key, $value);
      $client->set('/phpunit/ls/subdir/' . $key, $value);
    }

    $result = $client->ls('/phpunit/ls');
    $this->assertEquals(
      [
        '/phpunit/ls/testA' => 'ValueA',
        '/phpunit/ls/testB' => 'ValueB',
        '/phpunit/ls/testC' => 'ValueC',
        '/phpunit/ls/subdir' => [],
      ],
      $result
    );

    $result = $client->ls('/phpunit/ls', true);
    $this->assertEquals(
      [
        '/phpunit/ls/testA' => 'ValueA',
        '/phpunit/ls/testB' => 'ValueB',
        '/phpunit/ls/testC' => 'ValueC',
        '/phpunit/ls/subdir' => [
          '/phpunit/ls/subdir/testA' => 'ValueA',
          '/phpunit/ls/subdir/testB' => 'ValueB',
          '/phpunit/ls/subdir/testC' => 'ValueC',
        ],
      ],
      $result
    );
  }

  public function testSetGet()
  {
    $client = $this->_getClient();

    $key = '/phpunit/testSet';
    $values = [
      'test value 1',
      'test value 2'
    ];

    foreach($values as $value)
    {
      $client->set($key, $value);
      $this->assertEquals($value, $client->get($key));
    }
  }
}
