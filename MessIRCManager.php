<?php

require_once "MessIRC.php";


class MessIRCManager{

private $AigisBot;
private $SelfNick;
private $lastMessage;

public function __construct(AigisBot $AigisBot){
	$this->AigisBot = $AigisBot;
	$this->SelfNick = $AigisBot->getConfig()['Auth']['nicks'][0];
}

public function getMessage($data){
	$MessIRC = new MessIRC($data, $this->SelfNick);
	$this->lastMessage = $MessIRC;
	return $MessIRC;
}

public function lastMessage(){
	return $this->lastMessage;
}

}
