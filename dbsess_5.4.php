<?php
class DBSession implements SessionHandlerInterface{
	const DRIVER = 'mysql';  //Database driver
	const HOST = 'localhost';  //Server hostname or IP
	const USER = 'root';  //Server username
	const PASS = 'root';  //Server password
	const DB = 'mydb';  //Server database
	const MEM_TBL = 'sess_mem';  //Table for memory session storage
	const DISK_TBL = 'sess_disk';  //Table for disk session storage (if memory storage becomes larger than MEM_MAX)
	const ID_FIELD = 'sess_id';  //The ID field inside of the MEM_TBL and DISK_TBL tables
	const DATA_FIELD = 'data';  //The data field inside of the MEM_TBL and DISK_TBL tables
	const TIME_FIELD = 'time';  //The time field inside of the MEM_TBL and DISK_TBL tables
	const MEM_MAX = 8192;  //The max amount of data a session can hold in memory before it gets pushed to the disk
	const SESS_TIMEOUT = 900;  //Age of session before garbage collection purges it, in seconds (default value is 900, 60 * 15)
	const ENCRYPTED = false;  //Whether to use encrypted session data or not
	const ID_HASH_FUNCTION = 'sha256';  //Hashing function for the ID

	public static $db;
	public static $non_volatile = false;

	protected static $type;
	protected static $table;
	protected static $max;
	protected static $GET_DATA;
	protected static $SET_DATA;
	protected static $INSERT;
	protected static $EXISTS;
	protected static $DELETE;
	protected static $GARBAGE_COLLECT;
	protected static $UPDATE;
	protected static $WHERE;
	protected static $initialized = false;
	protected static $connected = false;

	public static function init(){
		self::$GET_DATA = 'SELECT `' . self::DATA_FIELD . '` FROM `' . self::DB . '`.`%s` WHERE `' . self::ID_FIELD . '` = ?';
		self::$SET_DATA = 'UPDATE `' . self::DB . '`.`%s` SET `' . self::DATA_FIELD . '` = ? WHERE `' . self::ID_FIELD . '` = ?';
		self::$INSERT = 'INSERT INTO `' . self::DB . '`.`%s` (`'. self::DATA_FIELD .'`, `'. self::ID_FIELD .'`) VALUES (?, ?)';
		self::$EXISTS = 'SELECT `' . self::ID_FIELD . '` FROM `' . self::DB . '`.`%s` WHERE `' . self::ID_FIELD . '` = ?';
		self::$DELETE = 'DELETE FROM `' . self::DB . '`.`%s` WHERE `' . self::ID_FIELD . '` = ?';
		self::$GARBAGE_COLLECT = 'DELETE FROM `' . self::DB . '`.`%s` WHERE UNIX_TIMESTAMP(`' . self::TIME_FIELD . '`) < ?';
		self::$UPDATE = 'UPDATE `' . self::DB . '`.`%s` SET `' . self::TIME_FIELD . '` = CURRENT_TIMESTAMP WHERE `' . self::ID_FIELD . '` = ?';
		self::$WHERE = 'SELECT (SELECT `' . self::ID_FIELD . '` FROM `' . self::DB . '`.`' . self::MEM_TBL . '` WHERE `' . self::ID_FIELD . '` = ?) AS memid, (SELECT `' . self::ID_FIELD . '` FROM `' . self::DB . '`.`' . self::DISK_TBL . '` WHERE `' . self::ID_FIELD . '` = ?) AS diskid';

		if(!self::$db && !self::connect()){
			throw new DBSession_Exception('Unable to connect to the database.');
		}

		self::$table = self::$non_volatile ? self::DISK_TBL : self::MEM_TBL;

		session_set_save_handler('static');

		self::$initialized = true;

		return true;
	}

	protected static function connect(){
		if(!self::$db){
			self::$db = new PDO(self::DRIVER . ':dbname=' . self::DB . ';host=' . self::HOST, self::USER, self::PASS);
		}

		return true;
	}

	protected static function hash_id($id){
		return hash(self::ID_HASH_FUNCTION, $id);
	}

