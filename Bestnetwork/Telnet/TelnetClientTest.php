<?php
namespace Bestnetwork\Telnet;

class TelnetClientTest extends \PHPUnit_Framework_TestCase {

    /**
     * @dataProvider connectionParametersProvider
     */
    public function testConnect( $host, $port = NULL, $user = NULL, $pass = NULL ){
        $client = new TelnetClient( $host, $port );
        $client->connect();

        if ($user && $pass) {
            $client->login($user, $pass);
        }

        $client->disconnect();
    }
    
    public function connectionParametersProvider(){
        return array(
            array(
                'host' => '192.168.1.1',
                'port' => 23,
                'user' => 'admin',
                'pass' => 'admin'
            )
        );
    }
}
