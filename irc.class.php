<?php
class IRC{
	public $server_host = "irc.freenode.net";
	public $server_channels = "#randomchannel";
	public $server_port = 6667;
	public $server_ssl = false;
	public $nick = 'bot';
	public $master = 'admin';
	public $handlers = array();
	public $plugindir = './plugins/';
	public $debug = false;
	public $startbotting = false;
	public $reconnect = true;
	private $server;
	
	public function __construct($data)
	{
		foreach($data as $key => $value)
		{
			if(isset($this->$key))
			{
				$this->$key = $value;
			}
		}
		
		if (!is_array($this->server_channels)) {
			$this->server_channels = array($this->server_channels);
		}
	}
	
	/*
	 * Add a channel to the channel array
	 *
	 * @param mixed $chan A string or array of strings with the name(s) of the joined channel(s) 
	 */
	public function addChannels($chan){
		array_push($this->server_channels, $chan);
	}
	
	/*
	 * Remove a channel from the channel array
	 *
	 * @param mixed $chan A string or array of strings with the name(s) of the parted channel(s) 
	 */
	public function removeChannels($chan){
		if (!is_array($chan)) {
			foreach ($this->server_channels as $key => $this_channel){
				if($this_channel == $chan){
					unset($this->server_channels[$key]);
				}
			}
		}else{
			foreach ($this->server_channels as $key => $this_channel){
				foreach ($chan as $this_chan){
					if($this_channel == $this_chan){
						unset($this->server_channels[$key]);
					}
				}
			}
		}
	}
	
	//Send Raw commands
	public function sendCommand($cmd){
		fwrite($this->server['SOCKET'], $cmd, strlen($cmd)); //sends the command to the server
		if($this->debug) echo "[SEND] $cmd \n"; //displays it on the screen
	}
	
	//Send messages to users/channels
	public function sayToChannel($msg,$channel)
	{
		if(strlen($msg)>400)
		{
			$len = 399;	
			$char = substr($msg, $len ,1);
			while($char != ' ')
			{
				$len--;
				$char = substr($msg, $len ,1);
			}
			$msg2 = substr($msg, $len+1);
			$msg  = substr($msg, 0, $len);
		}
		
		$this->sendCommand('PRIVMSG '.$channel.' :'.$msg."\r\n");
		if (isset($msg2)) {
			return $this->sayToChannel($msg2,$channel);
		}
	}

	public function connect(){
		set_time_limit(0);
		while($this->reconnect){
			$this->server = array(); //we will use an array to store all the server data.
			//Open the socket connection to the IRC server
			$this->server['SOCKET'] = fsockopen(($this->server_ssl ? 'ssl://' : '') . $this->server_host, $this->server_port, $errno, $errstr, 2);
			if($this->server['SOCKET'])
			{
				//Ok, we have connected to the server, now we have to send the login commands.
				$this->sendCommand("PASS NOPASS\n\r"); //Sends the password not needed for most servers
				$this->sendCommand("NICK $this->nick\n\r"); //sends the nickname
				$this->sendCommand("USER $this->nick USING PHP IRC\n\r"); //sends the user must have 4 paramters
				while(!@feof($server['SOCKET'])) //while we are connected to the server
				{
					//get a line of data from svr
					$this->server['READ_BUFFER'] = fgets($this->server['SOCKET'], 1024); 
					if(empty($this->server['READ_BUFFER'])) continue;
					
					//display the recived data from the server
					if($this->debug) echo "[RECIVE] ".$this->server['READ_BUFFER']."\n"; 
					
					//Now lets check to see if we have joined the server
					//376 is the message number of the MOTD for the server
					//(The last thing displayed after a successful connection)
					if(strpos($this->server['READ_BUFFER'], "376")) 
					{
						//If we have joined the server
						foreach ($this->server_channels as $chan){
							$this->sendCommand("JOIN {$chan}\n\r"); //Join the chanel
						}
					}
					
					//If the server has sent the ping command
					if(substr($this->server['READ_BUFFER'], 0, 6) == "PING :") 
					{
						//Reply with pong
						$this->sendCommand("PONG :".substr($this->server['READ_BUFFER'], 6)."\n\r"); 
						//As you can see i dont have it reply with just "PONG"
						//It sends PONG and the data recived after the "PING" text on that recived line
						//Reason being is some irc servers have a "No Spoof" 
						//feature that sends a key after the PING
						//Command that must be replied with PONG and the same key sent.
					}
					
					if($this->startbotting == true &&
						/*strrpos($this->server['READ_BUFFER'],':'.$this->master."!n")!==false &&*/
						strrpos($this->server['READ_BUFFER'],'PRIVMSG')!==false)
					{
						//Someone said sth!
						$msg = explode('PRIVMSG ',$this->server['READ_BUFFER'],2);
						preg_match('/:(.*)!/',$msg[0],$matches);
						$who = $matches[1];
						list($channel,$msg) = explode(' :',$msg[1],2);
						if($this->debug) echo '['.$who.' on '.$channel.']: '.$msg;
						$this->runHandlers($msg,$channel,$who);
					}
					
					if(strrpos($this->server['READ_BUFFER'],'Closing Link')!==false)
					{
						@fclose($server['SOCKET']);
						unset($server['SOCKET']);
						//Just continue running the bot but no more loop!
						break;
					}
					//This flushes the output buffer forcing the text in the while loop
					// to be displayed "On demand"
					flush(); 
				}
				echo 'Disconnected from server';	
			}else{
				die('Could not connect to server. Error #'.$errno.' ('.$errstr.')');
			}
		}
	}

	public function initBot()
	{
		if(file_exists($this->plugindir))
		{
			//Batch loading!
			$dir = scandir($this->plugindir);
			foreach($dir as $file)
			{
				if($file != '.' && $file != '..' && preg_match('/\.plugin\.php$/',$file))
				{
					//A plugin. Let's load it!
					$thisfile = realpath($this->plugindir).'/'.basename($file);
					$syntaxcheck = shell_exec('php -l '.escapeshellarg($thisfile));
					if(strpos($syntaxcheck,'No syntax errors detected')!==false)
					{
						//It's OK, will not disturb us :p
						include($thisfile);
						echo 'OK Loading:    '.$file."\n";
					}else{
						//Fuckin' coder! You wanted to kill me!
						echo 'Error Loading: '.$file.' (syntax error)'."\n";
					}
				}
			}
			//Enable plugins now
			$this->startbotting = true;
		}else{
			echo 'Not using Plugin System, the bot will just connect.'."\n";
			$this->startbotting = null;
		}
	}
	
	private function runHandlers($msg,$channel,$who)
	{
		if($channel == $this->nick)
		{
			$channel = $who;
		}
		
		//Build "Handlers for the user" list
		$handlers = $this->handlers['*'];
		$who2 = strtolower($who);
		if($who == $this->master)
		{
			$handlers = array_merge($handlers,$this->handlers['admin']);
		}
		if($who2 != 'admin' && isset($this->handlers[$who2]))
		{
			$handlers = array_merge($handlers,$this->handlers[$who2]);
		}

		//Run the handlers
		foreach($handlers as $function => $regex)
		{
			if(preg_match($regex, $msg, $matches))
			{
				//Clean the matches
				foreach($matches as $key => $value) {
					if(empty($value))
					{
						unset($matches[$key]);
					}else{
						$matches[$key] = trim($matches[$key]);
					}
				}
				$matches = array_values($matches); 
				//Call it!
				echo 'Running '.$function."\n";
				$function($this,$msg,$channel,$matches,$who);
				break;
			}
		}
	}
}
