<?php
declare(strict_types=1);
require getcwd().'/vendor/autoload.php';
if (class_exists('Illuminate\\Container\\Container') || class_exists('Spatie\\WebhookClient\\WebhookConfig')) {
    throw new RuntimeException('Neutral consumer unexpectedly installed Laravel/Spatie.');
}
use Webong\Proxy\Http\HttpProfileNormalizer;
use Webong\Proxy\Http\GuzzleOptions;
use Webong\Proxy\Tcp\TcpProfile;
use Webong\Proxy\Tcp\TcpDialer;
use Webong\Proxy\Udp\UdpProfile;
use Webong\Proxy\Udp\UdpDialer;
use Webong\Proxy\Udp\Socks5Datagram;
function check(bool $ok): void {if (!$ok) throw new RuntimeException('Smoke assertion failed');}
$p=(new HttpProfileNormalizer())->normalize(['options'=>['timeout'=>3,'connect_timeout'=>2]]);
check(\Webong\Proxy\Http\HttpProfile::fromJson($p->toJson())->toArray()===$p->toArray());
function fixture(string $mode): array {
 $pipes=[];$proc=proc_open([PHP_BINARY,__DIR__.'/echo-server.php',$mode],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
 if (!is_resource($proc)) throw new RuntimeException('Server failed');
 $address=trim((string)fgets($pipes[1]));if ($address==='') throw new RuntimeException(stream_get_contents($pipes[2]));
 return [$proc,$pipes,$address];
}
foreach(['http','tcp','udp'] as $mode){
 [$proc,$pipes,$address]=fixture($mode);[$host,$port]=explode(':',$address);$port=(int)$port;
 try {
 if($mode==='http'){$client=new GuzzleHttp\Client(GuzzleOptions::fromProfile($p));check((string)$client->get('http://'.$address)->getBody()==='proxy-echo');}
 elseif($mode==='tcp'){$c=(new TcpDialer(TcpProfile::fromArray(['version'=>1])))->dial($host,$port);stream_set_timeout($c,3);fwrite($c,'tcp');check(fread($c,3)==='tcp');fclose($c);}
 else {$c=(new UdpDialer(UdpProfile::fromArray(['version'=>1])))->open('127.0.0.1');$c->sendTo('udp',$host,$port,3);check($c->receive(3)->payload==='udp');$c->close();}
 } finally {foreach($pipes as $pipe)fclose($pipe);check(proc_close($proc)===0);}
}
$d=Socks5Datagram::decode(Socks5Datagram::encode('frame','example.test',53));check($d!==null&&$d->payload==='frame'&&$d->host==='example.test');
echo "Neutral archive consumer: standalone profile export, HTTP, TCP, UDP and SOCKS5 datagram codec PASS\n";
