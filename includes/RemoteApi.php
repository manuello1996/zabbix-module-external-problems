<?php declare(strict_types = 0);
/*
** Zabbix
** Copyright (C) 2001-2025 Zabbix SIA
**
** This program is free software; you can redistribute it and/or modify
** it under the terms of the GNU General Public License as published by
** the Free Software Foundation; either version 2 of the License, or
** (at your option) any later version.
**
** This program is distributed in the hope that it will be useful,
** but WITHOUT ANY WARRANTY; without even the implied warranty of
** MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
** GNU General Public License for more details.
**
** You should have received a copy of the GNU General Public License
** along with this program; if not, write to the Free Software
** Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
**/


namespace Modules\TicketPlatform\Includes;

use Exception;

class RemoteApi {

	public static function call(string $url, string $token, string $method, array $params) {
		try {
			return self::request($url, $token, $method, $params, true);
		}
		catch (Exception $e) {
			if ($token !== '' && stripos($e->getMessage(), 'not authorized') !== false) {
				return self::request($url, $token, $method, $params, false);
			}

			throw $e;
		}
	}

	public static function callNoAuth(string $url, string $method, array $params) {
		return self::request($url, '', $method, $params, true);
	}

	private static function request(string $url, string $token, string $method, array $params, bool $use_bearer) {
		$payload = [
			'jsonrpc' => '2.0',
			'method' => $method,
			'params' => $params,
			'id' => 1
		];

		$headers = [
			'Content-Type: application/json-rpc'
		];

		if ($token !== '') {
			if ($use_bearer) {
				$headers[] = 'Authorization: Bearer '.$token;
			}
			else {
				$payload['auth'] = $token;
			}
		}

		if (function_exists('curl_init')) {
			$handle = curl_init($url);
			curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($handle, CURLOPT_POST, true);
			curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);
			curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_SLASHES));
			curl_setopt($handle, CURLOPT_TIMEOUT, 15);

			$response = curl_exec($handle);

			if ($response === false) {
				$error = curl_error($handle);
				curl_close($handle);
				throw new Exception($error);
			}

			curl_close($handle);
		}
		else {
			$context = stream_context_create([
				'http' => [
					'method' => 'POST',
					'header' => implode("\r\n", $headers),
					'content' => json_encode($payload, JSON_UNESCAPED_SLASHES),
					'timeout' => 15
				]
			]);

			$response = file_get_contents($url, false, $context);
			if ($response === false) {
				throw new Exception('Remote API request failed.');
			}
		}

		$data = json_decode($response, true);

		if (!is_array($data)) {
			throw new Exception('Invalid JSON-RPC response.');
		}

		if (array_key_exists('error', $data)) {
			$message = $data['error']['data'] ?? $data['error']['message'] ?? 'Unknown API error.';
			throw new Exception($message);
		}

		return $data['result'] ?? [];
	}

	public static function getWebUrl(string $api_url): string {
		return rtrim(preg_replace('/api_jsonrpc\.php$/', '', $api_url), '/').'/';
	}
}
