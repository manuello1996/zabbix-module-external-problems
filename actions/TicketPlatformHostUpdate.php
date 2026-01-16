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


namespace Modules\TicketPlatform\Actions;

use CController;
use CControllerResponseData;
use CWebUser;
use CItem;
use Exception;
use Modules\TicketPlatform\Includes\Config;
use Modules\TicketPlatform\Includes\RemoteApi;

require_once dirname(__FILE__).'/../../../include/hosts.inc.php';

class TicketPlatformHostUpdate extends CController {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$fields = [
			'server_id' => 'required',
			'hostid' => 'required',
			'host' => 'string',
			'visiblename' => 'string',
			'description' => 'string',
			'status' => 'int32',
			'monitored_by' => 'int32',
			'proxyid' => 'string',
			'proxy_groupid' => 'string',
			'interfaces' => 'array',
			'mainInterfaces' => 'array',
			'tags' => 'array',
			'ipmi_authtype' => 'int32',
			'ipmi_privilege' => 'int32',
			'ipmi_username' => 'string',
			'ipmi_password' => 'string',
			'tls_connect' => 'int32',
			'tls_accept' => 'int32',
			'tls_subject' => 'string',
			'tls_issuer' => 'string',
			'tls_psk_identity' => 'string',
			'tls_psk' => 'string',
			'inventory_mode' => 'int32',
			'host_inventory' => 'array',
			'macros' => 'array',
			'valuemaps' => 'array'
		];

		$ret = $this->validateInput($fields);

		if (!$ret) {
			$this->setResponse(new CControllerResponseData([
				'main_block' => json_encode([
					'error' => [
						'title' => _('Cannot update host'),
						'messages' => array_column(get_and_clear_messages(), 'message')
					]
				])
			]));
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		return CWebUser::getType() >= USER_TYPE_ZABBIX_USER;
	}

	protected function doAction(): void {
		$server = $this->getServer((string) $this->getInput('server_id', ''));
		$hostid = (string) $this->getInput('hostid', '');

		if ($server === null || $hostid === '') {
			$this->setResponse(new CControllerResponseData([
				'main_block' => json_encode([
					'error' => [
						'title' => _('Cannot update host'),
						'messages' => [_('No remote server or host specified.')]
					]
				])
			]));
			return;
		}

		$params = [
			'hostid' => $hostid
		];

		$host = (string) $this->getInput('host', '');
		if ($host !== '') {
			$params['host'] = $host;
		}

		$visiblename = (string) $this->getInput('visiblename', '');
		if ($visiblename !== '') {
			$params['name'] = $visiblename;
		}
		elseif ($host !== '') {
			$params['name'] = $host;
		}

		$this->assignIfSet($params, ['description', 'status', 'monitored_by', 'proxyid', 'proxy_groupid',
			'ipmi_authtype', 'ipmi_privilege', 'ipmi_username', 'ipmi_password', 'tls_connect', 'tls_accept',
			'tls_subject', 'tls_issuer', 'tls_psk_identity', 'tls_psk', 'inventory_mode'
		]);

		if ($this->hasInput('interfaces')) {
			$params['interfaces'] = $this->processHostInterfaces($this->getInput('interfaces', []));
		}
		else {
			$params['interfaces'] = $this->getCurrentInterfaces($server, $hostid);
		}

		if (!$this->hasInput('groups')) {
			$params['groups'] = $this->getCurrentGroups($server, $hostid);
		}

		if ($this->hasInput('tags')) {
			$params['tags'] = $this->processTags($this->getInput('tags', []));
		}

		if ($this->hasInput('macros')) {
			$params['macros'] = $this->processUserMacros($this->getInput('macros', []));
		}

		if ($this->hasInput('host_inventory')) {
			$params['inventory'] = $this->getInput('host_inventory', []);
		}

		if ($this->hasInput('valuemaps')) {
			$params['valuemaps'] = $this->getInput('valuemaps', []);
		}

		try {
			RemoteApi::call($server['api_url'], $server['api_token'], 'host.update', $params);
			$this->setResponse(new CControllerResponseData([
				'main_block' => json_encode([
					'success' => [
						'title' => _('Host updated')
					]
				])
			]));
		}
		catch (Exception $e) {
			$this->setResponse(new CControllerResponseData([
				'main_block' => json_encode([
					'error' => [
						'title' => _('Cannot update host'),
						'messages' => [$e->getMessage()]
					]
				])
			]));
		}
	}

	private function getServer(string $server_id): ?array {
		$config = Config::get();

		foreach ($config['servers'] as $server) {
			if ($server['id'] === $server_id) {
				return $server;
			}
		}

		return null;
	}

	private function assignIfSet(array &$target, array $keys): void {
		foreach ($keys as $key) {
			if ($this->hasInput($key)) {
				$target[$key] = $this->getInput($key);
			}
		}
	}

	private function processHostInterfaces(array $interfaces): array {
		foreach ($interfaces as $key => $interface) {
			if ($interface['type'] == INTERFACE_TYPE_SNMP) {
				if (!array_key_exists('details', $interface)) {
					$interface['details'] = [];
				}

				$interfaces[$key]['details']['bulk'] = array_key_exists('bulk', $interface['details'])
					? SNMP_BULK_ENABLED
					: SNMP_BULK_DISABLED;
			}

			if (!empty($interface['isNew'])) {
				unset($interfaces[$key]['interfaceid']);
			}

			unset($interfaces[$key]['isNew']);
			$interfaces[$key]['main'] = INTERFACE_SECONDARY;
		}

		$main_interfaces = $this->getInput('mainInterfaces', []);
		foreach (CItem::INTERFACE_TYPES_BY_PRIORITY as $type) {
			if (array_key_exists($type, $main_interfaces) && array_key_exists($main_interfaces[$type], $interfaces)) {
				$interfaces[$main_interfaces[$type]]['main'] = INTERFACE_PRIMARY;
			}
		}

		return $interfaces;
	}

	private function processTags(array $tags): array {
		return array_filter($tags, function (array $tag): bool {
			return ($tag['tag'] !== '' || $tag['value'] !== '');
		});
	}

	private function getCurrentGroups(array $server, string $hostid): array {
		$hosts = RemoteApi::call($server['api_url'], $server['api_token'], 'host.get', [
			'output' => ['hostid'],
			'selectHostGroups' => ['groupid'],
			'hostids' => [$hostid]
		]);

		if (!$hosts || !array_key_exists('hostgroups', $hosts[0])) {
			return [];
		}

		return array_map(function (array $group): array {
			return ['groupid' => $group['groupid']];
		}, $hosts[0]['hostgroups']);
	}

	private function getCurrentInterfaces(array $server, string $hostid): array {
		return RemoteApi::call($server['api_url'], $server['api_token'], 'hostinterface.get', [
			'output' => ['interfaceid', 'type', 'main', 'details', 'ip', 'dns', 'port', 'useip'],
			'hostids' => [$hostid]
		]);
	}

	private function processUserMacros(array $macros): array {
		$macros = cleanInheritedMacros($macros);

		$macros = array_map(function (array $macro): array {
			unset($macro['discovery_state'], $macro['original_value'], $macro['original_description'],
				$macro['original_macro_type'], $macro['allow_revert']
			);

			return $macro;
		}, $macros);

		return array_values(array_filter($macros, function (array $macro): bool {
			return (bool) array_filter(
				array_intersect_key($macro, array_flip(['hostmacroid', 'macro', 'value', 'description']))
			);
		}));
	}
}
