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
use Exception;
use Modules\TicketPlatform\Includes\Config;
use Modules\TicketPlatform\Includes\LocalApi;
use Modules\TicketPlatform\Includes\RemoteApi;

class TicketPlatformTriggerDetails extends CController {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$fields = [
			'server_id' => 'required',
			'triggerid' => 'required'
		];

		$ret = $this->validateInput($fields);

		if (!$ret) {
			$this->setResponse(new CControllerResponseData([
				'error' => [
					'title' => _('Cannot load trigger details'),
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
		$triggerid = (string) $this->getInput('triggerid', '');

		if ($server === null || $triggerid === '') {
			$this->setResponse(new CControllerResponseData([
				'error' => [
					'title' => _('Cannot load trigger details'),
					'messages' => [_('No remote server or trigger specified.')]
				]
			]));
			return;
		}

		try {
			$trigger = $this->getTrigger($server, $triggerid);
			$event_list = $this->getEventList($server, $triggerid);
			$event_list_actions = $this->getActionsSummary($server, $event_list);

			$response = new CControllerResponseData([
				'action' => $this->getAction(),
				'server' => $server,
				'trigger' => $trigger,
				'event_list' => $event_list,
				'event_list_actions' => $event_list_actions
			]);
			$response->setTitle(_('Trigger details'));

			$this->setResponse($response);
		}
		catch (Exception $e) {
			$this->setResponse(new CControllerResponseData([
				'error' => [
					'title' => _('Cannot load trigger details'),
					'messages' => [$e->getMessage()]
				]
			]));
		}
	}

	private function getServer(string $server_id): ?array {
		$config = Config::get();
		$servers = $this->addLocalServer($config['servers']);

		foreach ($servers as $server) {
			if ($server['id'] === $server_id) {
				return $server;
			}
		}

		return null;
	}

	private function getTrigger(array $server, string $triggerid): array {
		$triggers = $this->callApi($server, 'trigger.get', [
			'output' => ['triggerid', 'description', 'expression', 'recovery_expression', 'priority', 'type',
				'manual_close', 'status'
			],
			'selectHosts' => ['hostid', 'name', 'host'],
			'triggerids' => [$triggerid],
			'expandExpression' => true,
			'expandDescription' => true
		]);

		if (!$triggers) {
			throw new Exception(_('No permissions to referred object or it does not exist!'));
		}

		return $triggers[0];
	}

	private function getEventList(array $server, string $triggerid): array {
		$events = $this->callApi($server, 'event.get', [
			'output' => ['eventid', 'objectid', 'acknowledged', 'clock', 'ns', 'severity', 'r_eventid',
				'cause_eventid'
			],
			'selectAcknowledges' => ['userid', 'clock', 'message', 'action', 'old_severity', 'new_severity',
				'suppress_until', 'taskid'
			],
			'source' => EVENT_SOURCE_TRIGGERS,
			'object' => EVENT_OBJECT_TRIGGER,
			'value' => TRIGGER_VALUE_TRUE,
			'objectids' => [$triggerid],
			'sortfield' => ['clock', 'eventid'],
			'sortorder' => ZBX_SORT_DOWN,
			'limit' => 20,
			'preservekeys' => true
		]);

		if (!$events) {
			return [];
		}

		$r_eventids = [];
		foreach ($events as $row) {
			if ($row['r_eventid'] != 0) {
				$r_eventids[$row['r_eventid']] = true;
			}
		}

		$r_events = $r_eventids
			? $this->callApi($server, 'event.get', [
				'output' => ['clock'],
				'source' => EVENT_SOURCE_TRIGGERS,
				'object' => EVENT_OBJECT_TRIGGER,
				'eventids' => array_keys($r_eventids),
				'preservekeys' => true
			])
			: [];

		foreach ($events as &$row) {
			$row['r_clock'] = array_key_exists($row['r_eventid'], $r_events)
				? $r_events[$row['r_eventid']]['clock']
				: 0;
		}
		unset($row);

		return array_values($events);
	}

	private function getActionsSummary(array $server, array $events): array {
		if (!$events) {
			return [];
		}

		$eventids = array_column($events, 'eventid');
		$r_eventids = array_filter(array_map('intval', array_column($events, 'r_eventid')),
			function (int $eventid): bool {
				return $eventid > 0;
			}
		);
		$alert_eventids = array_values(array_unique(array_merge($eventids, $r_eventids)));

		$ack_counts = [];
		foreach ($events as $row) {
			$ack_counts[$row['eventid']] = array_key_exists('acknowledges', $row)
				? count($row['acknowledges'])
				: 0;
		}

		$search_limit = CSettingsHelper::get(CSettingsHelper::SEARCH_LIMIT);
		$alerts = $this->callApi($server, 'alert.get', [
			'output' => ['alertid', 'eventid', 'alerttype', 'status', 'mediatypeid', 'userid'],
			'eventids' => $alert_eventids,
			'limit' => $search_limit
		]);

		$alerts_by_event = [];
		foreach ($alerts as $alert) {
			$alerts_by_event[$alert['eventid']][] = $alert;
		}

		$summary = [];
		foreach ($events as $row) {
			$eventid = $row['eventid'];
			$r_eventid = (int) $row['r_eventid'];
			$event_alerts = $alerts_by_event[$eventid] ?? [];

			if ($r_eventid > 0 && array_key_exists((string) $r_eventid, $alerts_by_event)) {
				$event_alerts = array_merge($event_alerts, $alerts_by_event[(string) $r_eventid]);
			}

			$has_uncomplete = false;
			$has_failed = false;
			foreach ($event_alerts as $alert) {
				if ($alert['status'] == ALERT_STATUS_NEW || $alert['status'] == ALERT_STATUS_NOT_SENT) {
					$has_uncomplete = true;
				}
				elseif ($alert['status'] == ALERT_STATUS_FAILED) {
					$has_failed = true;
				}
			}

			$summary[$eventid] = [
				'count' => ($ack_counts[$eventid] ?? 0) + count($event_alerts),
				'has_uncomplete' => $has_uncomplete,
				'has_failed' => $has_failed
			];
		}

		return $summary;
	}

	private function callApi(array $server, string $method, array $params): array {
		if (!empty($server['is_local'])) {
			return LocalApi::call($method, $params);
		}

		return RemoteApi::call($server['api_url'], $server['api_token'], $method, $params);
	}

	private function addLocalServer(array $servers): array {
		$servers[] = [
			'id' => 'local',
			'name' => _('Local server'),
			'api_url' => '',
			'api_token' => '',
			'hostgroup' => '',
			'include_subgroups' => 1,
			'enabled' => 1,
			'is_local' => true
		];

		return $servers;
	}
}
