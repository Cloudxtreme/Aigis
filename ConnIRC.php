<?php

class ConnIRC{

// ConnIRC
// Part of AigisBot (https://github.com/Joaquin-V/AigisBot)

const SOCKET_TIMEOUT = 100000;

const PING_FREQUENCY = 90;
const ACTIVITY_TIMEOUT = 150;
const RECONNECT_TIMEOUT = 7;
const RECONNECT_DELAY = 10;

private $AigisBot = null;

private $network  = "";
private $server   = "";
private $port     = "";

private $nicks       = array();
private $currentNick = '';

private $socket   = null;

public function __construct(AigisBot $AigisBot){
	$this->AigisBot = $AigisBot;

	$config = $AigisBot->getConfig();
	// Default to profile name for network.
	$this->network = $AigisBot->getProfileName();

	$this->server  = $config['Server']['host'];
	$this->port    = $config['Server']['port'];
	$this->nicks   = $config['Auth']['nicks'];
	$this->currentNick = $this->nicks[0];
}

public function __destruct(){
	$this->send("QUIT");
	fclose($this->socket);
}

public function connect(){
	// Close socket if it's open.
	if(@get_resource_type($this->socket) === "stream")
		fclose($this->socket);

	// Attempt to open the socket.
	$this->AigisBot->consoleSend("Connecting to $this->server:$this->port...", "ConnIRC");
	$this->socket = @fsockopen($this->server, $this->port);

	// If the connection fails.
	if(!$this->socket){
		sleep(5);
		$this->connect();
	}
	// If the connection succeeds.
	else{
		$this->AigisBot->setAigisVar("lastConn", time());

		// Send a PASS command.
		$this->send("PASS ".$this->AigisBot->getConfig()['Auth']['pass']);
		// Set nick.
		$this->send("NICK ".$this->currentNick);
		// Send the USER command
		$this->send("USER AigisBot localhost $this->server :AigisBot");
	}
}

public function disconnect(){
	if($this->socket)
		fclose($this->socket);
}

public function connected(){
	if(is_resource($this->socket) and @get_resource_type($this->socket) === "stream")
		return true;
	else return false;
}

// ConnIRC::read()
// @return string|null Last message from the IRC server or null if no new messages were received.
public function read(){
	$read = array($this->socket);
	$write = $except = null;
	if(($changed = stream_select($read, $write, $except, 0, self::SOCKET_TIMEOUT)) > 0){
		$data = trim(fgets($this->socket));
		return $data;
	}
	else
		return null;
}

public function ircMessage(MessIRC $MessIRC){
	$type = $MessIRC->getType();
	if($type == 'ping')
		$this->send('PONG :'.$MessIRC->getMessage());

	elseif($type == 'raw'){
		switch($MessIRC->getRaw()){
			case 001:
			$this->AigisBot->setAigisVar("lastRegg", time());
			// Successful connection.
			if(preg_match('/Welcome to the (\w*) [\QInternet Relay Chat\E|IRC]* Network (.*)/',
				$MessIRC->getMessage(), $match)){

				$this->network = $match[1];
				if(preg_match('/(.*)!(.*)@(.*)/', $match[2], $vhost)){
					$this->hostmask		= $vhost[0];
					$this->nick			= $vhost[1];
					$this->ident		= $vhost[2];
					$this->host			= $vhost[3];
				}else
					$this->nick = $match[2];
				}
				$this->AigisBot->consoleSend(
					"Connected to $this->network as $this->nick.",
					"ConnIRC", "success");
				// Set +B (user mode for bots).
				$this->send("MODE ".$this->nick." +B");
				// Send successful connection to plugins.
				if($PlugIRC = $this->AigisBot->getModule('PlugIRC'))
					$PlugIRC->pluginSendAll('connect', time());
				break;

			case 005:
			// Send PROTOCTL for UHNAMES and NAMESX support.
			$this->send("PROTOCTL UHNAMES");
			$this->send("PROTOCTL NAMESX");
			break;

			case 433:
			$this->attempts++;
			if(isset($this->nicks[$this->attempts]))
				$altNick = $this->nicks[$this->attempts];
			else throw new Exception('All nicks taken');
			$this->AigisIRC->consoleSend(
				"Nick is taken. Using alternative nick \"$altNick\".",
				"ConnIRC", "warning");
			$this->send("NICK " . $altNick);
			break;
		}
	}

	elseif($type == 'nick'){
		if($MessIRC->getNick() == $this->currentNick)
			$this->currentNick = $MessIRC->getMessage();
	}
}

// ConnIRC::send($data)
// @param string $data String to send to the IRC server.
public function send($data){
	fputs($this->socket, $data."\n");
}

// ConnIRC::msg($target, $message, $ctcp)
// @param string $target  User of channel to send to.
// @param string $message Message to send.
// @param string $ctcp    If passed, CTCP command to send.
public function msg($target, $message, $ctcp = null){
	if(is_array($message)){
		foreach($message as $line)
			$this->msg($target, $line, $ctcp);
		return;
	}

	if(strlen(FontIRC::stripStyles($message)) === 0)
		return;

	$hostSelf = "$this->ident@$this->host";
	$maxlen = 512 - 1 - strlen($this->nick) - 1 - strlen($hostSelf) - 9 - strlen($target) - 2 - 2;

	if(isset($ctcp))
		$maxlen -= (3 + strlen($ctcp));

	if(strpos($message, "\n") !== false){
		$message = explode("\n", $message);
		$this->msg($target, $message, $ctcp);
		return;
	}
	$message = str_replace("\r", "", $message);

	$words = explode(" ", $message);
	$string = "";

	for($i = 0, $wordCount = count($words); $i < $wordCount; $i++){
		$string .= $words[$i] . " ";

		if((isset($words[$i+1]) && strlen($string . $words[$i+1]) > $maxlen) OR !isset($words[$i+1])){
			$stringToSend = substr($string, 0, -1);

			if(isset($ctcp))
				$stringToSend = "\x01$ctcp $stringToSend\x01";

			$this->send("PRIVMSG $target :$stringToSend");
			$this->AigisBot->consoleSend(
				FontIRC::stripStyles("$target -> $string"),
				"ConnIRC", "send");
			$this->AigisBot->sendToModules("ircPrivmsgSent", "$target :$stringToSend");
			$string = "";
		}
	}
}

// ConnIRC::notice($target, $message, $ctcp)
// @param string $target  Where to send the message(s).
// @param string $message Message to send.
// @param string $ctcp    If passed, CTCP command to send.
public function notice($target, $message, $ctcp = null){
	if(is_array($message)){
		foreach($message as $line)
			$this->notice($target, $line, $ctcp);
		return;
	}

	if(strlen(FontIRC::stripStyles($message)) === 0)
		return;

	$hostSelf = "$this->ident@$this->host";
	$maxlen = 512 - 1 - strlen($this->nick) - 1 - strlen($hostSelf) - 8 - strlen($target) - 2 - 2;

	if(isset($ctcp))
		$maxlen -= (3 + strlen($ctcp));

	if(strpos($message, "\n") !== false){
		$message = explode("\n", $message);
		$this->notice($target, $message, $ctcp);
		return;
	}

	$words = explode(" ", $message);
	$string = "";

	for($i = 0, $wordCount = count($words); $i < $wordCount; $i++){
		$string .= $words[$i] . " ";

		if((isset($words[$i+1]) && strlen($string . $words[$i+1]) > $maxlen) OR !isset($words[$i+1])){
			$stringToSend = substr($string, 0, -1);

			if(isset($ctcp))
				$stringToSend = "\x01$ctcp $stringToSend\x01";

			$this->send("NOTICE $target :$stringToSend");
			$this->AigisBot->consoleSend(
				FontIRC::stripStyles("[Notice] $target -> $string"),
				"ConnIRC", "send");
			$this->AigisBot->sendToModules("ircNoticeSent", "$target :$stringToSend");
			$string = "";
		}
	}
}


// ConnIRC::join($channel) - Joins a channel.
// @param string $channel Channel to join.
public function join($channel){
	$this->send("JOIN $channel");
}

// ConnIRC::part($channel) - Parts a channel.
// @param string $channel Channel to part.
// @param string $reason  Reason to part.
public function part($channel, $reason = "AigisBot by LunarMage"){
	$this->send("PART $channel :$reason");
}

public function getNetwork(){
	return $this->network;
}

public function getNick(){
	return $this->currentNick;
}

}
