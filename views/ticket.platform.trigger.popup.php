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
		'header' => _('Trigger'),
		'body' => makeMessageBox(ZBX_STYLE_MSG_BAD, [['message' => $data['error']['title']]], null, true)->toString(),
		'buttons' => []
	]);
	return;
}

$trigger = $data['trigger'];
$hosts = [];
if (array_key_exists('hosts', $trigger)) {
	foreach ($trigger['hosts'] as $host) {
		$hosts[] = $host['name'] !== '' ? $host['name'] : $host['host'];
	}
}

$details = (new CTableInfo())
	->addRow([_n('Host', 'Hosts', count($hosts)), implode(', ', $hosts)])
	->addRow([_('Trigger'), (new CCol($trigger['description']))->addClass(ZBX_STYLE_WORDBREAK)])
	->addRow([_('Severity'), CSeverityHelper::makeSeverityCell((int) $trigger['priority'])])
	->addRow([_('Problem expression'), (new CCol((new CDiv($trigger['expression']))
		->addClass(ZBX_STYLE_WORDBREAK)))])
	->addRow([_('Recovery expression'), (new CCol((new CDiv($trigger['recovery_expression']))
		->addClass(ZBX_STYLE_WORDBREAK)))])
	->addRow([_('Event generation'),
		_('Normal').((TRIGGER_MULT_EVENT_ENABLED == $trigger['type']) ? ' + '._('Multiple PROBLEM events') : '')
	])
	->addRow([_('Allow manual close'), ($trigger['manual_close'] == ZBX_TRIGGER_MANUAL_CLOSE_ALLOWED)
		? (new CCol(_('Yes')))->addClass(ZBX_STYLE_GREEN)
		: (new CCol(_('No')))->addClass(ZBX_STYLE_RED)
	])
	->addRow([_('Enabled'), ($trigger['status'] == TRIGGER_STATUS_ENABLED)
		? (new CCol(_('Yes')))->addClass(ZBX_STYLE_GREEN)
		: (new CCol(_('No')))->addClass(ZBX_STYLE_RED)
	]);

echo json_encode([
	'header' => _('Trigger'),
	'body' => $details->toString(),
	'buttons' => []
]);
