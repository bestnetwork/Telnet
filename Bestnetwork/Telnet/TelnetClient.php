<?php
namespace Bestnetwork\Telnet;

/**
* TelnetClient class
*
* Used to execute remote commands via telnet connection
* Usess sockets functions and fgetc() to process result
*
* All methods throw Exceptions on error
*
* Written by Dalibor Andzakovic <dali@swerve.co.nz>
* Based on the code originally written by Marc Ennaji and extended by
* Matthias Blaser <mb@adfinis.ch>
*
* Extended by Christian Hammers <chammers@netcologne.de>
*
*/
class TelnetClient {

    private $host;
    private $port;
    private $timeout;

    private $socket = NULL;
    private $buffer = NULL;
    private $prompt;
    private $err_prompt;
    private $errno;
    private $errstr;

    private $NULL;
    private $DC1;
    private $WILL;
    private $WONT;
    private $DO;
    private $DONT;
    private $IAC;

    private $global_buffer = '';

    /**
     * Constructor. Initialises host, port and timeout parameters
     * defaults to localhost port 23 (standard telnet port)
     *
     * @param string $host Host name or IP addres
     * @param int $port TCP port number
     * @param int $timeout Connection timeout in seconds
     * @return void
     */
    public function __construct( $host = '127.0.0.1', $port = '23', $timeout = 10, $prompt = '$', $err_prompt = 'ERROR' ){
        $this->host = $host;
        $this->port = $port;
        $this->timeout = $timeout;
        $this->prompt = $prompt;
        $this->err_prompt = $err_prompt;

        // set some telnet special characters
        $this->NULL = chr(0);
        $this->CR = chr(13);
        $this->DC1 = chr(17);
        $this->WILL = chr(251);
        $this->WONT = chr(252);
        $this->DO = chr(253);
        $this->DONT = chr(254);
        $this->IAC = chr(255);

        $this->connect();
    }

    /**
     * Destructor. Cleans up socket connection and command buffer
     *
     * @return void
     */
    public function __destruct(){
        // cleanup resources
        $this->disconnect();
        $this->buffer = NULL;
        $this->global_buffer = NULL;
    }

    /**
     * Attempts connection to remote host. Returns TRUE if sucessful.
     *
     * @return boolean
     */
    public function connect(){
        // check if we need to convert host to IP
        if( !preg_match('/([0-9]{1,3}\\.){3,3}[0-9]{1,3}/', $this->host) ){
            $ip = gethostbyname($this->host);

            if( $this->host == $ip ){
                throw new TelnetException('Cannot resolve ' . $this->host);
            }else{
                $this->host = $ip;
            }
        }

        // attempt connection
        $this->socket = fsockopen($this->host, $this->port, $this->errno, $this->errstr, $this->timeout);

        if( !$this->socket ){
            throw new TelnetException('Cannot connect to ' . $this->host . ' on port ' . $this->port);
        }
    }

    /**
     * Closes IP socket
     *
     * @return boolean
     */
    public function disconnect(){
        if( $this->socket ){
            if( !fclose($this->socket) ){
                throw new TelnetException('Error while closing telnet socket');
            }
            $this->socket = NULL;
        }
    }

    /**
     * Executes command and returns a string with result.
     * This method is a wrapper for lower level private methods
     *
     * @param string $command Command to execute
     * @return string Command result
     */
    public function execute( $command, $prompt = NULL, $err_prompt = NULL ){
        $this->write($command);
        $this->read($prompt, $err_prompt);
        return $this->getBuffer();
    }

    /**
     * Attempts login to remote host.
     * This method is a wrapper for lower level private methods and should be
     * modified to reflect telnet implementation details like login/password
     * and line prompts. Defaults to standard unix non-root prompts
     *
     * @param string $username Username
     * @param string $password Password
     * @return boolean
     */
    public function login( $username, $password ){
        
        try{
            $this->read('Login:');
            $this->write((string) $username);
            $this->read('Password:');
            $this->write((string) $password);
            $this->read('OK');

        } catch( TelnetException $e ){
            throw new TelnetException('Login failed.', 0, $e);
        }
    }

    /**
     * Sets the string of characters to respond to.
     * This should be set to the last character of the command line prompt
     *
     * @param string $s String to respond to
     * @return boolean
     */
    public function setPrompt( $s = '$' ){
        $this->prompt = $s;
    }

