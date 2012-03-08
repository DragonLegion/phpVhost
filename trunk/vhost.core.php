<?php
class Vhost
{
	/*
	 * Global variables for the class
	 *
	 * *** PRIVATE VARIABLES ***
	 * $db = resource if connected, false otherwise
	 * $taken = true if on secondary nick, false otherwise
	 * $id = 1 if identification information sent, 2 if reply, 0 if not
	 * $sql = resource if connected to
	 * $bLog = logging class, false if uninitialized
	 * $sql = database class, false if uninitialized
	 * $vhost = vhosting class, includes methods for each server, false if uninitialized
	 * $oper = oper class, false if uninitialized
	 * $channel = channel class, false if uninitialized
	 * $admin = admin class, false if uninitialized
	 * $private = private class, false if uninitialized
	 * $fopen = fopen connection class, false if uninitialized
	 * $socket = socket connection class, false if uninitialized
	 * *** PUBLIC VARIABLES ***
	 * $irc = resource if connected, false otherwise
	 * $nick = current bot nick
	 */
	/*
	 * Private variable declarations
	 */
	private var $db = FALSE;
	private var $taken = FALSE;
	private var $id = 0;
	private var $bLog = FALSE;
	private var $sql = FALSE;
	private var $vhost = FALSE;
	private var $oper = FALSE;
	private var $channel = FALSE;
	private var $admin = FALSE;
	private var $private = FALSE;
	private var $fopen = FALSE;
	private var $socket = FALSE;
	/*
	 * Public variable declarations
	 */
	public var $irc = FALSE;
	public var $nick;
	/*
	 * This function is used to forcibly unset all unnecessary variables
	 * (including php variables) that are not in the configuration.
	 * This prevents users from executing random variable code if the
	 * variable happens to exist.
	 * THIS CLEARS $_GET, $_POST, $_SERVER, $_ENV VARIABLES SO IF THEY
	 * ARE NEEDED DO NOT CALL THIS FUNCTION.
	 */
	public function check()
	{
		if ($GLOBAL)
		{
			foreach ($GLOBAL as $key => $glob)
			{
				if ($GLOBAL[$key] != '_CONF')
				{
					unset($GLOBAL[$key],${$glob});
				}
			}
		}
		if (ini_get('max_execution_time') > 0)
		{
			set_time_limit(0);
		}
	}
	/*
	 * This function is for restarting the bot in case of a change to
	 * the config or the code itself.  There is, currently, no way to
	 * 'reload' or 'overload' a function in php that has already been
	 * defined.  The only things that can be reloaded are the config
	 * variables but since they are in the file that starts the bot
	 * it is a better idea to just restart the entire bot than to
	 * reload the config.
	 */
	public function reboot($text)
	{
		if (strlen($text) == 0)
		{
			$this->_send("QUIT Rebooting courtesy of admin");
		}
		else
		{
			$this->_send("QUIT $text");
		}
		restart();
	}
	/*
	 * This section calls the connection functions and connects to the
	 * server specified in the config file.
	 * This also opers the nick after it finishes identing to the server.
	 */
	public function connect()
	{
		global $_CONF;
		$opered = FALSE;
		//connect to sql db
		if (!$this->db)
		{
			$this->_dbConnect();
		}
		/* Include all the extension files */
		$this->_includeFiles($_CONF['addon'],$_CONF['database']);
		//actually connect to the server and send greet commands
		if ($_CONF['connect'] == 'fopen')
		{
			$this->irc = $this->fopen->connect($_CONF['servIP'], $_CONF['port']);
		}
		elseif ($_CONF['connect'] == 'socket')
		{
			$this->irc = $this->socket->connect($_CONF['servIP'], $_CONF['port']);
		}
		else
		{
			$this->_logging("Invalid connection type.  Must be fopen or socket");
			exit;
		}
		//check to make sure the connection is active
		if (!is_resource($this->irc)) {
			$this->_logging("Connection Refused by $_CONF[servIP] : Bot Dying");
			exit;
		}
		//server greet
		$this->_send('USER '.$_CONF['ident'].' ChewyNet ChewyNet :'.$_CONF['gecos']);
		$this->_send('NICK '.$_CONF['nick']);
		$this->nick = $_CONF['nick'];
		while (!feof($this->irc))
		{
			//check for server name matching conf variable
			$response = trim(fgets($this->irc, 4096));
			//If nick is already taken, use nick2
			if (substr($response,strpos($response, " ")+1,3) == 433)
			{
				$this->_send('NICK '.$_CONF['taken']);
				$this->nick = $_CONF['taken'];
				$this->taken = TRUE;
			}
			if (substr($response,strpos($response, " "),3) == 004)
			{
				$r = explode(" ",$response);
				if ($r['3'] != $_CONF['servName'])
				{
					$this->_eLog('Server name did not match');
					die('Server did not match entered value');
				}
			}
			// If PINGed, PONG, is useful for servers that require authentication
			//if (substr($response, 0, 6) == 'PING :')
			//{
				/* PONG : is followed by the line that the server sent us when PINGing */
			//	$this->_send('PONG :'.substr($response, 6));
			//	$this->_send('OPER '.$_CONF['operNick'].' '.$_CONF['operPass']);
			//	$opered = TRUE;
			//}
			break;
		}
		//Check if OPER line already sent
		if ($opered != TRUE)
		{
			$this->_send('OPER '.$_CONF['operNick'].' '.$_CONF['operPass']);
		}
		//Check for nickserv section
		if ($_CONF['ns'])
		{
			$this->_send($_CONF['ns']['alias'].' '.$_CONF['ns']['type'].' '.$_CONF['ns']['pass']);
			$this->id = 1;
		}
		//Check if a channel in the conf
		if (!empty($_CONF['channel'][0]['name']))
		{
			$this->_parse();
		}
		else
		{
			die("No channels defined.  Please edit startup file and edit in at least 1 channel");
		}
	}
	/*
	 *This function includes all class extention files in the $_CONF['addon'] array
	 */
	private function _includeFiles($array,$db)
	{
		$tot = count($array)-1;
		for ($i = 0; $i <= $tot; $i++)
		{
			if ($array[$i] == 'sql')
			{
				if ($db == 'mysql')
				{
					include('extend/mysql.vhost');
					$this->db = new sql;
				}
				elseif ($db == 'txtsql')
				{
					include('extend/txtsql.vhost');
					$this->db = new sql;
				}
			}
			else
			{
				include('extend/'.$array[$i].'.vhost');
				$this->$array[$i] = new $array[$i];
			}
		}
	}
	/*
	 * This function connects to the db's (this way I can have multiple db's with a
	 * minimum of fuss)
	 */
	private function _dbConnect()
	{
		global $_CONF;
		if ($_CONF['database'] == 'mysql')
		{
			$this->_mysql();
		}
		elseif ($_CONF['database'] == 'txtsql')
		{
			$this->_txtsql();
		}
		elseif ($_CONF['database'] == 'mysqli')
		{
			$this->_mysqli();
		}
		else
		{
			die($this->_logging('Unknown database type'));
		}
	}
	/*
	 * This function connects to the mysql database
	 */
	private function _mysql()
	{
		global $_CONF;
		$connect = mysql_connect($_CONF['sql']['host'], $_CONF['sql']['user'], $_CONF['sql']['pass']);
		$connection = mysql_select_db($_CONF['sql']['db'], $connect);
		$this->db = $connection;
	}
	/*
	 * This function connects to the txtSQL database
	 */
	private function _txtsql()
	{
		global $_CONF;
		$txtSQL = new txtSQL($_CONF['sql']['host']);
		$txtSQL->strict(0);
		$txtSQL->connect($_CONF['sql']['user'],$_CONF['sql']['pass']);
		$txtSQL->select_db($_CONF['sql']['db']);
		$this->db = $txtSQL;
	}
	/*
	 * This function connects to mysql through the mysqli wrapper
	 */
	private function _mysqli()
	{
		global $_CONF;
		$connect = new mysqli($_CONF['sql']['host'], $_CONF['sql']['user'], $_CONF['sql']['pass'],$_CONF['sql']['db']);
		$this->db = $connect;
	}
	/*
	 * This function sends commands to the server (all commands are executed through
	 * this function with NO exceptions)
	 */
	private function _send($command)
	{
		if ($this->fsock != false)
		fputs($this->irc, $command."\n\r", 4096);
		$e = explode(" ",$command);
		if ($e['1'] != 'PONG')
		{
			$this->_logging(date("[d/m @ H:i]") ."-> ". $command. "\n\r");
		}
	}
	/*
	 * Temporary logging function
	 */
	private function _logging($input)
	{
		//Temporary output
		print($input."\n\r");
		$input .= "\n";
		if (!is_dir("./logs"))
		{
			mkdir("./logs");
		}
		$fp = fopen("./logs/Vhost.log",'a');
		fwrite($fp,$input);
		fclose($fp);
	}
	/*
	 * This function actually starts the bot and joins the channels in the 
	 * $_CONF['channel'] array
	 */
	private function _parse()
	{
		global $_CONF;
		$c = count($_CONF['channel']);
		$chanList = '';
		for ($i = 0; $i < $c; $i++)
		{
			if ($i == $c-1)
			{
				$chanList .= strtolower($_CONF['channel'][$i]['name']);
			}
			else
			{
				$chanList .= strtolower($_CONF['channel'][$i]['name'].',');
			}
		}
		$this->_chanJoin($chanList);
		$this->chanList = explode(',',$chanList);
		$this->_channels();
	}
	/*
	 * This function sends the join list
	 */
	private function _chanJoin($channel)
	{
		if (strpos($channel,',') !== FALSE)
		{
			$c = explode(",", $channel);
			foreach ($c as $k)
			{
				if (strpos($c[$k],"#") != 0)
				{
					$c[$k] = "#$c[$k]";
				}
			}
			foreach ($c as $k)
			{
				$channel .= $c[$k].',';
			}
		}
		$this->_send('JOIN '.$channel);
	}
	/*
	 * This is the meat of it.  This function extends the bot life and makes the
	 * messages readable
	 */
	private function _channels()
	{
		/* Here is the loop. Read the incoming data (from the socket connection) */
		global $_CONF;
		while (!feof($this->irc))
		{
			$text = trim(fgets($this->irc, 4096));
			//Dont need to log pings, just respond to them
			// If PINGed, PONG
			if(substr($text, 0, 6) == 'PING :')
			{
				$this->_logging(date("[d/m @ H:i]")."<- ".$text);
				$this->_send('PONG :'.substr($text, 6));
			}
			else
			{
				// No ping, parse input.
				// make sense of the buffer
				$this->_logging(date("[d/m @ H:i]")."<- ".$text);
				if (!$this->db)
				{
					$this->_dbConnect();
				}
				$this->_getText($text);
			}
		}
		$this->_eLog("*Disconnected at ".time());
	}
	/*
	 * Error and disconnection logging
	 */
	private function _eLog($message)
	{
		if (!file_exists('./logs/error.log'))
		{
			$f = fopen('./logs/error.log','w');
		}
		else
		{
			$f = fopen('./logs/error.log','a');
		}
		fwrite($f,$message."\n\r");
		fclose($f);
	}
	/*
	 * This function parses the message from the server and runs the message to the correct
	 * parsing function
	 */
	private function _getText($line)
	{
		$e = explode(" ", $line, 4);
		$mspot = strpos($line, ":", 1);
		$message = substr($line,$mspot+1);
		$me = explode(" ", $message);
		if (is_numeric($me['0']))
		{
			//put in numeric coding here (and lag coding)
			$this->_rawCheck($line);
		}
		else
		{
			$user = str_replace(":", "", substr($e['0'], 1, strpos($e['0'], "!")-1));
			$uHost = str_replace(":", "", $e['0']);
			$channel = $e['2'];
			$command = $e['1'];
			switch (strtoupper($command))
			{
				case "JOIN":
					//join stuff
					$this->_cJoin($user,$channel,$uHost);
					$j = "*JOIN $user $channel";
					$this->_botLog("join",$j);
					break;
				case "PART":
					//part stuff
					$this->_cPart($user,$channel);
					$p = "*PART $user $channel";
					$this->_botLog("part",$p);
					break;
				case "QUIT":
					//quit stuff
					$this->_cQuit($user);
					$q = "*QUIT $user";
					$this->_botLog("quit",$q);
					break;
				case "MODE":
					//mode stuff
					$m = $this->_cMode($line);
					$this->_botLog("mode",$m);
					break;
				case "NICK":
					//nick stuff
					$this->_uNick($user,$e['3']);
					$n = "*NICK $user -> $e[3]";
					$this->_botLog("nick",$n);
					break;
				case "NOTICE":
					//notice stuff
					$s = $this->_snotice($line);
					if ($s)
					{
						//setup logging for snotices
					}
					else
					{
						$this->_parseInput($message);
					}
					break;
				case "PRIVMSG":
					//privmsg stuff
					if ($text['2'] == $this->nick)
					{
						$this->_parsePrivMsg($user,$message);
					}
					elseif (strpos($text['2'],"#") !== FALSE)
					{
						$this->_parseChannel($message);
					}
					else
					{
						$this->_parseInput($message);
					}
					break;
				case "KICK":
					//kick stuff
					$this->_cKick($user,$e['e']);
					$k = "*KICK $user -> $e[3]";
					$this->_botLog("kick", $k);
					break;
				default:
					//other stuff
					break;
			}
			if ($this->db)
			{
				$this->_dbDisconnect();
			}
		}
	}
	/*
	 * This function checks the joining user to see if they are on that channel's banlist
	 */
	private function _cJoin($user,$channel,$uHost)
	{
		$m1 = $this->_matchUser($channel,$_CONF['channel']);
		if (!$m1)
		{
			$this->_send("PART $channel This channel is not in my database.  If it is neccessary for me to join it please message dragon");
			return;
		}
		$banlist = $this->_getBanlist($channel);
		$match = $this->_matchUser($user,$banlist);
		if ($match)
		{
			$this->_send("MODE $channel +b $uHost");
			$this->_send("KICK $channel $user You have been banned from $channel.  Please do not attempt to return");
			$this->_send("CHANSERV akick $channel add $uHost Banned (Vhost Bot)");
		}
	}
	/*
	 * This function checks if a user can part and if not pulls them back into the channel
	 */
	private function _cPart($user,$channel)
	{
		global $_CONF;
		if ($channel == $_CONF['vChan'])
		{
			$s = $this->_getStatus($user);
			if ($s['login'] == TRUE)
			{
				$this->_send("SAJOIN $user $channel");
				sleep(2);
				$this->_send("PRIVMSG $channel :You cannot leave while you have a vhost $user");
			}
		}
	}
	/*
	 * This function updates a user's status on quit
	 */
	private function _cQuit($user)
	{
		//update the user's online value
		global $_CONF;
		$s = $this->_getStatus($user);
		if ($s['login'] == TRUE)
		{
			$this->_sql("update","users login=0 cur_nick='' WHERE cur_nick=$user");
		}
	}
	/*
	 * This function checks modes to see if the modes being set are allowed
	 */
	private function _cMode($modes)
	{
		global $_CONF;
		$me = explode(" ", $modes);
		if (count($me) > 4)
		{
			//modes for the channel
			$this->cModeCheck = 324;
			$this->_send("MODE $me[2]");
			//return is raw 324
		}
	}
	/*
	 * This function updates the database value for cur_nick to track users
	 */
	private function _uNick($user,$new)
	{
		global $_CONF;
		if (!$this->db)
		{
			$this->dbConnect();
		}
		//check db -> user -> update
		
	}
	/*
	 * Snotice checking and logging
	 */
	private function _sNotice($snotice)
	{
		//check snotices and if applicable log em
		$s = explode(" ", $snotice);
		if (stripos($snotice, "connecting") !== FALSE)
		{
			if (stripos($snotice, "port") !== FALSE)
			{
				$log = "*Local Connect ($s[9]!str_replace('(', '', str_replace(')', '', $s[10])))";
			}
			else
			{
				$log = "*Remote Connect ($s[8]!str_replace('(', '', str_replace(')', '', $s[9])))";
			}
			$this->_botLog("connect",$log);
			return;
		}
		if (stripos($s['5'], "kill") !== FALSE)
		{
			$kill = substr($snotice, strpos($snotice, $s['8'])+1);
			$log = "*KILL $s[8] $kill";
			$this->_botLog("kill", $log);
			return;
		}
		if (stripos($snotice, "exiting") !== FALSE)
		{
			if (stripos($snotice, "exiting:") !== FALSE)
			{
				$quit = substr($snotice, strpos($snotice, $s[7])+1);
				$log = "*Local Exit ($s[6]!str_replace('(', '', str_replace(')', '', $s[7])))";
			}
			else
			{
				$quit = substr($snotice,strpos($snotice, $s[8])+1);
				$log = "*Remote Exit ($s[8]) $quit";
			}
			$this->_botLog("exit",$log);
			return;
		}
		if (stripos($snotice, "operoverride") !== FALSE)
		{
			$modes = substr($snotice, strpos($snotice,$s['5'])+1);
			$log = "*OperOverride $s[4]!str_replace('(', '', str_replace(')', '', $s[5])) $modes";
			$this->_botLog('operoverride', $log);
			return;
		}
		if (stripos($snotice, "chghost") !== FALSE)
		{
			//chghost logging
		}
		if (stripos($snotice, "chgident") !== FALSE)
		{
			//chgident logging
		}
		if (stripos($snotice, "chgname") !== FALSE)
		{
			//chgname logging
		}
		if (stripos($snotice, "sethost") !== FALSE)
		{
			//sethost logging (oper tabs)
		}
		if (stripos($snotice, "setident") !== FALSE)
		{
			//setident logging (oper tabs)
		}
		if (stripos($snotice, "G:line") !== FALSE)
		{
			//gline logging
		}
		if (stripos($snotice, "Z:line") !== FALSE)
		{
			//zline logging
		}
		if (stripos($snotice, "k:line") !== FALSE)
		{
			//kline logging
		}
		//check for 'administrator' or umode?
		if (strpos($snotice, "(a)") !== FALSE)
		{
			//operup notices (or check for (a) in case-sensitive)
		}
		if (strpos($snotice, "(N)") !== FALSE)
		{
			//operup notices (or check for (N) in case-sensitive)
		}
		if (stripos($snotice, "(o)") != FALSE)
		{
			//operup notices (or check for (O) or (o) in case-insensitive)
			if (strpos($snotice,"(O)") !== FALSE)
			{
				//locop
			}
			elseif (strpos($snotice, "(o)") !== FALSE)
			{
				//globop
			}
		}
		if (strpos($snotice, "(A)") !== FALSE)
		{
			//operup notices (or check for (A) in case-sensitive)
		}
		if (strpos($snotice, "(C)") !== FALSE)
		{
			//operup notices (or check for (C) in case-sensitive)
		}
	}
	/*
	 * Actual logging call to an extension class
	 */
	private function _botLog($type,$message)
	{
		//use switch statement for log files
		//$this->bLog->log($type,$message);
	}
	/*
	 * This checks to see if the message is a command to the bot and if so, parse
	 */
	private function _parsePrivMsg($user,$message)
	{
		//check for commands
		$m = explode(" ",$message);
		if (strpos($m['0'], ".") !== FALSE)
		{
			//private command sent, parse
			$c = str_replace(".", "", $m['0']);
			$str = substr($message, strpos($message,$m['1']));
			$stop = $this->_getLoc($c);
			$this->$stop->$c($str);
		}
		elseif ($m['0'] == 'help')
		{
			$this->_vHelp($user);
		}
	}
	/*
	 * This function checks to see if it is a channel command
	 */
	private function _parseChannel($message)
	{
		//check for commands
		$m = explode(" ",$message);
		if (preg_match("!@\.\$",$m['0'],$match))
		{
			//command sent, parse
			$list = $this->_cmdList();
			$cmd = str_replace($match['0'],'',$m['0']);
			if ($this->_matchUser($cmd,$list))
			{
				$stop = $this->_getLoc($cmd);
				$msg = substr($message,strpos($message,$m['1']));
				$this->$stop->$cmd($msg);
			}
		}
	}
	/*
	 * Extraneous parsing
	 */
	private function _parseInput($message)
	{
		//wtf?
	}
	/*
	 * Pull the banlist from the database
	 */
	private function _getBanlist($channel)
	{
		//connect to db, get banlist for $channel, and return it as an array
		if (!$this->db)
		{
			$this->_dbConnect();
		}
		$bList = $this->_sql("select","user user where channel='$channel'");
		return $bList;
	}
	/*
	 * Match function (not just for matching a user in a list)
	 */
	private function _matchUser($uname,$list)
	{
		//run a loop to check if $uname is in $list anywhere
		if (is_array($list))
		{
			if (in_array($uname,$list))
			{
				return TRUE;
			}
			else
			{
				return FALSE;
			}
		}
		else
		{
			if ($uname == $list)
			{
				return TRUE;
			}
			else
			{
				return FALSE;
			}
		}
	}
	/*
	 * Return requested user's status for another function usage
	 */
	private function _getStatus($user)
	{
		//return user's vhost status, level, cur_nick, credits, active, delete status, admin login
		global $_CONF;
		if (!$this->db)
		{
			$this->_dbConnect();
		}
		$r = $this->_sql("select", "user vStatus,level,cur_nick,credits,active,delStatus,login where user=$user");
		return $r;
	}
	/*
	 * Call to the sql parser and sender (extension class)
	 */
	private function _sql($type,$query) {
		//type = query type (eg: select)
		//query = query (table select/w/e where where limit)
		global $_CONF;
		$r = $this->sql->sql($type,$query);
		if ($_CONF['database'] = 'txtsql')
		{
			foreach ($r as $k => $v) { }
			return $v;
		}
		else
		{
			return $r;
		}
	}
	/*
	 * Find out the level required for a certain command
	 */
	private function _getLoc($command)
	{
		//return command type
		if (!$this->db)
		{
			$this->_dbConnect();
		}
		$r = $this->_sql("select","user uLevel WHERE command='$command' limit 1");
		$ret = $r['uLevel'];
		return $ret;
	}
	/*
	 * Disconnect from the db to prevent massive server load
	 */
	private function _dbDisconnect()
	{
		global $_CONF;
		if ($_CONF['database'] == 'mysql')
		{
			mysql_close();
		}
		elseif ($_CONF['database'] == 'txtsql')
		{
			$this->db->disconnect();
		}
		elseif ($_CONF['database'] == 'mysqli')
		{
			$this->db->close();
		}
		$this->db = FALSE;
	}
	/*
	 * Raw numerics check
	 */
	private function _rawCheck($message)
	{
		$raw = explode(' ',$message);
		if ($raw['1'] == $this->cModeCheck)
		{
			$this->private->raw($message);
		}
	}
}
