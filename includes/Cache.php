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

class Cache {
	private const CACHE_FILE = 'zbx_ticket_platform_cache.json';
	private const META_KEYS = ['api_version', 'connection_status', 'last_reached'];
	private const META_TTL = 60;

	public static function makeKey(array $payload): string {
		return sha1(json_encode($payload, JSON_UNESCAPED_SLASHES));
	}

	public static function get(string $server_id, string $key, int $ttl): ?array {
		$cache = self::load();

		if (!array_key_exists('servers', $cache)
				|| !array_key_exists($server_id, $cache['servers'])
				|| !array_key_exists('entries', $cache['servers'][$server_id])
				|| !array_key_exists($key, $cache['servers'][$server_id]['entries'])) {
			return null;
		}

		$entry = $cache['servers'][$server_id]['entries'][$key];
		if (!array_key_exists('ts', $entry) || (time() - $entry['ts']) > $ttl) {
			return null;
		}

		return $entry['payload'] ?? null;
	}

	public static function set(string $server_id, string $key, array $payload): void {
		$cache = self::load();
		$cache['servers'][$server_id]['entries'][$key] = [
			'ts' => time(),
			'payload' => $payload
		];

		self::save($cache);
	}

	public static function clearServer(string $server_id): void {
		$cache = self::load();
		if (array_key_exists('servers', $cache) && array_key_exists($server_id, $cache['servers'])) {
			unset($cache['servers'][$server_id]);
			if (!$cache['servers']) {
				unset($cache['servers']);
			}
			self::save($cache);
		}
	}

	public static function getServerMeta(string $server_id): array {
		$cache = self::load();
		if (!array_key_exists('server_meta', $cache) || !array_key_exists($server_id, $cache['server_meta'])) {
			return [];
		}

		$meta = $cache['server_meta'][$server_id];
		if (!is_array($meta)) {
			return [];
		}
		if (!array_key_exists('ts', $meta) || (time() - (int) $meta['ts']) > self::META_TTL) {
			return [];
		}

		return array_intersect_key($meta, array_flip(self::META_KEYS));
	}

	public static function getServerMetaAll(): array {
		$cache = self::load();
		if (!array_key_exists('server_meta', $cache) || !is_array($cache['server_meta'])) {
			return [];
		}

		$all = [];
		foreach ($cache['server_meta'] as $server_id => $meta) {
			if (!is_array($meta)) {
				continue;
			}
			if (!array_key_exists('ts', $meta) || (time() - (int) $meta['ts']) > self::META_TTL) {
				continue;
			}
			$filtered = array_intersect_key($meta, array_flip(self::META_KEYS));
			if ($filtered) {
				$all[$server_id] = $filtered;
			}
		}

		return $all;
	}

	public static function setServerMeta(string $server_id, array $updates): void {
		$updates = array_intersect_key($updates, array_flip(self::META_KEYS));
		if (!$updates) {
			return;
		}

		$cache = self::load();
		if (!array_key_exists('server_meta', $cache) || !is_array($cache['server_meta'])) {
			$cache['server_meta'] = [];
		}
		if (!array_key_exists($server_id, $cache['server_meta']) || !is_array($cache['server_meta'][$server_id])) {
			$cache['server_meta'][$server_id] = [];
		}

		foreach ($updates as $key => $value) {
			$cache['server_meta'][$server_id][$key] = $value;
		}
		$cache['server_meta'][$server_id]['ts'] = time();

		self::save($cache);
	}

	public static function clearServerMeta(string $server_id): void {
		$cache = self::load();
		if (array_key_exists('server_meta', $cache) && array_key_exists($server_id, $cache['server_meta'])) {
			unset($cache['server_meta'][$server_id]);
			if (!$cache['server_meta']) {
				unset($cache['server_meta']);
			}
			self::save($cache);
		}
	}

	private static function load(): array {
		$path = self::getPath();

		if (!is_file($path)) {
			return [];
		}

		$data = json_decode(file_get_contents($path), true);
		return is_array($data) ? $data : [];
	}

	private static function save(array $data): void {
		file_put_contents(self::getPath(), json_encode($data, JSON_UNESCAPED_SLASHES));
	}

	private static function getPath(): string {
		return sys_get_temp_dir().DIRECTORY_SEPARATOR.self::CACHE_FILE;
	}
}
