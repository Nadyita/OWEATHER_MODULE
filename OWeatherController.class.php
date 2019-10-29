<?php

namespace Budabot\User\Modules;

use Budabot\Core\xml;

/**
 * Authors:
 *	- Nadyita (RK5)
 *
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'oweather',
 *		accessLevel = 'all',
 *		description = 'View Weather for any location',
 *		help        = 'oweather.txt'
 *	)
 */
class OWeatherController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public $moduleName;

	/** @Inject */
	public $text;

	/** @Inject */
	public $settingManager;

	/**
	 * @Setup
	 */
	public function setup() {
		$this->settingManager->add($this->moduleName, "oweather_api_key", "The OpenWeatherMap API key", "edit", "text", "None", "None", '', "mod");
	}

	/**
	 * Try to convert a wind degree into a wind direction
	 */
	public function degreeToDirection($degree) {
		$mapping = array(
			  0 => "N",
			 22 => "NNE",
			 45 => "NE",
			 67 => "ENE",
			 90 => "E",
			112 => "ESE",
			135 => "SE",
			157 => "SSE",
			180 => "S",
			202 => "SSW",
			225 => "SW",
			247 => "WSW",
			270 => "W",
			292 => "WNW",
			315 => "NW",
			337 => "NNW",
			360 => "N",
		);
		$current = "unknown";
		$currentDiff = 360;
		foreach ($mapping as $mapDeg => $mapDir) {
			if (abs($degree-$mapDeg) < $currentDiff) {
				$current = $mapDir;
				$currentDiff = abs($degree-$mapDeg);
			}
		}
		return $current;
	}

	/**
	 * Convert the windspeed in m/s into the wind's strength according to beaufort
	 */
	public function getWindStrength($speed) {
		$beaufortScale = array(
			32.7 => 'hurricane',
			28.5 => 'violent storm',
			24.5 => 'storm',
			20.8 => 'strong gale',
			17.2 => 'gale',
			13.9 => 'high wind',
			10.8 => 'strong breeze',
			 8.0 => 'fresh breeze',
			 5.5 => 'moderate breeze',
			 3.4 => 'gentle breeze',
			 1.6 => 'light breeze',
			 0.5 => 'light air',
			 0.0 => 'calm',
		);
		foreach ($beaufortScale as $windSpeed => $windStrength) {
			if ($speed >= $windSpeed) {
				return $windStrength;
			}
		}
		return 'unknown';
	}

	/**
	 * Return a link to OpenStreetMap at the given coordinates
	 */
	public function getOSMLink($coords) {
		$zoom = 12; // Zoom is 1 to 20 (full in)
		$lat = number_format($coords["lat"], 4);
		$lon = number_format($coords["lon"], 4);

		return "https://www.openstreetmap.org/#map=$zoom/$lat/$lon";
	}

	/**
	 * Convert the result hash of the API into a blob string
	 */
	public function weatherToString($data) {
		$latString     = $data["coord"]["lat"] > 0 ? "N".$data["coord"]["lat"] : "S".(-1 * $data["coord"]["lat"]);
		$lonString     = $data["coord"]["lon"] > 0 ? "E".$data["coord"]["lon"] : "W".(-1 * $data["coord"]["lon"]);
		$mapCommand    = $this->text->makeChatcmd("OpenStreetMap", "/start ".$this->getOSMLink($data["coord"]));
		$luString      = date("D, Y-m-d H:i:s", $data["dt"])." UTC";
		$locName       = $data["name"];
		$locCC         = $data["sys"]["country"];
		$tempC         = number_format($data["main"]["temp"], 1);
		$tempF         = number_format($data["main"]["temp"] * 1.8 + 32, 1);
		$weatherString = $data["weather"][0]["description"];
		$clouds        = $data["clouds"]["all"];
		$humidity      = $data["main"]["humidity"];
		$pressureHPA   = $data["main"]["pressure"];
		$pressureHG    = number_format($data["main"]["pressure"] * 0.02952997, 2);
		$windStrength  = $this->getWindStrength($data["wind"]["speed"]);
		$windSpeedKMH  = number_format($data["wind"]["speed"] * 3600 / 1000.0, 1);
		$windSpeedMPH  = number_format($data["wind"]["speed"] * 3600 / 1609.3, 1);
		$windDirection = $this->degreeToDirection($data["wind"]["deg"]);
		$sunRise       = date("H:i:s", $data["sys"]["sunrise"])." UTC";
		$sunSet        = date("H:i:s", $data["sys"]["sunset"] )." UTC";
		$visibility    = "no data";
		if (array_key_exists("visibility", $data) && $data["visibility"] > 0) {
			$visibilityKM = number_format($data["visibility"]/1000, 1);
			$visibilityMiles = number_format($data["visibility"]/1609.3, 1);
		        $visibility = "$visibilityKM km ($visibilityMiles miles)";
		}

		$blob = "Last Updated: <highlight>$luString<end><br>".
		        "<br>".
		        "Location: <highlight>$locName<end>, <highlight>$locCC<end><br>".
		        "Lat/Lon: <highlight>${latString}° ${lonString}°<end> $mapCommand<br>".
		        "<br>".
		        "Currently: <highlight>${tempC}°C (${tempF}°F)<end>, <highlight>$weatherString<end><br>".
		        "Clouds: <highlight>${clouds}%<end><br>".
		        "Humidity: <highlight>${humidity}%<end><br>".
		        "Visibility: <highlight>$visibility<end><br>".
		        "Pressure: <highlight>$pressureHPA hPa (${pressureHG}\" Hg)<end><br>".
		        "Wind: <highlight>$windStrength<end> - <highlight>$windSpeedKMH km/h ($windSpeedMPH mph)<end> from the <highlight>$windDirection<end><br>".
		        "<br>".
		        "Sunrise: <highlight>$sunRise<end><br>".
		        "Sunset: <highlight>$sunSet<end>";

		return $blob;
	}

	/**
	 * Download the weather data from the API, returning
	 * either false for an unknown error, a string with the error message
	 * or a hash with the data.
	 */
	public function downloadWeather($apiKey, $location) {
		$apiUrl = "http://api.openweathermap.org/data/2.5/weather?".http_build_query(array(
			"q"     => $location,
			"appid" => $apiKey,
			"units" => "metric",
			"mode"  => "json"
		));
		$httpOptions = array(
			'http' => array(
				'ignore_errors' => true,
				'header' => "Content-Type: application/json\r\n"
			)
		);
		$httpContext  = stream_context_create($httpOptions);
		$response = file_get_contents($apiUrl, false, $httpContext);
		$data = json_decode($response, true);
		if (!is_array($data) || !array_key_exists("cod", $data)) {
			return false;
		}
		if ($data["cod"] != 200) {
			if (array_key_exists("message", $data)) {
				return $data["message"];
			}
			return false;
		}
		return $data;
	}

	/**
	 * @HandlesCommand("oweather")
	 * @Matches("/^oweather (.+)$/i")
	 */
	public function weatherCommand($message, $channel, $sender, $sendto, $args) {
		$location = $args[1];

		$apiKey = $this->settingManager->get('oweather_api_key');
		if (strlen($apiKey) != 32) {
			$sendto->reply("There is either no API key or an invalid one was set.");
			return;
		}
		$data = $this->downloadWeather($apiKey, $location);
		if (is_string($data)) {
			$sendto->reply("Error looking up the weather: <highlight>$data<end>.");
			return;
		}
		if (!is_array($data)) {
			$sendto->reply("Unknown error while looking up the weather.");
			return;
		}

		$blob = $this->weatherToString($data);

		$msg = $this->text->makeBlob("Weather for ".$data["name"].", ".$data["sys"]["country"], $blob);

		$sendto->reply($msg);
	}
}
