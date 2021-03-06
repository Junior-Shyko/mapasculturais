<?php
/**
 * CAMA Legal Information
 * Return CAMA legal information for a parcel.
 * 
 * @param 		string 		$pid 			Parcel ID
 * @param 		string		$pidtype		Parcel ID type, tax or common
 * @param 		string		$format			format of output, either json or xml
 * @return 		string						resulting json or xml string
 */

# Includes
require_once("../../inc/error.inc.php");
require_once("../../inc/database.inc.php");
require_once("../../inc/security.inc.php");

# Set arguments for error email 
$err_user_name = "Tobin";
$err_email = "tobin.bradley@mecklenburgcountync.gov";

# Retrive URL arguments
try {
	$pid = $_REQUEST['pid'];
	$pidtype = $_REQUEST['pidtype'];
	$format = $_REQUEST['format'];
} 
catch (Exception $e) {
    trigger_error("Caught Exception: " . $e->getMessage(), E_USER_ERROR);
}

# Performs the query and returns XML or JSON
try {
	$sql = "select id_pid as parcel_id, id_common_pid as common_parcel_id, txt_legaldesc as legal_description, txt_accounttype_desc as account_type, txt_taxmun_desc as municipality, 
		txt_taxfire_desc as fire_district from dbo.tb_PubParcelInfo where ";
		
	if ($pidtype == "tax") { $sql .= "id_pid = ?"; }
	elseif ($pidtype == "common") { $sql .= "id_common_pid = ?"; }
	else { trigger_error("Caught Exception: pidtype must be either tax or common", E_USER_ERROR); }
	$sql = sanitizeSQL($sql);
	$camaconn = camaConnection();

    /*** fetch into an PDOStatement object ***/
    $recordSet = $camaconn->prepare($sql);
    $recordSet->bindParam(1, $pid);
    $recordSet->execute();

	if ($format == 'xml') {
		require_once("../../inc/xml.pdo.inc.php");
		header("Content-Type: text/xml");
		echo rs2xml($recordSet);
	}
	elseif ($format == 'json') {
		require_once("../../inc/json.pdo.inc.php");
		header("Content-Type: application/json");
		echo rs2json($recordSet);
	}
	else {
		trigger_error("Caught Exception: format must be xml or json.", E_USER_ERROR);
	}
}
catch (Exception $e) {
	trigger_error("Caught Exception: " . $e->getMessage(), E_USER_ERROR);
}

?>