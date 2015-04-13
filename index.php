<?php
/**
 * Inventory WebService for the Fundus App.
 * 
 * URI for whole inventory: "http://<user>:<pass>@127.0.0.1/inventory"
 * Supported Operations:
 * + HEAD -> returns header without data (e.g. to check for service availability)
 * + GET -> Returns list of ids and names for every item formated as JSON
 * + POST name="Blättermagen" lieferant="Lunderland" ... -> Create new item
 * 
 * URI for an item with database id 67: "http://<user>:<pass>@127.0.0.1/inventory/67"
 * Supported Operations:
 * + GET -> Returns database values for the item formated as JSON
 * + PUT quantity=5 -> Adjusts quantity to 5
 * 
 * Testing with curl:
 * + HEAD: curl -u <user>:<pass> -i -X 'HEAD' http://127.0.0.1/inventory/
 * + GET: curl -u <user>:<pass> http://127.0.0.1/inventory/67
 * + PUT: curl -u <user>:<pass> -X PUT -d quantity=3 http://127.0.0.1/inventory/67
 * + POST: curl -u <user>:<pass> --request 'POST' -d 'name=wurst' http://127.0.0.1/inventory/
 * 
 */

//ini_set('display_errors', 'On');

$service = new InventoryWebservice();
$service->check_authorization();
$service->process_request();

class InventoryWebservice {
	private $mysqli;
	private $user_id;
	
	function __construct() {
		header("Content-Type:application/json");
		header("Allow: HEAD, GET, PUT, POST");
		
		// connect to database
		$this->mysqli = new mysqli("localhost","root","<password>","diehundebarf");
		if ($this->mysqli->connect_errno) {
			// 500 - Internal Server Error
			$this->respond(500, "Failed to connect to Database: (" . $this->mysqli->connect_errno . ") " . $this->mysqli->connect_error, NULL);
		}
		$this->mysqli->set_charset("utf8");
	}
	
	function __destruct() {
		$this->mysqli->close();
	}
	
	public function check_authorization() {
		$authorized = false;
		if(isset($_SERVER['PHP_AUTH_USER']) && isset($_SERVER['PHP_AUTH_PW'])) {
			$user = $this->mysqli->real_escape_string($_SERVER['PHP_AUTH_USER']);
			$pass = $this->mysqli->real_escape_string($_SERVER['PHP_AUTH_PW']);
			$result = $this->mysqli->query("SELECT benutzerID FROM benutzer WHERE benutzername = '$user' AND pin = '$pass'");
			if ($this->mysqli->error) {
				// 500 - Internal Server Error
				$this->respond(500, "Error: " . $this->mysqli->error, NULL);
			}
			$row = $result->fetch_array();
			if(isset($row['benutzerID'])) {
				// Authorized
				$this->user_id = $row['benutzerID'];
				$authorized = true;
			}
		}
		if(!$authorized){
			header('WWW-Authenticate: Basic realm="Inventory"');
			// 401 - Not authorized
			$this->respond(401, "Not Authorized", NULL);
		}
	}
	
	public function process_request() {
		switch ($_SERVER['REQUEST_METHOD']) {
			case 'GET':
				if (isset($_GET['item_id'])) {
					$this->reply_item($_GET['item_id']);
				} else {
					$this->reply_item_list();
				}
				break;
		
			case 'POST':
				parse_str(file_get_contents("php://input"), $input_vars);
				if(isset($_GET['item_id'])) {
					// 400 - Bad Request
					$this->respond(400, "Can not post on item", NULL);
				}
				if(empty($input_vars)) {
					// 400 - Bad Request
					$this->respond(400, "Values required", NULL);
				}
				$json = json_decode($input_vars);
				if(json_last_error() === JSON_ERROR_NONE) {
					// encoding: application/json
					$this->add_to_database($json);
				} else {
					// encoding: application/x-www-form-urlencoded
					$this->add_to_database($input_vars);
				}
				break;
		
			case 'PUT':
				// FIXME should be PATCH. PUT is for whole entitys, not single values
				parse_str(file_get_contents("php://input"), $input_vars);
				if(!isset($_GET['item_id'])) {
					// 400 - Bad Request
					$this->respond(400, "Item required", NULL);
				}
				if (!isset($input_vars['quantity'])) {
					// 400 - Bad Request
					$this->respond(400, "Quantity required", NULL);
				}
				$this->update_quantity($_GET['item_id'], $input_vars['quantity']);
				break;
				
			case 'HEAD':
				// 200 OK
				$this->respond(200, "Item list found", NULL);
		
			default:
				// 405 - Method Not Allowed
				$this->respond(405, "Method Not Allowed", NULL);
		}
	}
	
