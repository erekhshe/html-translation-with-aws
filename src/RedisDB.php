<?php
require __DIR__ . '/../vendor/autoload.php';
use Predis\Client;

class RedisDB {
    public $redis;
    public function __construct() {
        $this->redis = new Client();
        $this->redis->select(5);
        $this->redis->config('SET', 'save', '60 1000');
        $this->redis->save();
    }
    public function del_all() {
        $redis = $this->redis;
        $redis->executeRaw(['FLUSHALL']);
    }
    public function get_all($pattern) {
        $redis = $this->redis;
        return $redis->keys("$pattern:*");
    }
    public function ins($to,$arr){
        if ($arr){
            if (($arr['orgtext'] !== "" and $arr['translate'] !== "") and !is_numeric($arr['orgtext'])){
                $redis=$this->redis;
                $redis->set("$to:{$arr['orgtext']}", $arr['translate']);
            }
        }
    }
    public function get($to,$key) {
        $redis=$this->redis;
        $searchTxt = $to.":".$key;
        return $redis->get($searchTxt);
    }
}