	protected static function where_is($id){  //DOES NOT HASH ID
		$stmt = self::$db->prepare(self::$WHERE);

		$stmt->execute([$id, $id]);

		$data = $stmt->fetchAll()[0];

		$stmt->closeCursor();

		switch($id){  //A way of using a switch that a lot aren't used to, but it's fast and efficient.
			case $data['memid']:
				return self::MEM_TBL;

			case $data['diskid']:
				return self::DISK_TBL;

			default:
					return false;
		}
	}

	protected static function exists($id){
		$stmt = self::$db->prepare(sprintf(self::$EXISTS, self::$table));

		$stmt->execute([$id]);

		$ret = (boolean)count($stmt->fetchAll());

		$stmt->closeCursor();

		return $ret;
	}

	protected static function switch_table($id){  //DOES NOT HASH ID
		self::delete($id);

		switch(self::$table){
			case self::MEM_TBL:
				self::$table = self::DISK_TBL;
				break;

			case self::DISK_TBL:
				self::$table = self::MEM_TBL;
		}

		return true;
	}

	protected static function switch_if_necessary($id, $data){  //DOES NOT HASH ID
		if(self::$non_volatile){
			return true;
		}

		if(self::$table === self::MEM_TBL){
			if(strlen((binary)$data) > self::MEM_MAX){
				self::switch_table($id);
			}
		}elseif(strlen((binary)$data) <= self::MEM_MAX){
			self::switch_table($id);
		}

		return true;
	}

	protected static function set_table($id){  //DOES NOT HASH ID
		return (boolean)(self::$table = (self::where_is($id) ?: (self::$non_volatile ? self::DISK_TBL : self::MEM_TBL)));
	}

	protected static function delete($id){
		$stmt = self::$db->prepare(sprintf(self::$DELETE, self::$table));

		$stmt->execute([$id]);
		$stmt->closeCursor();

		return true;
	}

	public static function open(){
		if(!self::$initialized || !self::$connected){
			self::init();  //If this fails, it will throw an exception, so we don't need to worry about making sure it initializes properly.

			self::$connected = true;
		}

		return true;
	}

	public static function close(){
		if(self::$connected){
			self::$db = null;
			self::$connected = false;
		}

		return true;
	}

	public static function read($id){  //FRONT-END FUNCTION, WILL HASH ID
		$id = self::hash_id($id);

		self::set_table($id);

		$stmt = self::$db->prepare(sprintf(self::$GET_DATA, self::$table));

		$stmt->execute([$id]);

		$data = $stmt->fetchAll()[0][self::DATA_FIELD];

		$stmt->closeCursor();

		return $data;
	}

	public static function write($id, $data){  //FRONT-END FUNCTION, WILL HASH ID
		$id = self::hash_id($id);

		self::set_table($id);

		self::switch_if_necessary($id, $data);

		$stmt = self::$db->prepare(sprintf(self::${self::exists($id) ? 'SET_DATA' : 'INSERT'}, self::$table));

		$stmt->execute([$data, $id]);
		$stmt->closeCursor();

		return true;
	}

	public static function destroy($id){  //FRONT-END FUNCTION, WILL HASH ID
		$id = self::hash_id($id);

		self::set_table($id);
		self::delete($id);

		return true;
	}

	public static function gc(){
		foreach([self::MEM_TBL, self::DISK_TBL] as $tbl){
			$stmt = self::$db->prepare(sprintf(self::$GARBAGE_COLLECT, $tbl));

			$stmt->execute([time() - self::SESS_TIMEOUT]);
			$stmt->closeCursor();
		}

		return true;
	}
}

class DBSession_Exception extends Exception{}

if(DBSession::ENCRYPTED){
	class DBSession_Encrypted extends DBSession{
		protected static function encrypt($data){

		}

		protected static function decrypt($data){

		}

		public static function write($id, $data){
			return parent::write($id, self::encrypt($data));
		}

		public static function read($id){
			return self::decrypt(parent::read($id));
		}
	}
}

DBSession::ENCRYPTED ? DBSession_Encrypted::init() : DBSession::init();