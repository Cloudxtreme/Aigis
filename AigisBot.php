<?php

// AigisIRC
// GitHub: https://github.com/Joaquin-V/AigisIRC

// Create some constants.

// Aigis configuration directory in home.
define('AIGIS_HOME', getenv('HOME').'/.config/aigis');
if(!file_exists(AIGIS_HOME))
	mkdir(AIGIS_HOME, 0755, true);
if(!is_dir(AIGIS_HOME)){
	unlink(AIGIS_HOME);
	// Create all relevant directories.
	mkdir(AIGIS_HOME,           0755, true);
	mkdir(AIGIS_HOME.'/config', 0755, true);
	mkdir(AIGIS_HOME.'/logs'  , 0755, true);
	mkdir(AIGIS_HOME.'/sqlite', 0755, true);
}

// usr directory for random files like AigisURL/TextDB databases.
define('AIGIS_USR', AIGIS_HOME.'/usr');
if(!file_exists(AIGIS_USR))
	mkdir(AIGIS_USR, 0755, true);

// Home directory for plugins.
define('AIGIS_HOMEPLG', AIGIS_HOME.'/plugins');
if(!file_exists(AIGIS_HOMEPLG))
	mkdir(AIGIS_HOMEPLG, 0755, true);

// plugins/config for plugin configuration files.
define('PLUGIRC_CONFIG',  'plugins/config');
define('PLUGIRC_HOMECFG', AIGIS_HOMEPLG.'/config');
if(!file_exists(PLUGIRC_HOMECFG))
	mkdir(PLUGIRC_HOMECFG, 0755, true);

// Require all of the modules.
require_once "ConnIRC.php";
require_once "UserIRC.php";
require_once "MessIRCManager.php";
require_once "FontIRC.php";
require_once "PlugIRC.php";

class AigisBotException extends Exception {}

class AigisBot{

	// Version constants.
	const AIGIS_VERSION = '1.00';
	const AIGIS_VERNAME = 'Palladion';
	const AIGIS_GITHUB  = "https://github.com/MakotoYuki/Aigis/";

	// Configuration.
	protected $config  = array();
	protected $start   = 0;
	protected $verbose = false;

	// Modules.
	protected $modules = array();

	public function __construct($profile, $verbose=false){
		$this->start   = time();
		$this->config  = self::getConfigInit($profile);
		$this->verbose = $verbose;
		$this->profile = $profile;
	}


	// Returns a variable in the object.
	public function getAigisVar($varname){
		$this->errorSend(
			"DEPRECATED: AigisBot::getAigisVar() (Please use getModule() and getConfig())",
			"DEP");
		if(isset($this->$varname))
			return $this->$varname;
		return null;
	}

	// Sets an object's variable.
	public function setAigisVar($varname, $value){
		$this->errorSend(
			"DEPRECATED: AigisBot::setAigisVar() (Please use getModule() and getConfig())", 
			"DEP");
		$this->$varname = $value;
	}

	// Static function for getting config files for the first time.
	// @param  $network  - Network name.
	// @return $cofnig   - Configuration.
	// @throws Exception - When global and/or network files not found.
	protected static function getConfigInit($profile){
		// Get global configuration.
		if(file_exists('profiles/_GLOBAL_'))
			$global = parse_ini_file('profiles/_GLOBAL_', true);
		else throw new Exception('Global configuration file not found.');

		// Check user directory.
		if(file_exists(AIGIS_HOME.'/profiles/'.$profile.'.conf'))
			$netw = parse_ini_file(AIGIS_HOME.'/profiles/'.$profile.'.conf', true);
		// Check base directory.
		elseif(file_exists('profiles/'.$profile.'.conf'))
			$netw = parse_ini_file('profiles/'.$profile.'.conf', true);
		else throw new Exception('Profile not found.');

		// Start overwriting global with network settings.
		$config = $global;
		foreach($config as $section => $options){
			if(isset($global[$section]) and isset($netw[$section]))
				$config[$section] = array_merge($global[$section], $netw[$section]);
			elseif(isset($netw[$section]))
				$config[$section] = $netw[$section];
		}
		return $config;
	}

