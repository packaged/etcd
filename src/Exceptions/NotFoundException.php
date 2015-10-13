<?php
namespace Packaged\Etcd\Exceptions;

class NotFoundException extends EtcdException
{
  public function __construct($path)
  {
    parent::__construct('Path not found: ' . $path);
  }
}
