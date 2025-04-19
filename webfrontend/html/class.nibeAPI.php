<?php
class NibeAPI {
	var $clientID;
	var $clientSecret;
	var $redirectURL;
	var $scopes;
	var $debugActive;
	var $apiBaseUrl = "https://api.myuplink.com/v2";

	function __construct($clientID, $clientSecret, $redirectURL, $scopes = "READSYSTEM WRITESYSTEM offline_access", $debugActive = 0) {
		$this->clientID = $clientID;
		$this->clientSecret = $clientSecret;
		$this->redirectURL = $redirectURL;
		$this->scopes = $scopes;
		$this->debugActive = $debugActive;
	}

	function authorizationURL()
	{
		return "https://api.myuplink.com/oauth/authorize?response_type=code&client_id=" . $this->clientID . "&scope=" . urlencode($this->scopes) . "&redirect_uri=" . urlencode($this->redirectURL) . "&state=authorization";
	}

	function authorize($CODE, $isRefresh = false)
	{
		$ch = curl_init();
		if ($isRefresh)
		{
			$postFields = array(
				'grant_type' => 'refresh_token',
				'client_id' => $this->clientID,
				'client_secret' => $this->clientSecret,
				'refresh_token' => $CODE
			);
		}
		else
		{
			$postFields = array(
				'grant_type' => 'authorization_code',
				'client_id' => $this->clientID,
				'client_secret' => $this->clientSecret,
				'code' => $CODE,
				'redirect_uri' => $this->redirectURL
			);
		}

		curl_setopt($ch, CURLOPT_URL, "https://api.myuplink.com/oauth/token");
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
		curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/x-www-form-urlencoded'));
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

		$response = curl_exec($ch);
		$token = false;

		if ($response === false)
		{
			echo 'Curl Error: ' . curl_error($ch);
		}
		else
		{
			switch ($http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE))
			{
				case 200:
					$jsonResponse = json_decode($response);
					if ($jsonResponse != NULL)
					{
						$token = $jsonResponse;
						// Store token expiration time
						$token->expires_at = time() + $token->expires_in;
					} else {
						echo 'Could not decode json response: ' . $response . '\n';
					}
				break;

				default:
					echo 'Unexpected HTTP Code: ' . $http_code . "\n";
					echo 'Response: ' . $response . "\n";
			}
		}
		curl_close ($ch);
		return $token;
	}

	function readAPI($URI, $token, &$success = 'undefined')
	{
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $this->apiBaseUrl . "/" . $URI);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array(
			"Authorization: Bearer " . $token->access_token,
			"Accept: application/json",
			"Content-Type: application/json"
		));
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		$response = curl_exec($ch);
		$data = false;
		$success = false;

		if ($response === false)
		{
			echo 'Curl Error: ' . curl_error($ch);
		}
		else
		{
			switch ($http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE))
			{
				case 200:
					$data = json_decode($response);
					if ($data !== false) {
						$success = true;
						// Check if this is a smart-home-mode request and format=loxone
						if (strpos($URI, 'smart-home-mode') !== false && isset($_GET['format']) && $_GET['format'] === 'loxone') {
							$mode = $data->smartHomeMode;
							$numericValue = 9; // default value
							switch($mode) {
								case "Normal":
									$numericValue = 0;
									break;
								case "Away":
									$numericValue = 1;
									break;
								case "Vacation":
									$numericValue = 2;
									break;
							}
							echo $numericValue;
							exit;
						}
					}
				break;

				case 401:
					// Token might be expired, try to refresh
					if ($this->debugActive) echo "Token expired, attempting refresh...<br />\n";
					$newToken = $this->authorize($token->refresh_token, true);
					if ($newToken) {
						$this->save_token($newToken);
						return $this->readAPI($URI, $newToken, $success);
					}
					// Fall through to default case if refresh fails
				default:
					$data = "Unexpected HTTP Code: " . $http_code . "<br />\nResponse:<br />\n" . $response;
			}
		}
		curl_close ($ch);
		return $data;
	}

	function postAPI($URI, $postBody, $token, &$success = 'undefined')
	{
		return $this->postPutAPI($URI, "POST", $postBody, $token, $success);
	}

	function putAPI($URI, $postBody, $token, &$success = 'undefined')
	{
		$curl = curl_init();

		curl_setopt_array($curl, array(
			CURLOPT_URL => $this->apiBaseUrl . "/" . $URI,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CUSTOMREQUEST => "PUT",
			CURLOPT_POSTFIELDS => $postBody,
			CURLOPT_HTTPHEADER => array(
				"Authorization: Bearer " . $token->access_token,
				"Content-Type: application/json-patch+json",
				"Accept: */*"
			),
		));

		$response = curl_exec($curl);
		$data = false;
		$success = false;

		if ($response === false)
		{
			echo 'Curl Error: ' . curl_error($curl);
		}
		else
		{
			switch ($http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE))
			{
				case 200:
				case 204:
					$success = true;
					$data = json_decode($response);
					if ($data === null) {
						$data = (object)["status" => $http_code];
					}
				break;

				case 401:
					// Token might be expired, try to refresh
					if ($this->debugActive) echo "Token expired, attempting refresh...<br />\n";
					$newToken = $this->authorize($token->refresh_token, true);
					if ($newToken) {
						$this->save_token($newToken);
						return $this->putAPI($URI, $postBody, $newToken, $success);
					}
					// Fall through to default case if refresh fails
				default:
					$data = "Unexpected HTTP Code: " . $http_code . "<br />\nResponse:<br />\n" . $response;
			}
		}
		curl_close ($curl);
		return $data;
	}

	function postPutAPI($URI, $method, $postBody, $token, &$success = 'undefined')
	{
		$curl = curl_init();

		curl_setopt_array($curl, array(
			CURLOPT_URL => $this->apiBaseUrl . "/" . $URI,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CUSTOMREQUEST => $method,
			CURLOPT_POSTFIELDS => $postBody,
			CURLOPT_HTTPHEADER => array(
				"Authorization: Bearer " . $token->access_token,
				"Content-Type: application/json",
				"Accept: application/json"
			),
		));

		$response = curl_exec($curl);
		$data = false;
		$success = false;

		if ($response === false)
		{
			echo 'Curl Error: ' . curl_error($curl);
		}
		else
		{
			switch ($http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE))
			{
				case 200:
					$data = json_decode($response);
					if ($data !== false) $success = true;
				break;

				case 204:
					$success = true;
					$data = "Success";
				break;

				case 401:
					// Token might be expired, try to refresh
					if ($this->debugActive) echo "Token expired, attempting refresh...<br />\n";
					$newToken = $this->authorize($token->refresh_token, true);
					if ($newToken) {
						$this->save_token($newToken);
						return $this->postPutAPI($URI, $method, $postBody, $newToken, $success);
					}
					// Fall through to default case if refresh fails
				default:
					$data = "Unexpected HTTP Code: " . $http_code . "<br />\nResponse:<br />\n" . $response;
			}
		}
		curl_close ($curl);
		return $data;
	}

	function save_token($token)
	{
		if (!file_put_contents("token", serialize($token)))
		{
			echo "Could not save token.";
			die();
		}
		return true;
	}

	function load_token()
	{
		$token = @file_get_contents("token");
		if ($token === false)
		{
			return false;
		}
		return @unserialize($token);
	}

	function clear_token()
	{
		if (!file_put_contents("token", (string)"\n"))
		{
			echo "Could not clear token.";
			die();
		}
		return true;
	}

	function last_token_update()
	{
		return filemtime("token");
	}

	function token_needs_update($token)
	{
		// Check if token is about to expire (within 5 minutes)
		return (time() + 300 >= $token->expires_at);
	}

	function checkToken()
	{
		$token = $this->load_token();
		if ($token === false)
		{
			return false;
		}

		if ($this->token_needs_update($token))
		{
			if ($this->debugActive) echo "Token needs update. Working on it...<br />\n";
			$token = $this->authorize($token->refresh_token, true);
			if ($token === false)
			{
				$this->clear_token();
				if ($this->debugActive) echo "Failed to refresh token.<br />\n";
				return false;
			}
			$this->save_token($token);
			if ($this->debugActive) echo "Successfully refreshed token.<br />\n";
		}
		return $token;
	}

	// New helper methods for common API endpoints
	function getSystems($token)
	{
		return $this->readAPI("systems/me", $token);
	}

	function getSystemDetails($token, $systemId)
	{
		return $this->readAPI("systems/" . $systemId, $token);
	}

	function getSystemParameters($token, $systemId)
	{
		return $this->readAPI("systems/" . $systemId . "/parameters", $token);
	}

	function getSystemSmartHomeMode($token, $systemId)
	{
		return $this->readAPI("systems/" . $systemId . "/smart-home-mode", $token);
	}

	function setSystemSmartHomeMode($token, $systemId, $mode)
	{
		$postBody = json_encode(array("smartHomeMode" => $mode));
		$result = $this->putAPI("systems/" . $systemId . "/smart-home-mode", $postBody, $token);
		
		// If successful, return a simple success response
		if (isset($result->status) && ($result->status == 200 || $result->status == 204)) {
			echo "OK";
			exit;
		}
		
		// If there was an error, return the error
		echo "ERROR";
		exit;
	}

	function getDevices($token, $systemId)
	{
		return $this->readAPI("systems/" . $systemId . "/devices", $token);
	}

	function getDevicePoints($token, $deviceId)
	{
		return $this->readAPI("devices/" . $deviceId . "/points", $token);
	}
}
?>
