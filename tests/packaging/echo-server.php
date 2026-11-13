<?php
$mode=$argv[1];$udp=$mode==='udp';
$s=stream_socket_server(($udp?'udp':'tcp').'://127.0.0.1:0',$errno,$error,$udp?STREAM_SERVER_BIND:STREAM_SERVER_BIND|STREAM_SERVER_LISTEN);
if(!$s)throw new RuntimeException($error);echo stream_socket_get_name($s,false)."\n";flush();
if($udp){$data=stream_socket_recvfrom($s,1024,0,$peer);stream_socket_sendto($s,$data,0,$peer);}
else{$c=stream_socket_accept($s,10);if(!$c)exit(1);stream_set_timeout($c,5);if($mode==='http'){while(($line=fgets($c))!==false&&trim($line)!==''){}fwrite($c,"HTTP/1.1 200 OK\r\nContent-Length: 10\r\nConnection: close\r\n\r\nproxy-echo");}else{fwrite($c,fread($c,3));}fclose($c);}fclose($s);
