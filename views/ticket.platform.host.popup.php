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


/**
 * @var CView $this
 * @var array $data
 */

if (array_key_exists('error', $data)) {
	echo json_encode([
		'header' => _('Host'),
		'body' => makeMessageBox(ZBX_STYLE_MSG_BAD, [['message' => $data['error']['title']]], null, true)->toString(),
		'buttons' => []
	]);
	return;
}

$data['form_name'] = 'ticket-platform-host-form';

$buttons = [
	[
		'title' => _('Update'),
		'class' => '',
		'keepOpen' => true,
		'isSubmit' => true,
		'action' => 'host_edit_popup.submit();'
	],
	[
		'title' => _('Close'),
		'class' => 'btn-alt',
		'keepOpen' => true,
		'isSubmit' => false,
		'action' => 'overlayDialogueDestroy("host_edit");'
	]
];

$output = [
	'header' => _('Host'),
	'doc_url' => CDocHelper::getUrl(CDocHelper::DATA_COLLECTION_HOST_EDIT),
	'body' => (new CPartial('configuration.host.edit.html', $data))->getOutput(),
	'script_inline' => getPagePostJs().
		$this->readJsFile('ticket.platform.host.popup.js.php').
		'host_edit_popup.init('.json_encode([
			'popup_url' => (new CUrl('zabbix.php'))
				->setArgument('action', 'ticket.platform.host.popup')
				->setArgument('server_id', $data['server_id'])
				->setArgument('hostid', $data['hostid'])
				->getUrl(),
			'form_name' => $data['form_name'],
			'host_interfaces' => $data['host']['interfaces'],
			'proxy_groupid' => $data['host']['proxy_groupid'],
			'host_is_discovered' => ($data['host']['flags'] == ZBX_FLAG_DISCOVERY_CREATED),
			'warnings' => $data['warnings'],
			'server_id' => $data['server_id'],
			'counts' => $data['counts']
		]).');',
	'buttons' => $buttons
];

if ($data['user']['debug_mode'] == GROUP_DEBUG_MODE_ENABLED) {
	CProfiler::getInstance()->stop();
	$output['debug'] = CProfiler::getInstance()->make()->toString();
}

echo json_encode($output);
