<?php
class NibeGateway {
	var $nibeAPI;
	function __construct($nibeAPI)
	{
		$this->nibeAPI = $nibeAPI;
	}

	function formatForLoxone($data) {
		$output = "";
		if (is_array($data)) {
			foreach ($data as $point) {
				if (isset($point->parameterId) && isset($point->value)) {
					$output .= '"parameterId": ' . $point->parameterId . ', "value": ' . $point->value . "\n";
				}
			}
		}
		return $output;
	}

	function main()
	{
		// handle API callback if needed
		if (isset($_GET["state"]) && $_GET["state"] == "authorization")
		{
			if (!isset($_GET["code"]))
			{
				echo "Missing code!";
				die();
			}
			$CODE = $_GET["code"];
			$token = $this->nibeAPI->authorize($CODE);
			header("refresh:5;url=" . $_SERVER['PHP_SELF']);
			if ($token === false)
			{
				$this->nibeAPI->clear_token();
				echo "Failed to authorize! Redirecting to <a href=\"" . $_SERVER['PHP_SELF']  . "\">status page</a> ...";
			}
			else
			{
				$this->nibeAPI->save_token($token);
				echo "Successfully authorized! Redirecting to <a href=\"" . $_SERVER['PHP_SELF']  . "\">status page</a> ...";
			}
			die();
		}

		else if (isset($_GET["status"]))
		{
			echo ($this->nibeAPI->checkToken() === false) ? "0" : "1";
		}
		else if (isset($_GET["mode"]))
		{
			// always check token first
			$token = $this->nibeAPI->checkToken();
			if ($token === false)
			{
				header("HTTP/1.0 401 Unauthorized");
				$URL = $_SERVER['PHP_SELF'];
				echo "Not authorized yet. Please setup the required token by opening the following URL in your browser from without your LAN:<br />\n";
				echo "<a href=\"$URL\">" . (isset($_SERVER['HTTPS']) ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'] . explode("?",$_SERVER['REQUEST_URI'])[0] . "</a>";
				die();
			}

			// defaults
			$success = false;
			$response = "Invalid mode request";
			if ($_GET["mode"] == "raw")
			{
				if (isset($_GET["exec"]))
				{
					$functionURI=$_GET["exec"];
					$response = $this->nibeAPI->readAPI($functionURI, $token, $success);
				}

				elseif (isset($_GET["get"]))
				{
					$functionURI=$_GET["get"];
					$response = $this->nibeAPI->readAPI($functionURI, $token, $success);
				}

				elseif (isset($_GET["put"]) && isset($_GET["data"]))
				{
					$functionURI=$_GET["put"];
					$postBody=$_GET["data"];
					$response = $this->nibeAPI->putAPI($functionURI, $postBody, $token, $success);
					if ($success)
					{
						header("HTTP/1.0 204 No Content");
					}
				}
				elseif (isset($_GET["post"]) && isset($_GET["data"]))
				{
					$functionURI=$_GET["post"];
					$postBody=$_GET["data"];
					$response = $this->nibeAPI->postAPI($functionURI, $postBody, $token, $success);
					if ($success)
					{
						header("HTTP/1.0 204 No Content");
					}
				}
				elseif (isset($_GET["patch"]) && isset($_GET["data"]))
				{
					$functionURI=$_GET["patch"];
					$patchBody=$_GET["data"];
					$response = $this->nibeAPI->patchAPI($functionURI, $patchBody, $token, $success);
					if ($success)
					{
						header("HTTP/1.0 204 No Content");
					}
				}
			}
			elseif ($_GET["mode"] == "set")
			{
				if (!isset($_GET["systemId"]))
				{
					header("HTTP/1.0 400 Bad Request");
					echo "Missing parameter systemId";
					die();
				}
				$systemId = $_GET["systemId"];

				if (isset($_GET["smartHomeMode"])) // DEFAULT_OPERATION , AWAY_FROM_HOME , VACATION
				{
					$response = $this->nibeAPI->setSystemSmartHomeMode($token, $systemId, $_GET["smartHomeMode"], $success);
					if ($success)
					{
						header("HTTP/1.0 204 No Content");
					}
				}

				elseif (isset($_GET["hotWaterBoost"]))
				{
					$postBody = json_encode(array(
						"settings" => array(
							"hot_water_boost" => $_GET["hotWaterBoost"]
						)
					));
					$response = $this->nibeAPI->putAPI("systems/" . $systemId . "/parameters", $postBody, $token, $success);
				}
			}

			if (!$success)
			{
				header("HTTP/1.0 400 Bad Request");
				print_r($response);
				die(1);
			}

			// Format the response based on the format parameter
			if (isset($_GET["format"])) {
				switch ($_GET["format"]) {
					case "pretty":
						$output = "<pre>" . json_encode($response, JSON_PRETTY_PRINT) . "</pre>";
						break;
					case "loxone":
						$output = $this->formatForLoxone($response);
						break;
					case "json":
					default:
						$output = json_encode($response);
						break;
				}
			} else {
				$output = json_encode($response);
			}

			echo $output;
			die();
		}
		// handle default access
		else
		{
			$this->displayStatusPage();
		}
	}

	function displayStatusPage()
	{
		$token = $this->nibeAPI->checkToken();
		if ($token === false)
		{
			$URL = $this->nibeAPI->authorizationURL();

			echo "You're not authorized yet.<br /><br />\n";
			echo "<b>Important:</b> If you haven't done that yet, create an application on <a href=\"https://api.myuplink.com\">https://api.myuplink.com</a> first and update the <a href=\"/admin/plugins/nibeuplink/config.cgi	\">config section</a>.<br ><br />\n";
			echo "If you think you're ready to connect this bridge to the MyUplink API, click <a href=\"$URL\">here</a>.";
			die();
		}

		if (isset($_GET["autoUpdate"]) && $_GET["autoUpdate"] == "true")
		{
			header("refresh:5;url=" . $_SERVER['PHP_SELF'] . "?autoUpdate=true");
			echo "<center><a href=\"" . $_SERVER['PHP_SELF'] . "?autoUpdate=false\">Disable auto refresh</a></center><br /><br />\n";
		}
		else
		{
			echo "<center><a href=\"" . $_SERVER['PHP_SELF'] . "?autoUpdate=true\">Enable auto refresh</a></center><br /><br />\n";
		}

		echo "<h2>Status</h2>";
		echo "Current status: authorized<br /><br />\n";
		echo "Access-Token:<br />" . $token->access_token . "<br /><br />\n";
		echo "Current Time: " . time() . "<br />\n";
		echo "Last update: " . $this->nibeAPI->last_token_update() . "<br />\n";
		echo "Token expire time: " . $token->expires_in . "<br />\n";
		echo "Remaining seconds: " . ($token->expires_in - (time() - $this->nibeAPI->last_token_update()) . "<br /><br />\n");

		echo "<h2>System Information</h2>";
		$success = false;
		$systemsResponse = $this->nibeAPI->getSystems($token, $success);
		
		// Check if we got a valid response with systems
		if ($systemsResponse && isset($systemsResponse->systems) && is_array($systemsResponse->systems) && count($systemsResponse->systems) > 0) {
			echo "<h3>Systems Overview</h3>";
			echo "<pre>" . json_encode($systemsResponse, JSON_PRETTY_PRINT) . "</pre>";
			
			// Display details for each system
			foreach ($systemsResponse->systems as $system) {
				echo "<h3>System Details: " . htmlspecialchars($system->systemId) . " (" . htmlspecialchars($system->name) . ")</h3>";
				
				// Display basic system info
				echo "<h4>Basic Information</h4>";
				echo "<ul>";
				echo "<li>Security Level: " . htmlspecialchars($system->securityLevel) . "</li>";
				echo "<li>Country: " . htmlspecialchars($system->country) . "</li>";
				echo "<li>Has Alarm: " . ($system->hasAlarm ? "Yes" : "No") . "</li>";
				echo "</ul>";
				
				// Display devices
				if (isset($system->devices) && is_array($system->devices)) {
					echo "<h4>Devices</h4>";
					foreach ($system->devices as $device) {
						echo "<div style='margin-left: 20px;'>";
						echo "<h5>Device: " . htmlspecialchars($device->id) . "</h5>";
						echo "<ul>";
						echo "<li>Connection State: " . htmlspecialchars($device->connectionState) . "</li>";
						echo "<li>Firmware Version: " . htmlspecialchars($device->currentFwVersion) . "</li>";
						if (isset($device->product)) {
							echo "<li>Product: " . htmlspecialchars($device->product->name) . "</li>";
							echo "<li>Serial Number: " . htmlspecialchars($device->product->serialNumber) . "</li>";
						}
						echo "</ul>";
						echo "</div>";
					}
				}
				
				// Get system details
				$success = false;
				$systemDetails = $this->nibeAPI->getSystemDetails($token, $system->systemId, $success);
				if ($success) {
					echo "<h4>System Information</h4>";
					echo "<pre>" . json_encode($systemDetails, JSON_PRETTY_PRINT) . "</pre>";
				}
				
				// Get system parameters
				$success = false;
				$parameters = $this->nibeAPI->getSystemParameters($token, $system->systemId, $success);
				if ($success) {
					echo "<h4>System Parameters</h4>";
					echo "<pre>" . json_encode($parameters, JSON_PRETTY_PRINT) . "</pre>";
				}
				
				// Get smart home mode
				$success = false;
				$smartHomeMode = $this->nibeAPI->getSystemSmartHomeMode($token, $system->systemId, $success);
				if ($success) {
					echo "<h4>Smart Home Mode</h4>";
					echo "<pre>" . json_encode($smartHomeMode, JSON_PRETTY_PRINT) . "</pre>";
				}
				
				// Get devices and their points
				$success = false;
				$devices = $this->nibeAPI->getDevices($token, $system->systemId, $success);
				if ($success) {
					echo "<h4>Devices</h4>";
					echo "<pre>" . json_encode($devices, JSON_PRETTY_PRINT) . "</pre>";
					
					// Get points for each device
					foreach ($devices as $device) {
						$success = false;
						$points = $this->nibeAPI->getDevicePoints($token, $device->id, $success);
						if ($success) {
							echo "<h5>Device Points: " . htmlspecialchars($device->id) . "</h5>";
							echo "<pre>" . json_encode($points, JSON_PRETTY_PRINT) . "</pre>";
						}
					}
				}
			}
		} else {
			echo "<p>No systems found or error fetching systems.</p>";
			if ($systemsResponse) {
				echo "<pre>Debug Info: " . json_encode($systemsResponse, JSON_PRETTY_PRINT) . "</pre>";
			}
		}
		?>
		<h2>Query</h2>
		<div><form>
			<input type="hidden" name="mode" value="raw" />
			<p>
				Output format: 
				<input type="radio" name="format" value="json" checked>json&nbsp;
				<input type="radio" name="format" value="pretty">pretty print&nbsp;
				<input type="radio" name="format" value="loxone">loxone
			</p>
			<p>
				Function:
				<input type="text" name="exec" value="systems/me">&nbsp;
				<input type="submit" value="Submit">
			</p>
		</form></div>
		<div>
		<a href="https://api.myuplink.com/swagger/index.html">MyUplink API Documentation</a><br />
		</div>
		<?php
	}
}
?>