	private function reply_item_list() {
		$result = $this->mysqli->query("
				SELECT p.ID, p.name, p.barcode, w.name AS warengruppe
				FROM `produkte-verkauf` AS p
					JOIN warengruppe AS w
					  ON p.warengruppe = w.ID"
				);
		if ($this->mysqli->error) {
			// 500 - Internal Server Error
			$this->respond(500, "Error: " . $this->mysqli->error, NULL);
		}
		// 200 - OK
		$this->respond(200, "Item list found", $this->sql_result_to_array($result));
	}
	
	private function reply_item($item_id) {
		$item_id = $this->mysqli->real_escape_string($item_id);
		$result = $this->mysqli->query("
				SELECT p.*, b.menge, w.name AS warengruppe
				FROM   `produkte-verkauf` AS p
       				JOIN bestand AS b
         			  ON p.id = b.verkaufsid
					JOIN warengruppe AS w
					  ON p.warengruppe = w.ID
				WHERE  p.id = $item_id
		");
		if ($this->mysqli->error) {
			// 500 - Internal Server Error
			$this->respond(500, "Error: " . $this->mysqli->error, NULL);
		}
		if($result->num_rows === 0) {
			// 404 - Not Found
			$this->respond(404, "Item not found", NULL);
		}
		// 200 - OK
		$this->respond(200, "Item found", $result->fetch_assoc());
	}
	
	private function add_to_database($data) {
		$time_now = date ("Y-m-d H:i:s", time());
		$columns = "";
		$values = "";
		foreach ($data as $column => $value) {
			if($column === "ID") {
				// ingore id as a new one will be generated
				continue;
			}
			$column = "`".$column."`";
			$columns.=$this->mysqli->real_escape_string($column).", ";
			$values.="'".$this->mysqli->real_escape_string($value)."'".", ";
		}
		$columns.= "änderungsdatum , `geändert_von`";
		$values.= "'$time_now', '$this->user_id'";
		$this->mysqli->query("INSERT INTO `produkte-verkauf` ($columns) VALUES ($values);");
		if ($this->mysqli->error) {
			// 500 - Internal Server Error
			$this->respond(500, "Error: " . $this->mysqli->error, NULL);
		}
		// 201 - Created
		$this->respond(201, "Entry created", NULL);
	}
	
	private function update_quantity($item_id, $quantity){
		$item_id = $this->mysqli->real_escape_string($item_id);
		$quantity = $this->mysqli->real_escape_string($quantity);
		$time_now = date ("Y-m-d H:i:s", time());
		$result = $this->mysqli->query("
				UPDATE bestand
				SET	menge = $quantity,
					beschreibung = 'Bestandsänderung',
					änderungsdatum = '$time_now',
					`geändert_von` = '$this->user_id'
				WHERE verkaufsID = $item_id
		");
		if ($this->mysqli->error) {
			// 500 - Internal Server Error
			$this->respond(500, "Error: " . $this->mysqli->error, NULL);
		} elseif($this->mysqli->affected_rows === 0) {
			// 404 - Not Found
			$this->respond(404, "Item not found", NULL);
		} else {
			// 204 - No Content (OK, but no data in response)
			$this->respond(204, "Quantity updated ", NULL);
		}
	}
	
	private function sql_result_to_array($result) {
		$data = array();
		while ($row = $result->fetch_assoc()) {
			$data[] = $row;
		}
		return $data;
	}
	
	private function respond($status, $status_message, $data) {
		header("HTTP/1.1 $status $status_message");
	
		// pretty print for browser testing
		//$json_response=json_encode($data, JSON_PRETTY_PRINT);
		//$json_response=str_replace("\n","<br>",$json_response);
		//$json_response=str_replace("\t","<br>",$json_response);
		
		if(is_null($data)) {
			exit();
		} else {
			$json_response=json_encode($data);
			exit($json_response);
		}
	}
}

?>
