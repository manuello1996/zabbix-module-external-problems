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

use CArrayHelper;
use CController;
use CControllerResponseData;
use CSettingsHelper;
use CWebUser;
use DB;
use Exception;
use Modules\TicketPlatform\Includes\Config;
use Modules\TicketPlatform\Includes\RemoteApi;

require_once dirname(__FILE__).'/../../../include/forms.inc.php';

class TicketPlatformHostPopup extends CController {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$fields = [
			'server_id' => 'required',
			'hostid' => 'required'
		];

		$ret = $this->validateInput($fields);

		if (!$ret) {
			$this->setResponse(new CControllerResponseData([
				'error' => [
					'title' => _('Cannot load host details'),
					'messages' => array_column(get_and_clear_messages(), 'message')
				]
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
				'error' => [
					'title' => _('Cannot load host details'),
					'messages' => [_('No remote server or host specified.')]
				]
			]));
			return;
		}

		try {
			$host = $this->getHost($server, $hostid);
			$data = $this->buildViewData($host, $server['id']);

			$response = new CControllerResponseData($data);
			$response->setTitle(_('Host'));

			$this->setResponse($response);
		}
		catch (Exception $e) {
			$this->setResponse(new CControllerResponseData([
				'error' => [
					'title' => _('Cannot load host details'),
					'messages' => [$e->getMessage()]
				]
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

	private function getHost(array $server, string $hostid): array {
		$hosts = RemoteApi::call($server['api_url'], $server['api_token'], 'host.get', [
			'output' => ['hostid', 'host', 'name', 'monitored_by', 'proxyid', 'proxy_groupid', 'assigned_proxyid',
				'status', 'description', 'ipmi_authtype', 'ipmi_privilege', 'ipmi_username', 'ipmi_password',
				'tls_connect', 'tls_accept', 'tls_issuer', 'tls_subject', 'tls_psk_identity', 'tls_psk', 'flags',
				'inventory_mode'
			],
			'selectDiscoveryRule' => ['itemid', 'name', 'parent_hostid'],
			'selectHostGroups' => ['groupid', 'name'],
			'selectHostDiscovery' => ['parent_hostid', 'disable_source'],
			'selectInterfaces' => ['interfaceid', 'type', 'main', 'available', 'error', 'details', 'ip', 'dns',
				'port', 'useip'
			],
			'selectInventory' => array_column(getHostInventories(), 'db_field'),
			'selectMacros' => ['hostmacroid', 'macro', 'value', 'description', 'type', 'automatic'],
			'selectParentTemplates' => ['templateid', 'name', 'link_type'],
			'selectTags' => ['tag', 'value', 'automatic'],
			'selectValueMaps' => ['valuemapid', 'name', 'mappings'],
			'hostids' => [$hostid]
		]);

		if (!$hosts) {
			throw new Exception(_('No permissions to referred object or it does not exist!'));
		}

		$host = $hosts[0];
		$host['groups'] = $host['hostgroups'];
		unset($host['hostgroups']);

		if (array_key_exists('interfaces', $host) && $host['interfaces']) {
			$interface_items = RemoteApi::call($server['api_url'], $server['api_token'], 'hostinterface.get', [
				'output' => [],
				'selectItems' => API_OUTPUT_COUNT,
				'hostids' => [$host['hostid']],
				'preservekeys' => true
			]);

			foreach ($host['interfaces'] as &$interface) {
				if (array_key_exists($interface['interfaceid'], $interface_items)) {
					$interface['items'] = $interface_items[$interface['interfaceid']]['items'];
				}
			}
			unset($interface);
		}

		return $host + $this->getHostDefaultValues();
	}

	private function buildViewData(array $host, string $server_id): array {
		$tags_count = 0;
		foreach ($host['tags'] as $tag) {
			if (($tag['tag'] ?? '') !== '' || ($tag['value'] ?? '') !== '') {
				$tags_count++;
			}
		}

		$macros_count = 0;
		foreach ($host['macros'] as $macro) {
			if (($macro['macro'] ?? '') !== '') {
				$macros_count++;
			}
		}

		$valuemaps_count = count($host['valuemaps']);

		$data = [
			'form_action' => 'ticket.platform.host.update',
			'form_name' => 'ticket-platform-host-form',
			'hostid' => $host['hostid'],
			'clone' => null,
			'clone_hostid' => null,
			'host' => $host,
			'server_id' => $server_id,
			'is_psk_edit' => false,
			'show_inherited_macros' => 0,
			'warnings' => [],
			'counts' => [
				'tags' => $tags_count,
				'macros' => $macros_count,
				'valuemaps' => $valuemaps_count
			],
			'user' => [
				'debug_mode' => $this->getDebugMode(),
				'can_edit_templates' => false,
				'can_edit_proxy_groups' => true,
				'can_edit_proxies' => true
			]
		];

		$data['host'] = CArrayHelper::renameKeys($data['host'], [
			'name' => 'visiblename'
		]);

		if (!array_key_exists('inventory', $data['host']) || !is_array($data['host']['inventory'])) {
			$data['host']['inventory'] = [];
		}

		if ($data['host']['host'] === $data['host']['visiblename']) {
			$data['host']['visiblename'] = '';
		}

		if (!array_key_exists('tags', $data['host']) || !$data['host']['tags']) {
			$data['host']['tags'][] = ['tag' => '', 'value' => '', 'automatic' => ZBX_TAG_MANUAL];
		}
		else {
			foreach ($data['host']['tags'] as &$tag) {
				$tag += ['automatic' => ZBX_TAG_MANUAL];
			}
			unset($tag);

			CArrayHelper::sort($data['host']['tags'],
				[['field' => 'automatic', 'order' => ZBX_SORT_DOWN], 'tag', 'value']
			);
		}

		$data['host']['macros'] = array_values(order_macros($data['host']['macros'], 'macro'));
		if (!$data['host']['macros']) {
			$data['host']['macros'][] = [
				'type' => ZBX_MACRO_TYPE_TEXT,
				'macro' => '',
				'value' => '',
				'description' => '',
				'automatic' => ZBX_USERMACRO_MANUAL
			];
		}
		else {
			foreach ($data['host']['macros'] as &$macro) {
				$macro['automatic'] = ZBX_USERMACRO_MANUAL;
				$macro['discovery_state'] = 0x3;
				unset($macro['original'], $macro['allow_revert']);
			}
			unset($macro);
		}

		order_result($data['host']['valuemaps'], 'name');
		$data['host']['valuemaps'] = array_values($data['host']['valuemaps']);

		$data['groups_ms'] = $this->hostGroupsForMultiselect($data['host']['groups']);
		unset($data['groups']);

		CArrayHelper::sort($data['host']['parentTemplates'], ['name']);
		$data['editable_templates'] = [];

		$this->extendInventory($data['inventory_items'], $data['inventory_fields'], $data['host']['hostid'],
			$data['host']['inventory'], $server_id
		);

		$data['ms_proxy'] = [];
		$data['ms_proxy_group'] = [];
		$data['host']['assigned_proxy_name'] = '';

		if ($data['host']['monitored_by'] == ZBX_MONITORED_BY_PROXY) {
			$data['ms_proxy'] = CArrayHelper::renameObjectsKeys($this->callApi($server_id, 'proxy.get', [
				'output' => ['proxyid', 'name'],
				'proxyids' => $data['host']['proxyid']
			]), ['proxyid' => 'id']);
		}
		elseif ($data['host']['monitored_by'] == ZBX_MONITORED_BY_PROXY_GROUP) {
			$data['ms_proxy_group'] = CArrayHelper::renameObjectsKeys($this->callApi($server_id, 'proxygroup.get', [
				'output' => ['proxy_groupid', 'name'],
				'proxy_groupids' => $data['host']['proxy_groupid']
			]), ['proxy_groupid' => 'id']);

			if ($data['host']['assigned_proxyid'] != 0) {
				$db_proxies = $this->callApi($server_id, 'proxy.get', [
					'output' => ['name'],
					'proxyids' => $data['host']['assigned_proxyid']
				]);

				if ($db_proxies) {
					$data['host']['assigned_proxy_name'] = $db_proxies[0]['name'];
				}
			}
		}

		$data['is_discovery_rule_editable'] = false;

		return $data;
	}

	private function hostGroupsForMultiselect(array $groups): array {
		$groups_ms = [];

		foreach ($groups as $group) {
			if (array_key_exists('new', $group)) {
				continue;
			}

			$groups_ms[] = [
				'id' => $group['groupid'],
				'name' => $group['name']
			];
		}

		CArrayHelper::sort($groups_ms, ['name']);
		return $groups_ms;
	}

	private function extendInventory(?array &$inventory_items, ?array &$inventory_fields, string $hostid,
			array $inventory, string $server_id): void {
		$db_fields = DB::getSchema('host_inventory');
		$inventory_fields = array_map(function ($field) use ($db_fields) {
			return $field += array_intersect_key($db_fields['fields'][$field['db_field']], [
				'type' => null,
				'length' => null
			]);
		}, getHostInventories());

		$inventory_items = $hostid
			? $this->callApi($server_id, 'item.get', [
				'output' => ['inventory_link', 'itemid', 'name'],
				'hostids' => [$hostid],
				'filter' => [
					'inventory_link' => array_keys($inventory_fields)
				]
			])
			: [];

		$inventory_items = zbx_toHash($inventory_items, 'inventory_link');

		if (!$inventory) {
			$inventory = [];
		}
	}

	private function callApi(string $server_id, string $method, array $params): array {
		$server = $this->getServer($server_id);
		if ($server === null) {
			return [];
		}

		return RemoteApi::call($server['api_url'], $server['api_token'], $method, $params);
	}

	private function getHostDefaultValues(): array {
		return [
			'hostid' => null,
			'name' => '',
			'host' => '',
			'monitored_by' => ZBX_MONITORED_BY_SERVER,
			'proxyid' => '0',
			'proxy_groupid' => '0',
			'assigned_proxyid' => '0',
			'status' => HOST_STATUS_MONITORED,
			'ipmi_authtype' => IPMI_AUTHTYPE_DEFAULT,
			'ipmi_privilege' => IPMI_PRIVILEGE_USER,
			'ipmi_username' => '',
			'ipmi_password' => '',
			'flags' => ZBX_FLAG_DISCOVERY_NORMAL,
			'description' => '',
			'tls_connect' => HOST_ENCRYPTION_NONE,
			'tls_accept' => HOST_ENCRYPTION_NONE,
			'tls_issuer' => '',
			'tls_subject' => '',
			'tls_psk_identity' => '',
			'tls_psk' => '',
			'tags' => [],
			'groups' => [],
			'parentTemplates' => [],
			'discoveryRule' => [],
			'interfaces' => [],
			'macros' => [],
			'inventory' => [],
			'valuemaps' => [],
			'inventory_mode' => CSettingsHelper::get(CSettingsHelper::DEFAULT_INVENTORY_MODE)
		];
	}
}