    /**
     * Sets the string of characters to respond to.
     * This should be set to the last character of the command line prompt
     *
     * @param string $s String to respond to
     * @return boolean
     */
    public function setErrPrompt( $s = 'ERR' ){
        $this->err_prompt = $s;
    }

    /**
     * Gets character from the socket
     *
     * @return void
     */
    protected function getc(){
        $c = fgetc($this->socket);
        $this->global_buffer .= $c;
        return $c;
    }

    /**
     * Clears internal command buffer
     *
     * @return void
     */
    public function clearBuffer(){
        $this->buffer = '';
    }

    /**
     * Reads characters from the socket and adds them to command buffer.
     * Handles telnet control characters. Stops when prompt is ecountered.
     *
     * @param string $prompt
     * @param string $err_prompt
     * @return boolean
     */
    protected function read( $prompt = NULL, $err_prompt = NULL ){
        if( !$this->socket ){
            throw new TelnetException('Telnet connection closed');
        }
        
        if( is_null($prompt) ){
            $prompt = $this->prompt;
        }
        
        if( is_null($err_prompt) ){
            $err_prompt = $this->err_prompt;
        }

        // clear the buffer
        $this->clearBuffer();

        $until_t = time() + $this->timeout;
        do {
            // time's up (loop can be exited at end or through continue!)
            if( time() > $until_t ){
                throw new TelnetException('Couldn\'t find the requested: "' . $prompt . '" within ' . $this->timeout . ' seconds');
            }

            $c = $this->getc();

            if( $c === false ){
                throw new TelnetException('Couldn\'t find the requested: "' . $prompt . '", it was not in the data returned from server: ' . $this->buffer);
            }

            // Interpreted As Command
            if( $c == $this->IAC ){
                if($this->negotiateTelnetOptions()){
                    continue;
                }
            }

            // append current char to global buffer
            $this->buffer .= $c;

            // we've encountered the prompt. Break out of the loop
            if( substr($this->buffer, strlen($this->buffer) - strlen($prompt)) == $prompt ){
                return substr($this->buffer, 0, strlen($this->buffer) - strlen($prompt));
            }elseif( strlen($err_prompt) && substr($this->buffer, strlen($this->buffer) - strlen($err_prompt)) == $err_prompt ){
                throw new TelnetException('Commad has returned ERROR status');
            }

        }while( $c != $this->NULL || $c != $this->DC1 );
    }

    /**
     * Write command to a socket
     *
     * @param string $buffer Stuff to write to socket
     * @param boolean $addNewLine Default true, adds newline to the command
     * @return boolean
     */
    protected function write( $buffer, $addNewLine = true ){
        if( !$this->socket ){
            throw new TelnetException('Telnet connection closed');
        }

        // clear buffer from last command
        $this->clearBuffer();

        if( $addNewLine == true ){
            $buffer .= $this->CR;
        }

        $this->global_buffer .= $buffer;
        if( !fwrite($this->socket, $buffer) < 0 ){
            throw new TelnetException('Error writing to socket');
        }
    }

    /**
     * Returns the content of the command buffer
     *
     * @return string Content of the command buffer
     */
    protected function getBuffer(){
            // cut last line (is always prompt)
            $buf = explode("\n", $this->buffer);
            unset($buf[count($buf) - 1]);
            $buf = implode("\n", $buf);
            return trim($buf);
    }

    /**
     * Returns the content of the global command buffer
     *
     * @return string Content of the global command buffer
     */
    public function getGlobalBuffer(){
            return $this->global_buffer;
    }

    /**
     * Telnet control character magic
     *
     * @param string $command Character to check
     * @return boolean
     */
    protected function negotiateTelnetOptions(){
        $c = $this->getc();

        if( $c != $this->IAC ){
            if( $c == $this->DO || $c == $this->DONT ){
                $opt = $this->getc();
                fwrite($this->socket, $this->IAC . $this->WONT . $opt);
            }else if(($c == $this->WILL) || ($c == $this->WONT)){
                $opt = $this->getc();
                fwrite($this->socket, $this->IAC . $this->DONT . $opt);
            }else{
                throw new TelnetException('Error: unknown control character ' . ord($c));
            }
        } else {
            throw new TelnetException('Error: Something Wicked Happened');
        }
    }
}
