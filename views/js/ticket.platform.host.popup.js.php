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
 */

?>
window.host_edit_popup = {
	overlay: null,
	dialogue: null,
	form: null,

	init({popup_url, form_name, host_interfaces, proxy_groupid, host_is_discovered, warnings, server_id, counts}) {
		this.overlay = overlays_stack.getById('host_edit');
		this.dialogue = this.overlay.$dialogue[0];
		this.form = this.overlay.$dialogue.$body[0].querySelector('form');

		const original_url = location.href;
		history.replaceState({}, '', popup_url);

		host_edit.init({form_name, host_interfaces, proxy_groupid, host_is_discovered});

		if (warnings.length) {
			const message_box = warnings.length == 1
				? makeMessageBox('warning', warnings, null, true, false)[0]
				: makeMessageBox('warning', warnings,
						<?= json_encode(_('Cloned host parameter values have been modified.')) ?>, true, false
					)[0];

			this.form.parentNode.insertBefore(message_box, this.form);
		}

		this.ensureServerInput(server_id);
		this.disableTemplatesAndGroups();
		this.disableMonitoredBy();
		this.disableEncryptionTab();
		this.removeInterfaceAddButton();
		this.applyTabCounts(counts);

		this.initial_form_fields = getFormFields(this.form);

		this.dialogue.addEventListener('dialogue.close', () => {
			history.replaceState({}, '', original_url);
		}, {once: true});
	},

	ensureServerInput(server_id) {
		let server_field = this.form.querySelector('input[name="server_id"]');
		if (!server_field) {
			server_field = document.createElement('input');
			server_field.type = 'hidden';
			server_field.name = 'server_id';
			this.form.appendChild(server_field);
		}
		server_field.value = server_id;
	},

	disableTemplatesAndGroups() {
		const $templates = jQuery('#add_templates_');
		if ($templates.length) {
			$templates.multiSelect('disable');
		}

		const $groups = jQuery('#groups_');
		if ($groups.length) {
			$groups.multiSelect('disable');
		}

		jQuery('#linked-templates .btn-link').addClass('disabled').prop('disabled', true);
		jQuery('#linked-templates .btn-link').attr('aria-disabled', 'true');
	},

	disableMonitoredBy() {
		for (const field of this.form.querySelectorAll('[name="monitored_by"]')) {
			field.disabled = true;
		}

		jQuery('#proxyid').multiSelect('disable');
		jQuery('#proxy_groupid').multiSelect('disable');
	},

	disableEncryptionTab() {
		for (const field of this.form.querySelectorAll(
			'[name="tls_connect"], [name^="tls_in_"], #tls_psk_identity, #tls_psk, #tls_issuer, #tls_subject'
		)) {
			field.disabled = true;
		}

		const change_psk = document.getElementById('change_psk');
		if (change_psk) {
			change_psk.disabled = true;
		}
	},

	removeInterfaceAddButton() {
		const button = this.form.querySelector('.add-interface');
		if (button) {
			button.remove();
		}
	},

	applyTabCounts(counts) {
		if (!counts) {
			return;
		}

		const tabs = {
			'host-tags-tab': {value: counts.tags, indicator: 'host-tags'},
			'macros-tab': {value: counts.macros, indicator: 'host-macros'},
			'valuemap-tab': {value: counts.valuemaps, indicator: 'host-valuemaps'}
		};

		document.querySelectorAll('#host-tabs .tabs-nav a').forEach((tab) => {
			const href = tab.getAttribute('href') || '';
			const match = href.split('#')[1];
			if (match && match in tabs && tabs[match].value > 0) {
				tab.setAttribute('data-indicator', 'count');
				tab.setAttribute('data-indicator-value', String(tabs[match].value));
				tab.setAttribute('js-indicator', tabs[match].indicator);
			}
		});
	},

	submit() {
		this.removePopupMessages();

		const fields = host_edit.preprocessFormFields(getFormFields(this.form), false);
		const curl = new Curl(this.form.getAttribute('action'));

		fetch(curl.getUrl(), {
			method: 'POST',
			headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
			body: urlEncodeData(fields)
		})
			.then((response) => response.json())
			.then((response) => {
				if ('error' in response) {
					throw {error: response.error};
				}

				overlayDialogueDestroy(this.overlay.dialogueid);

				if (typeof jQuery !== 'undefined' && typeof jQuery.publish === 'function') {
					jQuery.publish('ticketplatform.host.update', [response, this.overlay]);
				}
			})
			.catch(this.ajaxExceptionHandler)
			.finally(() => {
				this.overlay.unsetLoading();
			});
	},

	removePopupMessages() {
		for (const el of this.form.parentNode.children) {
			if (el.matches('.msg-good, .msg-bad, .msg-warning')) {
				el.parentNode.removeChild(el);
			}
		}
	},

	ajaxExceptionHandler: (exception) => {
		const form = host_edit_popup.form;

		let title, messages;

		if (typeof exception === 'object' && 'error' in exception) {
			title = exception.error.title;
			messages = exception.error.messages;
		}
		else {
			messages = [<?= json_encode(_('Unexpected server error.')) ?>];
		}

		const message_box = makeMessageBox('bad', messages, title)[0];

		form.parentNode.insertBefore(message_box, form);
	}
};
