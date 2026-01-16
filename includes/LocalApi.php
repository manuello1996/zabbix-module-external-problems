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

use API;
use Exception;

class LocalApi {
	public static function call(string $method, array $params): array {
		switch ($method) {
			case 'problem.get':
				return API::Problem()->get($params);
			case 'event.get':
				return API::Event()->get($params);
			case 'hostgroup.get':
				return API::HostGroup()->get($params);
			case 'host.get':
				return API::Host()->get($params);
			case 'alert.get':
				return API::Alert()->get($params);
			case 'user.get':
				return API::User()->get($params);
			case 'mediatype.get':
				return API::Mediatype()->get($params);
			case 'trigger.get':
				return API::Trigger()->get($params);
			case 'item.get':
				return API::Item()->get($params);
			default:
				throw new Exception('Unsupported local API method: '.$method);
		}
	}
}
