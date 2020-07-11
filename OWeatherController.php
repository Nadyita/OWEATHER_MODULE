<?php

namespace Budabot\User\Modules\OWEATHER_MODULE;

/**
 * @author Nadyita (RK5) <nadyita@hodorraid.org>
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
 *	@DefineCommand(
 *		command     = 'forecast',
 *		accessLevel = 'all',
 *		description = 'View Weather forecast for any location',
 *		help        = 'oweather.txt'
 *	)
 */
class OWeatherController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public $moduleName;

	/**
	 * @Inject
	 * @var \Budabot\Core\Text $text
	 */
	public $text;

	/**
	 * @Inject
	 * @var \Budabot\Core\SettingManager $settingManager
	 */
	public $settingManager;

	/**
	 * @Setup
	 */
	public function setup() {
		$this->settingManager->add(
			$this->moduleName,
			"oweather_api_key",
			"The OpenWeatherMap API key",
			"edit",
			"text",
			"None",
			"None",
			"",
			"mod"
		);
	}

	/**
	 * Try to convert a wind degree into a wind direction
	 */
	public function degreeToDirection($degree) {
		$mapping = [
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
		];
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
		$beaufortScale = [
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
		];
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

	public function formatCelsius($degrees) {
		$temp = number_format(abs($degrees), 1);
		if (strlen($temp) === 3) {
			if ($degrees > 0) {
				$temp = "<black>-_<end>$temp";
			} else {
				$temp = "<black>_<end>-$temp";
			}
		} elseif ($degrees > 0) {
			$temp = "<black>-<end>$temp";
		} else {
			$temp = "-$temp";
		}
		return $temp;
	}

	public function getCountryName($cc) {
		if (!function_exists("locale_get_display_region")) {
			return $cc;
		}
		return locale_get_display_region("-$cc", "en");
	}

	public function forecastToString($data) {
		$latString     = $data["city"]["coord"]["lat"] > 0 ? "N".$data["city"]["coord"]["lat"] : "S".(-1 * $data["city"]["coord"]["lat"]);
		$lonString     = $data["city"]["coord"]["lon"] > 0 ? "E".$data["city"]["coord"]["lon"] : "W".(-1 * $data["city"]["coord"]["lon"]);
		$mapCommand    = $this->text->makeChatcmd("OpenStreetMap", "/start ".$this->getOSMLink($data["city"]["coord"]));
		$locName       = $data["city"]["name"];
		$locCC         = $this->getCountryName($data["city"]["country"]);
		$population    = number_format($data["city"]["population"], 0);
		$timezone      = $this->tzSecsToHours($data["city"]["timezone"]);
		$currentTime   = date("l, H:i:s", time() + $data["city"]["timezone"]);

		$blob = "Location: <highlight>$locName<end>, <highlight>$locCC<end><br>" .
			"Timezone: <highlight>UTC ${timezone}<end><br>" .
			"Lat/Lon: <highlight>${latString}° ${lonString}°<end> $mapCommand<br>" .
			"Population: <highlight>${population}<end><br>" .
			"Local time: <highlight>${currentTime}<end><br>" .
			"<br>".
			"All times are UTC ${timezone}.<br>";

		$weatherByDay = [];
		foreach ($data['list'] as $forecast) {
			$day = date("l", $forecast["dt"]+$data["city"]["timezone"]);
			if (!array_key_exists($day, $weatherByDay)) {
				$weatherByDay[$day] = [];
			}
			$weatherByDay[$day][] = $forecast;
		}
		// Remove the last day from the list if we don't have a full forecast
		if (count($weatherByDay[$day]) < 8) {
			unset($weatherByDay[$day]);
		}
		foreach ($weatherByDay as $day => $forecastlist) {
			$blob .= "<br><header2>$day<end><br>";
			foreach ($forecastlist as $forecast) {
				$when          = date("H:i", $forecast["dt"]+$data["city"]["timezone"]);
				$tempC         = $this->formatCelsius($forecast["main"]["temp"]);
				$tempFeelsC    = $this->formatCelsius($forecast["main"]["feels_like"]);
//				$tempF         = number_format($forecast["main"]["temp"] * 1.8 + 32, 1);
//				$tempFeelsF    = number_format($forecast["main"]["feels_like"] * 1.8 + 32, 1);
				$weatherString = $forecast["weather"][0]["description"];
				$blob .= "<tab>$when: <highlight>${tempC}°C<end>, ".
					"feels like <highlight>${tempFeelsC}°C<end>".
//					" (<highlight>${tempF}°F<end>, ".
//					"feels like <highlight>${tempFeelsF}°F<end>)".
//					", <highlight>$weatherString<end>";
					"";
				if (array_key_exists("clouds", $forecast)) {
					$clouds = $forecast["clouds"]["all"];
					if ($clouds < 10) {
						$clouds = "<black>00<end>$clouds";
					} elseif ($clouds < 100) {
						$clouds = "<black>0<end>$clouds";
					}
					$blob .= ", <highlight>${clouds}%<end> clouds";
				}
				if (array_key_exists("rain", $forecast)) {
					$rain = number_format($forecast["rain"]["3h"], 1);
					if (strlen($rain) < 4) {
						$rain = "<black>0<end>$rain";
					}
					$blob .= ", <highlight>${rain}mm<end> rain";
				}
				$blob .= "<br>";
			}
		}
		return $blob;
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
		$locCC         = $this->getCountryName($data["sys"]["country"]);
		$tempC         = number_format($data["main"]["temp"], 1);
		$tempFeelsC    = number_format($data["main"]["feels_like"], 1);
		$tempF         = number_format($data["main"]["temp"] * 1.8 + 32, 1);
		$tempFeelsF    = number_format($data["main"]["feels_like"] * 1.8 + 32, 1);
		$weatherString = $data["weather"][0]["description"];
		$clouds        = $data["clouds"]["all"];
		$humidity      = $data["main"]["humidity"];
		$pressureHPA   = $data["main"]["pressure"];
		$pressureHG    = number_format($data["main"]["pressure"] * 0.02952997, 2);
		$windStrength  = $this->getWindStrength($data["wind"]["speed"]);
		$windSpeedKMH  = number_format($data["wind"]["speed"] * 3600 / 1000.0, 1);
		$windSpeedMPH  = number_format($data["wind"]["speed"] * 3600 / 1609.3, 1);
		$windDirection = $this->degreeToDirection($data["wind"]["deg"]);
		$timezone      = $this->tzSecsToHours($data["timezone"]);
		$sunRise       = date("H:i:s", $data["sys"]["sunrise"] + $data["timezone"]) . " UTC $timezone";
		$sunSet        = date("H:i:s", $data["sys"]["sunset"] + $data["timezone"]) . " UTC $timezone";
		$visibility    = "no data";
		if (array_key_exists("visibility", $data) && $data["visibility"] > 0) {
			$visibilityKM = number_format($data["visibility"]/1000, 1);
			$visibilityMiles = number_format($data["visibility"]/1609.3, 1);
		}

		$blob = "Last Updated: <highlight>$luString<end><br>" .
			"<br>" .
			"Location: <highlight>$locName<end>, <highlight>$locCC<end><br>" .
			"Timezone: <highlight>UTC $timezone<end><br>" .
			"Lat/Lon: <highlight>${latString}° ${lonString}°<end> $mapCommand<br>" .
			"<br>" .
			"Currently: <highlight>${tempC}°C<end>".
				" (<highlight>${tempF}°F<end>)".
				", <highlight>$weatherString<end><br>".
			"Feels like: <highlight>${tempFeelsC}°C<end>".
				" (<highlight>${tempFeelsF}°F<end>)<br>".
			"Clouds: <highlight>${clouds}%<end><br>" .
			"Humidity: <highlight>${humidity}%<end><br>" .
			"Visibility: <highlight>${visibilityKM} km<end> (<highlight>${visibilityMiles} miles<end>)<br>" .
			"Pressure: <highlight>$pressureHPA hPa <end>(<highlight>${pressureHG}\" Hg<end>)<br>" .
			"Wind: <highlight>$windStrength<end> - <highlight>$windSpeedKMH km/h ($windSpeedMPH mph)<end> from the <highlight>$windDirection<end><br>" .
			"<br>" .
			"Sunrise: <highlight>$sunRise<end><br>" .
			"Sunset: <highlight>$sunSet<end>".
			"<br><br>".
			$this->text->makeChatcmd("Forecast for the next 3 days", "/tell <myname> forecast ${locName},${locCC}");

		return $blob;
	}

	public function tzSecsToHours($secs) {
		$prefix = "+";
		if ($secs < 0) {
			$prefix = "-";
		}
		return $prefix . date("H:i", abs($secs));
	}

	/**
	 * Download the weather data from the API, returning
	 * either false for an unknown error, a string with the error message
	 * or a hash with the data.
	 */
	public function downloadWeather($apiKey, $location, $endpoint="weather", $extraArgs=[]) {
		$apiUrl = "http://api.openweathermap.org/data/2.5/${endpoint}?".
			http_build_query(
				array_merge(
					[
						"q"     => $location,
						"appid" => $apiKey,
						"units" => "metric",
						"mode"  => "json"
					],
					$extraArgs
				)
			);
		$httpOptions = [
			'http' => [
				'ignore_errors' => true,
				'header' => "Content-Type: application/json\r\n"
			]
		];
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
	 * Download the weather forecast data from the API, returning
	 * either false for an unknown error, a string with the error message
	 * or a hash with the data.
	 */
	public function downloadWeatherForecast($apiKey, $location) {
		return $this->downloadWeather($apiKey, $location, "forecast", ["cnt" => 24]);
	}

	/**
	 * @HandlesCommand("forecast")
	 * @Matches("/^forecast (.+)$/i")
	 */
	public function forecastCommand($message, $channel, $sender, $sendto, $args) {
		$location = $args[1];

		$apiKey = $this->settingManager->get('oweather_api_key');
		if (strlen($apiKey) != 32) {
			$sendto->reply("There is either no API key or an invalid one was set.");
			return;
		}
		$data = $this->downloadWeatherForecast($apiKey, $location);
		if (is_string($data)) {
			$sendto->reply("Error looking up the weather: <highlight>$data<end>.");
			return;
		}
		if (!is_array($data)) {
			$sendto->reply("Unknown error while looking up the weather.");
			return;
		}

		$blob = $this->forecastToString($data);

		$locCC = $this->getCountryName($data["city"]["country"]);
		$msg = $this->text->makeBlob("Weather forecast for ".$data["city"]["name"].", $locCC", $blob);

		$sendto->reply($msg);
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
		$tempC = number_format($data["main"]["temp"], 1);
		$weatherString = $data["weather"][0]["description"];
		$cc = $this->getCountryName($data["sys"]["country"]);

		$blob = $this->weatherToString($data);

		$msg = "The weather for <highlight>" . $data["name"] . "<end>, ${cc} is ".
			"<highlight>${tempC}°C<end> with $weatherString [" . $this->text->makeBlob("Details", $blob) . "]";

		$sendto->reply($msg);
	}
}