	// Function for getting configuration after initialising.
	public function getConfig(){
		return $this->config;
	}

	public function getProfileName(){
		return $this->profile;
	}

	//
	// Module management.
	//

	// Get a module.
	// @param  $module - Module name.
	// @return Module or NULL if it doesn't exist.
	public function getModule($module){
		if(isset($this->modules[$module]))
			return $this->modules[$module];
		else return null;
	}

	public function sendToModules($method, $param){
		foreach($this->modules as $module){
			if(method_exists($module, $method))
				call_user_func(array($module, $method), $param);
		}
	}

	public function loadModule($module){
		if(!class_exists($module))
			return false;

		try{
			$this->modules[$module] = new $module($this);
		}catch(Exception $e){
			unset($this->modules[$module]);
			$this->consoleSend("Error loading $module: ".$e->getMessage(), 'AIG', '!');
			return false;
		}

		$this->verboseSend("Loaded $module.", 'AIG');
		return $this->modules[$module];
	}

	/* Console sending functions.
	 * @param $message - Message to send.
	 * @param $source  - Three character line giving context (IRC-related, PlugIRC plugin, etc.)
	 * @param $type    - One character saying what kind it is (information, warning, urgent, etc.)
	 * ($type isn't used in errorSend(), it uses "!" and sends to STDERR instead of STDOUT)
	*/

	public function consoleSend($message, $source = 'NUL', $type = '-'){
		if($message == '' OR !is_string($message))
			return;
		$message = str_replace(array("\n","\r"), "", $message);
		$source  = strtoupper($source);
		if(strlen($source) != 3)
			$source = 'NUL';
		if(strlen($type)   != 1)
			$type   = '-';

		$ttymsg = @date("d/m H:i:s").  " $source $type $message\n";
		$logmsg = @date("d/m/Y H:i:s")." $source $type $message\n";
		echo $ttymsg;
		file_put_contents(AIGIS_HOME.'/logs/main.log', $logmsg, FILE_APPEND);
	}

	public function verboseSend($message, $source = 'NUL', $type = '-'){
		if($message == '' OR !is_string($message))
			return;
		$message = str_replace(array("\n","\r"), "", $message);
		$source  = strtoupper($source);
		if(strlen($source) != 3)
			$source = 'NUL';
		if(strlen($type)   != 1)
			$type   = '-';

		$ttymsg = @date("d/m H:i:s").  " $source $type $message\n";
		$logmsg = @date("d/m/Y H:i:s")." $source $type $message\n";
		if($this->verbose)
			echo $ttymsg;
		file_put_contents(AIGIS_HOME.'/logs/main.log', $logmsg, FILE_APPEND);
	}

	public function errorSend($message, $source = 'NUL'){
		if($message == '' OR !is_string($message))
			return;
		$message = str_replace(array("\n","\r"), "", $message);
		$source  = strtoupper($source);
		if(strlen($source) != 3)
			$source = 'NUL';

		$ttymsg = @date("d/m H:i:s").  " $source ! $message\n";
		$logmsg = @date("d/m/Y H:i:s")." $source ! $message\n";
		fwrite(STDERR, $ttymsg);
		file_put_contents(AIGIS_HOME.'/logs/main.log', $logmsg, FILE_APPEND);
	}

	// Custom error handler.
	// See: http://php.net/manual/en/function.set-error-handler.php
	public function errorHandler($errno, $errstr, $errfile, $errline){
		$sendMsg = sprintf('Error: %d: %s', $errno, $errstr);
		$this->errorSend($sendMsg, 'PHP');
		// Send a verbose message for the file and line.		
		$lineMsg = sprintf('File: %s on line %d', $errfile, $errline);
		$this->verboseSend($lineMsg, 'PHP');
		return true;
	}
}
