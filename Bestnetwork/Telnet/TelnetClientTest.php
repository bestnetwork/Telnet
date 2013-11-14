<?php
namespace Bestnetwork\Telnet;

class TelnetClientTest extends \PHPUnit_Framework_TestCase {

    /**
     * @dataProvider connectionParametersProvider
     */
    public function testConnect( $host, $port, $user, $pass ){
        $client = new TelnetClient( $host, $port );
        $client->connect();
        $client->login($user, $pass);
        $client->disconnect();
    }
    
    public function connectionParametersProvider(){
        return array(
            array(
                'host' => '192.168.1.91',
                'port' => 23,
                'user' => 'Admin',
                'pass' => '2n'
            )
        );
    }
}
