<?php
namespace app\kefu\model;

use think\Model;

class Friend extends Model
{
    protected $pk = 'id';
    protected $table = 'cd_kefu_friends';
    protected $autoWriteTimestamp = true;
    
    protected $schema = [
      
    ];
}